package htaccesswatch

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestVhostRootDerivation(t *testing.T) {
	w := &Watcher{root: "/var/www/vhosts"}

	root, domain := w.vhostRoot("/var/www/vhosts/example.test/httpdocs/.htaccess")
	if root != "/var/www/vhosts/example.test" {
		t.Fatalf("unexpected root: %s", root)
	}
	if domain != "example.test" {
		t.Fatalf("unexpected domain: %s", domain)
	}

	root, domain = w.vhostRoot("/var/www/vhosts/example.test/subdomains/blog/httpdocs/private/.htaccess")
	if root != "/var/www/vhosts/example.test/subdomains/blog" {
		t.Fatalf("unexpected nested root: %s", root)
	}
	if domain != "blog" {
		t.Fatalf("unexpected nested domain label: %s", domain)
	}
}

func TestWatchedDirCheck(t *testing.T) {
	w := &Watcher{root: "/var/www/vhosts"}
	if !w.isWatchedDir("/var/www/vhosts/example.test/httpdocs") {
		t.Fatal("httpdocs directory must be watched")
	}
	if w.isWatchedDir("/var/www/vhosts/example.test/logs") {
		t.Fatal("non-httpdocs directory must not be watched")
	}
	if w.isWatchedDir("/var/www/vhosts/example.test/httpdocs-old") {
		t.Fatal("directory name containing httpdocs must not match")
	}
	if !w.isWatchedDir("/var/www/vhosts/example.test") {
		t.Fatal("vhost root must be watched for new httpdocs trees")
	}
}

func TestWatcherEmitsHtaccessEvent(t *testing.T) {
	root := t.TempDir()
	httpdocs := filepath.Join(root, "example.test", "httpdocs")
	if err := os.MkdirAll(httpdocs, 0700); err != nil {
		t.Fatal(err)
	}

	watcher, err := New(root)
	if err != nil {
		t.Fatal(err)
	}
	defer watcher.Close()
	if err := watcher.Rescan(); err != nil {
		t.Fatal(err)
	}

	path := filepath.Join(httpdocs, ".htaccess")
	if err := os.WriteFile(path, []byte("RewriteEngine On\n"), 0600); err != nil {
		t.Fatal(err)
	}

	timeout := time.After(2 * time.Second)
	for {
		select {
		case event := <-watcher.Events():
			if event.Path != path {
				continue
			}
			if event.Domain != "example.test" {
				t.Fatalf("unexpected domain: %s", event.Domain)
			}
			return
		case err := <-watcher.Errors():
			t.Fatalf("watcher error: %v", err)
		case <-timeout:
			t.Fatal("timed out waiting for .htaccess event")
		}
	}
}
