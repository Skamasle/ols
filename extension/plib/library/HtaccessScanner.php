<?php

class Modules_SkamasleOls_HtaccessScanner
{
    const MAX_DIRECTORIES = 5000;
    const MAX_FILES = 128;
    const MAX_FILE_BYTES = 1048576;
    const MAX_TOTAL_BYTES = 4194304;
    const MAX_DEPTH = 24;

    private $supported = array(
        'rewritebase',
        'rewritecond',
        'rewriteengine',
        'rewriterule',
    );

    private $unsupportedSecurity = array(
        'allow',
        'authbasicprovider',
        'authgroupfile',
        'authname',
        'authtype',
        'authuserfile',
        'deny',
        'order',
        'require',
        'satisfy',
    );

    private $unsupportedBehavior = array(
        'addhandler',
        'addtype',
        'directoryindex',
        'errordocument',
        'expiresactive',
        'expiresbytype',
        'header',
        'options',
        'php_flag',
        'php_value',
        'redirect',
        'redirectmatch',
        'removehandler',
        'requestheader',
        'setenv',
        'setenvif',
        'setenvifnocase',
        'sethandler',
    );

    public function scan($documentRoot, $maxDepth = null)
    {
        $root = realpath($documentRoot);
        if (false === $root || !is_dir($root) || !is_readable($root)) {
            return $this->result(
                'blocked',
                array($this->finding(
                    '.',
                    0,
                    'document-root',
                    'scan-error',
                    'Document root is unavailable or unreadable.'
                )),
                0
            );
        }

        $depthLimit = self::MAX_DEPTH;
        if (null !== $maxDepth) {
            $depthLimit = max(0, min(self::MAX_DEPTH, (int) $maxDepth));
        }

        $queue = array(array($root, 0));
        $directories = 0;
        $files = 0;
        $totalBytes = 0;
        $findings = array();

        while (!empty($queue)) {
            list($directory, $depth) = array_shift($queue);
            $directories++;
            if ($directories > self::MAX_DIRECTORIES) {
                $findings[] = $this->limitFinding(
                    $root,
                    $directory,
                    'Directory scan limit exceeded.'
                );
                break;
            }

            $entries = @scandir($directory);
            if (false === $entries) {
                $findings[] = $this->finding(
                    $this->relativePath($root, $directory),
                    0,
                    'directory',
                    'scan-error',
                    'Directory cannot be read.'
                );
                continue;
            }

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if ($depth >= $depthLimit) {
                        $findings[] = $this->limitFinding(
                            $root,
                            $path,
                            'Directory depth limit exceeded.'
                        );
                        continue;
                    }
                    $queue[] = array($path, $depth + 1);
                    continue;
                }
                if ('.htaccess' !== $entry || !is_file($path)) {
                    continue;
                }

                $files++;
                if ($files > self::MAX_FILES) {
                    $findings[] = $this->limitFinding(
                        $root,
                        $path,
                        '.htaccess file limit exceeded.'
                    );
                    break 2;
                }

                $size = @filesize($path);
                if (false === $size || $size > self::MAX_FILE_BYTES) {
                    $findings[] = $this->limitFinding(
                        $root,
                        $path,
                        '.htaccess file is unreadable or too large.'
                    );
                    continue;
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    $findings[] = $this->limitFinding(
                        $root,
                        $path,
                        'Total .htaccess scan size exceeded.'
                    );
                    break 2;
                }

                $content = @file_get_contents($path);
                if (false === $content) {
                    $findings[] = $this->finding(
                        $this->relativePath($root, $path),
                        0,
                        'file',
                        'scan-error',
                        '.htaccess file cannot be read.'
                    );
                    continue;
                }
                $findings = array_merge(
                    $findings,
                    $this->analyze(
                        $content,
                        $this->relativePath($root, $path)
                    )
                );
            }
        }

        $status = $this->resultStatus($findings);
        return $this->result($status, $findings, $files);
    }

    public function analyze($content, $relativePath = '.htaccess')
    {
        $logicalLines = $this->logicalLines((string) $content);
        $findings = array();

        foreach ($logicalLines as $logicalLine) {
            $line = trim($logicalLine['content']);
            if ('' === $line || '#' === substr($line, 0, 1)) {
                continue;
            }

            if ('<' === substr($line, 0, 1)) {
                $directive = $this->blockDirective($line);
            } else {
                $parts = preg_split('/\s+/', $line, 2);
                $directive = strtolower($parts[0]);
            }

            $classification = $this->classify($directive);
            if ('supported' === $classification
                || 'ignored-safe' === $classification
            ) {
                continue;
            }

            $findings[] = $this->finding(
                $relativePath,
                $logicalLine['line'],
                $directive,
                $classification,
                $this->classificationMessage($classification)
            );
        }

        return $findings;
    }

    private function logicalLines($content)
    {
        $physicalLines = preg_split('/\R/', $content);
        $logicalLines = array();
        $buffer = '';
        $startLine = 1;

        foreach ($physicalLines as $index => $physicalLine) {
            $lineNumber = $index + 1;
            if ('' === $buffer) {
                $startLine = $lineNumber;
            }
            $trimmedRight = rtrim($physicalLine);
            $continued = '' !== $trimmedRight
                && '\\' === substr($trimmedRight, -1);
            $fragment = $continued
                ? substr($trimmedRight, 0, -1)
                : $physicalLine;
            $buffer .= ('' === $buffer ? '' : ' ') . $fragment;

            if (!$continued) {
                $logicalLines[] = array(
                    'line' => $startLine,
                    'content' => $buffer,
                );
                $buffer = '';
            }
        }

        if ('' !== $buffer) {
            $logicalLines[] = array(
                'line' => $startLine,
                'content' => $buffer,
            );
        }

        return $logicalLines;
    }

    private function blockDirective($line)
    {
        if (!preg_match('/^<\/?\s*([A-Za-z][A-Za-z0-9]*)/', $line, $matches)) {
            return 'unknown-block';
        }
        return strtolower($matches[1]);
    }

    private function classify($directive)
    {
        if (in_array($directive, $this->supported, true)) {
            return 'supported';
        }
        if (in_array(
            $directive,
            array('ifmodule', 'ifdefine', 'ifversion'),
            true
        )) {
            return 'ignored-safe';
        }
        if (in_array(
            $directive,
            array('files', 'filesmatch', 'limit', 'limitexcept'),
            true
        ) || in_array($directive, $this->unsupportedSecurity, true)
        ) {
            return 'unsupported-security';
        }
        if (in_array($directive, $this->unsupportedBehavior, true)) {
            return 'unsupported-behavior';
        }
        return 'unknown';
    }

    private function classificationMessage($classification)
    {
        if ('unsupported-security' === $classification) {
            return 'Apache security directive requires explicit translation.';
        }
        if ('unsupported-behavior' === $classification) {
            return 'Apache behavior directive is not yet translated.';
        }
        if ('scan-error' === $classification) {
            return 'Compatibility analysis could not be completed safely.';
        }
        return 'Unknown directive requires administrator review.';
    }

    private function limitFinding($root, $path, $message)
    {
        return $this->finding(
            $this->relativePath($root, $path),
            0,
            'scan-limit',
            'scan-error',
            $message
        );
    }

    private function resultStatus(array $findings)
    {
        if (empty($findings)) {
            return 'compatible';
        }
        foreach ($findings as $finding) {
            if ('scan-error' === $finding['classification']) {
                return 'blocked';
            }
        }
        return 'review';
    }

    private function finding(
        $file,
        $line,
        $directive,
        $classification,
        $message
    ) {
        return array(
            'file' => $file,
            'line' => (int) $line,
            'directive' => $directive,
            'classification' => $classification,
            'message' => $message,
        );
    }

    private function relativePath($root, $path)
    {
        if ($root === $path) {
            return '.';
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (0 !== strpos($path, $prefix)) {
            return '.';
        }
        return substr($path, strlen($prefix));
    }

    private function result($status, array $findings, $files)
    {
        return array(
            'status' => $status,
            'filesScanned' => (int) $files,
            'findingCount' => count($findings),
            'summary' => $this->summarize($findings),
            'findings' => $findings,
        );
    }

    private function summarize(array $findings)
    {
        $summary = array();
        foreach ($findings as $finding) {
            $key = $finding['classification'] . ':' . $finding['directive'];
            if (!isset($summary[$key])) {
                $summary[$key] = array(
                    'directive' => $finding['directive'],
                    'classification' => $finding['classification'],
                    'count' => 0,
                    'exampleFile' => $finding['file'],
                    'exampleLine' => $finding['line'],
                );
            }
            $summary[$key]['count']++;
        }
        uasort($summary, function ($left, $right) {
            if ($left['count'] === $right['count']) {
                return strcmp($left['directive'], $right['directive']);
            }
            return $right['count'] - $left['count'];
        });
        return array_values($summary);
    }
}
