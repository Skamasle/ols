// Package main implements the deprecated Skamasle OLS watcher prototype.
//
// It recursively watches /var/www/vhosts/*/httpdocs/ for .htaccess changes
// and triggers a graceful OpenLiteSpeed reload with a 2-second debounce,
// ensuring that high-frequency writes (e.g. WordPress plugin updates) only
// cause a single reload.
//
// Resource target: < 15MB RAM with 5000+ vhosts via inotify.
package main

import (
	"log"
	"log/syslog"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/fsnotify/fsnotify"
)

const (
	watchRoot    = "/var/www/vhosts"
	httpdocsDir  = "httpdocs"
	htaccessFile = ".htaccess"
	lswsctrl     = "/usr/local/lsws/bin/lswsctrl"
	debounceMs   = 2000 * time.Millisecond
	syslogTag    = "skamasle-ols-watchdog"
)

var (
	logger  *syslog.Writer
	mu      sync.Mutex
	timer   *time.Timer
	reloads int64
)

func main() {
	// Set up syslog
	var err error
	logger, err = syslog.New(syslog.LOG_DAEMON|syslog.LOG_INFO, syslogTag)
	if err != nil {
		log.Fatalf("Cannot open syslog: %v", err)
	}
	defer logger.Close()

	logInfo("Starting Skamasle OLS .htaccess watchdog")

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		logFatal("Cannot create fsnotify watcher: " + err.Error())
	}
	defer watcher.Close()

	// Walk vhosts and add httpdocs directories to watcher
	if err := walkAndWatch(watcher); err != nil {
		logFatal("Initial vhost walk failed: " + err.Error())
	}

	// Handle OS signals for graceful shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)

	logInfo("Watchdog active. Monitoring .htaccess changes...")

	for {
		select {
		case event, ok := <-watcher.Events:
			if !ok {
				logInfo("Watcher channel closed, exiting")
				return
			}
			handleEvent(watcher, event)

		case err, ok := <-watcher.Errors:
			if !ok {
				return
			}
			logWarn("Watcher error: " + err.Error())

		case sig := <-sigCh:
			switch sig {
			case syscall.SIGHUP:
				// Re-scan vhosts on SIGHUP (e.g. new domain added)
				logInfo("SIGHUP received — re-scanning vhosts")
				if err := walkAndWatch(watcher); err != nil {
					logWarn("Re-scan failed: " + err.Error())
				}
			default:
				logInfo("Signal received, shutting down watchdog")
				return
			}
		}
	}
}

// walkAndWatch recursively finds all httpdocs directories under watchRoot
// and adds them (and their subdirectories) to the fsnotify watcher.
func walkAndWatch(watcher *fsnotify.Watcher) error {
	added := 0
	err := filepath.Walk(watchRoot, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			// Skip unreadable paths (e.g. permission denied on vhost subdir)
			return nil
		}
		if !info.IsDir() {
			return nil
		}

		// Only watch directories that are inside an httpdocs tree
		if strings.HasSuffix(path, "/"+httpdocsDir) || isUnderHTTPDocs(path) {
			if watchErr := watcher.Add(path); watchErr != nil {
				logWarn("Cannot watch " + path + ": " + watchErr.Error())
			} else {
				added++
			}
		}
		return nil
	})
	logInfo("Watching " + itoa(added) + " directories under " + watchRoot)
	return err
}

// isUnderHTTPDocs returns true if path is a descendant of an httpdocs directory.
func isUnderHTTPDocs(path string) bool {
	parts := strings.Split(path, string(os.PathSeparator))
	for _, p := range parts {
		if p == httpdocsDir {
			return true
		}
	}
	return false
}

// handleEvent processes a single fsnotify event.
// It only acts on .htaccess files and uses a debounced reload.
func handleEvent(watcher *fsnotify.Watcher, event fsnotify.Event) {
	name := filepath.Base(event.Name)

	// Watch new subdirectories as they are created
	if event.Has(fsnotify.Create) {
		info, err := os.Stat(event.Name)
		if err == nil && info.IsDir() && isUnderHTTPDocs(event.Name) {
			if err := watcher.Add(event.Name); err != nil {
				logWarn("Cannot watch new dir " + event.Name + ": " + err.Error())
			} else {
				logInfo("Now watching new directory: " + event.Name)
			}
		}
	}

	// Filter: only care about .htaccess files
	if name != htaccessFile {
		return
	}

	if event.Has(fsnotify.Write) || event.Has(fsnotify.Create) || event.Has(fsnotify.Remove) {
		logInfo("Detected .htaccess change: " + event.Name + " [" + event.Op.String() + "]")
		scheduleReload()
	}
}

// scheduleReload resets the debounce timer. The OLS reload is only triggered
// after debounceMs of inactivity, collapsing rapid bursts into a single reload.
func scheduleReload() {
	mu.Lock()
	defer mu.Unlock()

	if timer != nil {
		timer.Reset(debounceMs)
		return
	}

	timer = time.AfterFunc(debounceMs, func() {
		mu.Lock()
		timer = nil
		mu.Unlock()
		triggerReload()
	})
}

// triggerReload executes lswsctrl reload and logs the result.
func triggerReload() {
	reloads++
	logInfo("Triggering OLS graceful reload (total reloads: " + itoa64(reloads) + ")")

	cmd := exec.Command(lswsctrl, "reload")
	out, err := cmd.CombinedOutput()
	if err != nil {
		logWarn("lswsctrl reload failed: " + err.Error() + " — output: " + strings.TrimSpace(string(out)))
	} else {
		logInfo("OLS reload successful")
	}
}

// ---------------------------------------------------------------------------
// Logging helpers
// ---------------------------------------------------------------------------

func logInfo(msg string)  { _ = logger.Info(msg) }
func logWarn(msg string)  { _ = logger.Warning(msg) }
func logFatal(msg string) { _ = logger.Err(msg); os.Exit(1) }

func itoa(n int) string { return strconv.Itoa(n) }
func itoa64(n int64) string {
	if n == 0 {
		return "0"
	}
	buf := make([]byte, 0, 20)
	neg := n < 0
	if neg {
		n = -n
	}
	for n > 0 {
		buf = append([]byte{byte('0' + n%10)}, buf...)
		n /= 10
	}
	if neg {
		buf = append([]byte{'-'}, buf...)
	}
	return string(buf)
}
