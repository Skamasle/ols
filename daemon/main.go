package main

import (
	"log"
	"log/syslog"
	"os"
	"os/signal"
	"sync/atomic"
	"syscall"
	"time"

	"skamasle-ols-agent/internal/eventqueue"
	"skamasle-ols-agent/internal/htaccessscan"
	"skamasle-ols-agent/internal/htaccesswatch"
	"skamasle-ols-agent/internal/ols"
	"skamasle-ols-agent/internal/reconcile"
	"skamasle-ols-agent/internal/state"
)

const (
	watchRoot = "/var/www/vhosts"
	debounce  = 2 * time.Second
	syslogTag = "skamasle-ols-agent"
)

var reloads int64

func main() {
	logger, err := syslog.New(syslog.LOG_DAEMON|syslog.LOG_INFO, syslogTag)
	if err != nil {
		log.Fatalf("Cannot open syslog: %v", err)
	}
	defer logger.Close()

	info := func(msg string) { _ = logger.Info(msg) }
	warn := func(msg string) { _ = logger.Warning(msg) }
	fatal := func(msg string) {
		_ = logger.Err(msg)
		os.Exit(1)
	}

	info("Starting Skamasle OLS .htaccess watcher")

	watcher, err := htaccesswatch.New(watchRoot)
	if err != nil {
		fatal("Cannot create watcher: " + err.Error())
	}
	defer watcher.Close()

	if err := watcher.Rescan(); err != nil {
		fatal("Initial vhost scan failed: " + err.Error())
	}

	scheduler := eventqueue.New(debounce)
	defer scheduler.Close()

	stateStore := state.New("/usr/local/psa/var/modules/skamasle-ols/desired-state.json")
	reconciler := reconcile.New(stateStore, htaccessscan.New())
	olsManager := ols.New(nil)

	go func() {
		for event := range watcher.Events() {
			info("Detected .htaccess change: " + event.Path + " [" + event.Op.String() + "]")
			scheduler.Submit(eventqueue.Event{
				Key:    event.Key,
				Path:   event.Path,
				Reason: "htaccess-change",
				Op:     event.Op.String(),
				When:   event.When,
				Root:   event.VhostRoot,
				Domain: event.Domain,
			})
		}
	}()

	go func() {
		for err := range watcher.Errors() {
			warn("Watcher error: " + err.Error())
		}
	}()

	go func() {
		for event := range scheduler.Events() {
			decision, err := reconciler.Decide(event)
			if err != nil {
				warn("Reconcile failed for " + event.Key + ": " + err.Error())
				continue
			}

			switch decision.Action {
			case reconcile.ActionReload:
				if err := olsManager.Validate(); err != nil {
					warn("Skipping reload for " + decision.DomainName + ": " + err.Error())
					continue
				}
				count := atomic.AddInt64(&reloads, 1)
				info("Reload requested for " + decision.DomainName + " after " + event.Reason + " (reloads: " + itoa64(count) + ")")
				if err := olsManager.Reload(); err != nil {
					warn("OLS reload failed for " + event.Key + ": " + err.Error())
				}
			case reconcile.ActionNoop:
				info("No reconcile needed for " + event.Key + ": " + decision.Reason)
			case reconcile.ActionReview:
				warn("Holding reload for " + decision.DomainName + ": .htaccess requires review (" + decision.Reason + ")")
			case reconcile.ActionBlocked:
				warn("Blocking reload for " + decision.DomainName + ": " + decision.Reason)
			case reconcile.ActionMissing:
				warn("Skipping reconcile for " + event.Key + ": " + decision.Reason)
			default:
				warn("Unknown reconcile action for " + event.Key + ": " + string(decision.Action))
			}
		}
	}()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)

	info("Watcher active. Monitoring .htaccess changes...")

	for {
		switch sig := <-sigCh; sig {
		case syscall.SIGHUP:
			info("SIGHUP received, rescanning vhosts")
			if err := watcher.Rescan(); err != nil {
				warn("Rescan failed: " + err.Error())
			}
		default:
			info("Shutdown signal received")
			return
		}
	}
}

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
