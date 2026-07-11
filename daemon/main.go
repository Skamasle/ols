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
	watchRoot              = "/var/www/vhosts"
	debounce               = 2 * time.Second
	syslogTag              = "skamasle-ols-agent"
	reloadPolicyEnv        = "SKAMASLE_OLS_RELOAD_POLICY"
	reloadPolicyPermissive = "permissive"
	reloadPolicyStrict     = "strict"
	stateSyncInterval      = 30 * time.Second
)

var reloads int64

type olsController interface {
	Validate() error
	Reload() error
}

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
	stateStore := state.New("/usr/local/psa/var/modules/skamasle-ols/desired-state.json")

	watcher, err := htaccesswatch.New(watchRoot)
	if err != nil {
		fatal("Cannot create watcher: " + err.Error())
	}
	defer watcher.Close()

	if err := syncWatchRoots(watcher, stateStore); err != nil {
		warn("Initial OLS domain watch sync failed; periodic retry enabled: " + err.Error())
	}

	scheduler := eventqueue.New(debounce)
	defer scheduler.Close()

	reconciler := reconcile.New(stateStore, htaccessscan.New())
	olsManager := ols.New(nil)
	reloadPolicy, policyWarning := reloadPolicyFromEnvironment()
	if policyWarning != "" {
		warn(policyWarning)
	}
	info("Reload policy: " + reloadPolicy)

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

			executeDecision(decision, event, olsManager, reloadPolicy, info, warn)
		}
	}()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)
	stateTicker := time.NewTicker(stateSyncInterval)
	defer stateTicker.Stop()

	info("Watcher active. Monitoring .htaccess changes...")

	for {
		select {
		case sig := <-sigCh:
			switch sig {
			case syscall.SIGHUP:
				info("SIGHUP received, synchronizing OLS domain watches")
				if err := syncWatchRoots(watcher, stateStore); err != nil {
					warn("OLS domain watch sync failed: " + err.Error())
				}
			default:
				info("Shutdown signal received")
				return
			}
		case <-stateTicker.C:
			if err := syncWatchRoots(watcher, stateStore); err != nil {
				warn("Periodic OLS domain watch sync failed: " + err.Error())
			}
		}
	}
}

func syncWatchRoots(watcher *htaccesswatch.Watcher, store *state.Store) error {
	value, err := store.Load()
	if err != nil {
		return err
	}
	return watcher.SyncRoots(state.AppliedOLSDocumentRoots(value))
}

func executeDecision(
	decision reconcile.Decision,
	event eventqueue.Event,
	manager olsController,
	reloadPolicy string,
	info func(string),
	warn func(string),
) {
	switch decision.Action {
	case reconcile.ActionReload:
		reloadOLS(decision, event, manager, info, warn)
	case reconcile.ActionNoop:
		info("No reconcile needed for " + event.Key + ": " + decision.Reason)
	case reconcile.ActionReview:
		warn("Manual review recommended for " + decision.DomainName + ": " + decision.Reason)
		if reloadPolicy == reloadPolicyPermissive {
			reloadOLS(decision, event, manager, info, warn)
		}
	case reconcile.ActionBlocked:
		warn("Compatibility scan blocked for " + decision.DomainName + ": " + decision.Reason)
		if reloadPolicy == reloadPolicyPermissive {
			reloadOLS(decision, event, manager, info, warn)
		}
	case reconcile.ActionMissing:
		warn("Skipping reconcile for " + event.Key + ": " + decision.Reason)
	default:
		warn("Unknown reconcile action for " + event.Key + ": " + string(decision.Action))
	}
}

func reloadOLS(
	decision reconcile.Decision,
	event eventqueue.Event,
	manager olsController,
	info func(string),
	warn func(string),
) {
	if err := manager.Validate(); err != nil {
		warn("Skipping reload for " + decision.DomainName + ": " + err.Error())
		return
	}
	if err := manager.Reload(); err != nil {
		warn("OLS reload failed for " + event.Key + ": " + err.Error())
		return
	}
	count := atomic.AddInt64(&reloads, 1)
	info("Reload completed for " + decision.DomainName + " after " + event.Reason + " (reloads: " + itoa64(count) + ")")
}

func reloadPolicyFromEnvironment() (string, string) {
	value := os.Getenv(reloadPolicyEnv)
	switch value {
	case "", reloadPolicyPermissive:
		return reloadPolicyPermissive, ""
	case reloadPolicyStrict:
		return reloadPolicyStrict, ""
	default:
		return reloadPolicyPermissive,
			"Invalid " + reloadPolicyEnv + " value " + value + "; using permissive"
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
