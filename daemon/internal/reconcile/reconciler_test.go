package reconcile

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"skamasle-ols-agent/internal/eventqueue"
	"skamasle-ols-agent/internal/htaccessscan"
	"skamasle-ols-agent/internal/state"
)

type fakeScanner struct {
	result htaccessscan.Result
}

func (s fakeScanner) Scan(documentRoot string) htaccessscan.Result {
	return s.result
}

func TestDecideReloadsForOlsDomain(t *testing.T) {
	dir := t.TempDir()
	statePath := filepath.Join(dir, "desired-state.json")
	writeState(t, statePath, `{
  "schemaVersion": 1,
  "generation": 3,
  "server": {
    "defaultRouting": "native",
    "listener": {
      "bindAddress": "127.0.0.1",
      "port": 7088,
      "protocol": "http"
    }
  },
  "domains": [
    {
      "guid": "{123e4567-e89b-42d3-a456-426614174000}",
      "pleskId": 12,
      "name": "example.test",
      "aliases": ["www.example.test"],
      "documentRoot": "/var/www/vhosts/example.test/httpdocs",
      "vhostRoot": "/var/www/vhosts/example.test",
      "systemUser": "example",
      "systemGroup": "psacln",
      "nativeProfile": {
        "webMode": "proxy",
        "proxyMode": true,
        "phpHandlerId": "plesk-php83-fpm"
      },
      "php": {
        "pleskHandlerId": "plesk-php83-fpm",
        "version": "8.3",
        "lsphpBinary": "/opt/plesk/php/8.3/bin/lsphp",
        "socket": "/tmp/example.sock"
      },
      "requestedRouting": "ols",
      "appliedRouting": "ols"
    }
  ]
}`)

	r := New(state.New(statePath), fakeScanner{
		result: htaccessscan.Result{
			Status:       "compatible",
			FilesScanned: 1,
		},
	})
	decision, err := r.Decide(eventqueue.Event{
		Key:  "/var/www/vhosts/example.test",
		Path: "/var/www/vhosts/example.test/httpdocs/.htaccess",
		When: time.Now(),
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if decision.Action != ActionReload {
		t.Fatalf("expected reload, got %s", decision.Action)
	}
	if decision.DomainName != "example.test" {
		t.Fatalf("unexpected domain: %s", decision.DomainName)
	}
}

func TestDecideNoopForNativeDomain(t *testing.T) {
	dir := t.TempDir()
	statePath := filepath.Join(dir, "desired-state.json")
	writeState(t, statePath, `{
  "schemaVersion": 1,
  "generation": 1,
  "server": {
    "defaultRouting": "native",
    "listener": {
      "bindAddress": "127.0.0.1",
      "port": 7088,
      "protocol": "http"
    }
  },
  "domains": [
    {
      "guid": "{123e4567-e89b-42d3-a456-426614174000}",
      "pleskId": 12,
      "name": "example.test",
      "aliases": [],
      "documentRoot": "/var/www/vhosts/example.test/httpdocs",
      "vhostRoot": "/var/www/vhosts/example.test",
      "systemUser": "example",
      "systemGroup": "psacln",
      "nativeProfile": {
        "webMode": "proxy",
        "proxyMode": true,
        "phpHandlerId": "plesk-php83-fpm"
      },
      "php": {
        "pleskHandlerId": "plesk-php83-fpm",
        "version": "8.3",
        "lsphpBinary": "/opt/plesk/php/8.3/bin/lsphp",
        "socket": "/tmp/example.sock"
      },
      "requestedRouting": "native",
      "appliedRouting": "native"
    }
  ]
}`)

	r := New(state.New(statePath), fakeScanner{
		result: htaccessscan.Result{Status: "compatible"},
	})
	decision, err := r.Decide(eventqueue.Event{
		Key:  "/var/www/vhosts/example.test",
		Path: "/var/www/vhosts/example.test/httpdocs/.htaccess",
		When: time.Now(),
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if decision.Action != ActionNoop {
		t.Fatalf("expected noop, got %s", decision.Action)
	}
}

func TestDecideReloadsEvenWithReviewFindings(t *testing.T) {
	dir := t.TempDir()
	statePath := filepath.Join(dir, "desired-state.json")
	writeState(t, statePath, olsState)

	r := New(state.New(statePath), fakeScanner{
		result: htaccessscan.Result{
			Status:       "review",
			FilesScanned: 2,
			Findings: []htaccessscan.Finding{
				{Directive: "header", Classification: "unsupported-behavior"},
			},
		},
	})
	decision, err := r.Decide(eventqueue.Event{
		Path: "/var/www/vhosts/example.test/httpdocs/.htaccess",
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if decision.Action != ActionReload {
		t.Fatalf("expected reload, got %s", decision.Action)
	}
	if decision.FindingCount != 1 {
		t.Fatalf("expected one finding, got %d", decision.FindingCount)
	}
}

const olsState = `{
  "schemaVersion": 1,
  "generation": 3,
  "server": {
    "defaultRouting": "native",
    "listener": {
      "bindAddress": "127.0.0.1",
      "port": 7088,
      "protocol": "http"
    }
  },
  "domains": [
    {
      "guid": "{123e4567-e89b-42d3-a456-426614174000}",
      "pleskId": 12,
      "name": "example.test",
      "aliases": [],
      "documentRoot": "/var/www/vhosts/example.test/httpdocs",
      "vhostRoot": "/var/www/vhosts/example.test",
      "systemUser": "example",
      "systemGroup": "psacln",
      "nativeProfile": {
        "webMode": "proxy",
        "proxyMode": true,
        "phpHandlerId": "plesk-php83-fpm"
      },
      "php": {
        "pleskHandlerId": "plesk-php83-fpm",
        "version": "8.3",
        "lsphpBinary": "/opt/plesk/php/8.3/bin/lsphp",
        "socket": "/tmp/example.sock"
      },
      "requestedRouting": "ols",
      "appliedRouting": "ols"
    }
  ]
}`

func writeState(t *testing.T, path, content string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatalf("write state: %v", err)
	}
}
