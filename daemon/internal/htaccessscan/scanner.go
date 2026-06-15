package htaccessscan

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

const (
	maxDirectories = 5000
	maxFiles       = 128
	maxFileBytes   = 1048576
	maxTotalBytes  = 4194304
	maxDepth       = 24
)

type Finding struct {
	File           string
	Line           int
	Directive      string
	Classification string
	Message        string
}

type Result struct {
	Status       string
	FilesScanned int
	Findings     []Finding
}

type Scanner struct{}

func New() *Scanner {
	return &Scanner{}
}

func (s *Scanner) Scan(documentRoot string) Result {
	root, err := filepath.EvalSymlinks(documentRoot)
	if err != nil {
		return blocked(".", "document-root", "Document root is unavailable or unreadable.")
	}
	info, err := os.Stat(root)
	if err != nil || !info.IsDir() {
		return blocked(".", "document-root", "Document root is unavailable or unreadable.")
	}

	result := Result{Status: "compatible"}
	directories := 0
	totalBytes := int64(0)

	err = filepath.Walk(root, func(path string, info os.FileInfo, walkErr error) error {
		if walkErr != nil {
			result.Findings = append(result.Findings, finding(
				relativePath(root, path),
				0,
				"directory",
				"scan-error",
				"Directory cannot be read.",
			))
			return nil
		}
		if info == nil {
			return nil
		}
		if info.Mode()&os.ModeSymlink != 0 {
			if info.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if info.IsDir() {
			directories++
			if directories > maxDirectories {
				result.Findings = append(result.Findings, limitFinding(
					root,
					path,
					"Directory scan limit exceeded.",
				))
				return filepath.SkipAll
			}
			if depth(root, path) > maxDepth {
				result.Findings = append(result.Findings, limitFinding(
					root,
					path,
					"Directory depth limit exceeded.",
				))
				return filepath.SkipDir
			}
			return nil
		}
		if info.Name() != ".htaccess" {
			return nil
		}

		result.FilesScanned++
		if result.FilesScanned > maxFiles {
			result.Findings = append(result.Findings, limitFinding(
				root,
				path,
				".htaccess file limit exceeded.",
			))
			return filepath.SkipAll
		}
		if info.Size() > maxFileBytes {
			result.Findings = append(result.Findings, limitFinding(
				root,
				path,
				".htaccess file is unreadable or too large.",
			))
			return nil
		}
		totalBytes += info.Size()
		if totalBytes > maxTotalBytes {
			result.Findings = append(result.Findings, limitFinding(
				root,
				path,
				"Total .htaccess scan size exceeded.",
			))
			return filepath.SkipAll
		}

		content, err := os.ReadFile(path)
		if err != nil {
			result.Findings = append(result.Findings, finding(
				relativePath(root, path),
				0,
				"file",
				"scan-error",
				".htaccess file cannot be read.",
			))
			return nil
		}
		result.Findings = append(
			result.Findings,
			s.Analyze(string(content), relativePath(root, path))...,
		)
		return nil
	})
	if err != nil {
		result.Findings = append(result.Findings, finding(
			".",
			0,
			"scan",
			"scan-error",
			err.Error(),
		))
	}

	result.Status = status(result.Findings)
	return result
}

func (s *Scanner) Analyze(content, relativeFile string) []Finding {
	lines := logicalLines(content)
	findings := make([]Finding, 0)
	for _, logical := range lines {
		line := strings.TrimSpace(logical.content)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		directive := ""
		if strings.HasPrefix(line, "<") {
			directive = blockDirective(line)
		} else {
			fields := strings.Fields(line)
			if len(fields) > 0 {
				directive = strings.ToLower(fields[0])
			}
		}

		classification := classify(directive)
		if classification == "supported" || classification == "ignored-safe" {
			continue
		}
		findings = append(findings, finding(
			relativeFile,
			logical.line,
			directive,
			classification,
			classificationMessage(classification),
		))
	}
	return findings
}

type logicalLine struct {
	line    int
	content string
}

func logicalLines(content string) []logicalLine {
	normalized := strings.ReplaceAll(content, "\r\n", "\n")
	normalized = strings.ReplaceAll(normalized, "\r", "\n")
	physicalLines := strings.Split(normalized, "\n")
	lines := make([]logicalLine, 0)
	buffer := ""
	startLine := 1

	for index, physical := range physicalLines {
		lineNumber := index + 1
		if buffer == "" {
			startLine = lineNumber
		}
		trimmed := strings.TrimRight(physical, " \t\r")
		continued := strings.HasSuffix(trimmed, "\\")
		if continued {
			trimmed = strings.TrimSuffix(trimmed, "\\")
		}
		if buffer != "" {
			buffer += " "
		}
		buffer += trimmed
		if !continued {
			lines = append(lines, logicalLine{line: startLine, content: buffer})
			buffer = ""
		}
	}
	if buffer != "" {
		lines = append(lines, logicalLine{line: startLine, content: buffer})
	}
	return lines
}

func classify(directive string) string {
	if contains([]string{
		"rewritebase",
		"rewritecond",
		"rewriteengine",
		"rewriterule",
	}, directive) {
		return "supported"
	}
	if contains([]string{"ifmodule", "ifdefine", "ifversion"}, directive) {
		return "ignored-safe"
	}
	if contains([]string{
		"files",
		"filesmatch",
		"limit",
		"limitexcept",
		"allow",
		"authbasicprovider",
		"authgroupfile",
		"authname",
		"authtype",
		"authuserfile",
		"deny",
		"order",
		"require",
		"satisfy",
	}, directive) {
		return "unsupported-security"
	}
	if contains([]string{
		"addhandler",
		"addtype",
		"directoryindex",
		"errordocument",
		"expiresactive",
		"expiresbytype",
		"header",
		"options",
		"php_flag",
		"php_value",
		"redirect",
		"redirectmatch",
		"removehandler",
		"requestheader",
		"setenv",
		"setenvif",
		"setenvifnocase",
		"sethandler",
	}, directive) {
		return "unsupported-behavior"
	}
	return "unknown"
}

func blockDirective(line string) string {
	trimmed := strings.TrimSpace(strings.TrimPrefix(line, "<"))
	trimmed = strings.TrimSpace(strings.TrimPrefix(trimmed, "/"))
	fields := strings.Fields(trimmed)
	if len(fields) == 0 {
		return "unknown-block"
	}
	return strings.ToLower(strings.TrimRight(fields[0], ">"))
}

func classificationMessage(classification string) string {
	switch classification {
	case "unsupported-security":
		return "Apache security directive requires explicit translation."
	case "unsupported-behavior":
		return "Apache behavior directive is not yet translated."
	case "scan-error":
		return "Compatibility analysis could not be completed safely."
	default:
		return "Unknown directive requires administrator review."
	}
}

func status(findings []Finding) string {
	if len(findings) == 0 {
		return "compatible"
	}
	for _, item := range findings {
		if item.Classification == "scan-error" {
			return "blocked"
		}
	}
	return "review"
}

func finding(file string, line int, directive, classification, message string) Finding {
	return Finding{
		File:           file,
		Line:           line,
		Directive:      directive,
		Classification: classification,
		Message:        message,
	}
}

func blocked(file, directive, message string) Result {
	return Result{
		Status: "blocked",
		Findings: []Finding{
			finding(file, 0, directive, "scan-error", message),
		},
	}
}

func limitFinding(root, path, message string) Finding {
	return finding(
		relativePath(root, path),
		0,
		"scan-limit",
		"scan-error",
		message,
	)
}

func relativePath(root, path string) string {
	relative, err := filepath.Rel(root, path)
	if err != nil || strings.HasPrefix(relative, "..") {
		return "."
	}
	if relative == "." {
		return "."
	}
	return filepath.ToSlash(relative)
}

func depth(root, path string) int {
	relative, err := filepath.Rel(root, path)
	if err != nil || relative == "." {
		return 0
	}
	return len(strings.Split(filepath.Clean(relative), string(os.PathSeparator)))
}

func contains(values []string, value string) bool {
	for _, candidate := range values {
		if candidate == value {
			return true
		}
	}
	return false
}

func (r Result) Summary() string {
	return fmt.Sprintf("%s: %d files, %d findings", r.Status, r.FilesScanned, len(r.Findings))
}
