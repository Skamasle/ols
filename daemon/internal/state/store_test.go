package state

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadValidState(t *testing.T) {
	path := writeState(t, validState)
	value, err := New(path).Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if value.Generation != 2 || len(value.Domains) != 1 {
		t.Fatalf("unexpected state: %+v", value)
	}
}

func TestLoadRejectsUnknownFields(t *testing.T) {
	path := writeState(t, `{
  "schemaVersion": 1,
  "generation": 0,
  "server": {
    "defaultRouting": "native",
    "listener": {
      "bindAddress": "127.0.0.1",
      "port": 7088,
      "protocol": "http"
    },
    "unknown": true
  },
  "domains": []
}`)
	if _, err := New(path).Load(); err == nil {
		t.Fatal("unknown state fields must be rejected")
	}
}

func TestFindDomainUsesMostSpecificRoot(t *testing.T) {
	path := writeState(t, validState)
	value, err := New(path).Load()
	if err != nil {
		t.Fatal(err)
	}
	domain, root, ok := New(path).FindDomainForPath(
		value,
		"/var/www/vhosts/example.test/httpdocs/private/.htaccess",
	)
	if !ok || domain.Name != "example.test" {
		t.Fatalf("domain not resolved: %+v", domain)
	}
	if root != "/var/www/vhosts/example.test/httpdocs" {
		t.Fatalf("unexpected root: %s", root)
	}
}

func writeState(t *testing.T, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "desired-state.json")
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}
	return path
}

const validState = `{
  "schemaVersion": 1,
  "generation": 2,
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
