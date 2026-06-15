package htaccessscan

import (
	"os"
	"path/filepath"
	"testing"
)

func TestAnalyzeWordPressRules(t *testing.T) {
	scanner := New()
	findings := scanner.Analyze(`# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
`, ".htaccess")
	if len(findings) != 0 {
		t.Fatalf("expected compatible rules, got %+v", findings)
	}
}

func TestAnalyzeUnsupportedDirectives(t *testing.T) {
	scanner := New()
	findings := scanner.Analyze(`AuthType Basic
Require valid-user
Header set X-Frame-Options DENY
UnknownDirective value
`, "private/.htaccess")
	if len(findings) != 4 {
		t.Fatalf("expected 4 findings, got %d", len(findings))
	}
	if findings[0].Classification != "unsupported-security" {
		t.Fatalf("unexpected first classification: %s", findings[0].Classification)
	}
	if findings[2].Classification != "unsupported-behavior" {
		t.Fatalf("unexpected third classification: %s", findings[2].Classification)
	}
	if findings[3].Classification != "unknown" {
		t.Fatalf("unexpected fourth classification: %s", findings[3].Classification)
	}
}

func TestScanDocumentRoot(t *testing.T) {
	root := t.TempDir()
	public := filepath.Join(root, "public")
	if err := os.Mkdir(public, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, ".htaccess"), []byte("RewriteEngine On\n"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(public, ".htaccess"), []byte("Options -Indexes\n"), 0600); err != nil {
		t.Fatal(err)
	}

	result := New().Scan(root)
	if result.Status != "review" {
		t.Fatalf("expected review, got %s", result.Status)
	}
	if result.FilesScanned != 2 {
		t.Fatalf("expected 2 files, got %d", result.FilesScanned)
	}
	if len(result.Findings) != 1 || result.Findings[0].File != "public/.htaccess" {
		t.Fatalf("unexpected findings: %+v", result.Findings)
	}
}

func TestScanMissingDocumentRoot(t *testing.T) {
	result := New().Scan(filepath.Join(t.TempDir(), "missing"))
	if result.Status != "blocked" {
		t.Fatalf("expected blocked, got %s", result.Status)
	}
}
