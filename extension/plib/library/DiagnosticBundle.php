<?php

class Modules_SkamasleOls_DiagnosticBundle
{
    const SCHEMA_VERSION = 1;

    private $serviceStatus;

    public function __construct($serviceStatus = null)
    {
        $this->serviceStatus = $serviceStatus
            ? $serviceStatus
            : new Modules_SkamasleOls_SystemServiceStatus();
    }

    public function toJson()
    {
        $bundle = $this->collect();
        $json = json_encode(
            $bundle,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if (false === $json) {
            throw new pm_Exception('Unable to encode the diagnostic report.');
        }

        return $json . "\n";
    }

    public function collect()
    {
        $sections = array(
            'platform' => $this->collectPlatform(),
            'apis' => $this->collectApis(),
            'control' => $this->collectControl(),
            'phpHandlers' => $this->collectPhpHandlers(),
            'lsphpProbes' => $this->collectLsphpProbes(),
            'domains' => $this->collectDomains(),
        );

        $hashes = array();
        foreach ($sections as $name => $section) {
            $hashes[$name] = hash('sha256', $this->encodeForHash($section));
        }

        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => gmdate('c'),
            'privacy' => array(
                'anonymized' => true,
                'containsDomainNames' => false,
                'containsIpAddresses' => false,
                'containsFullGuids' => false,
                'containsRawDocumentRoots' => false,
                'reviewBeforeSharing' => true,
            ),
            'sections' => $sections,
            'sha256' => $hashes,
        );
    }

    private function collectPlatform()
    {
        return array(
            'pleskVersion' => $this->productInfo('getVersion'),
            'platform' => $this->productInfo('getPlatform'),
            'osName' => $this->productInfo('getOsName'),
            'osVersion' => $this->productInfo('getOsVersion'),
            'osArchitecture' => $this->productInfo('getOsArch'),
            'isUnix' => $this->productInfo('isUnix'),
        );
    }

    private function collectApis()
    {
        return array(
            'pmDomain' => class_exists('pm_Domain'),
            'pmHookWebServer' => class_exists('pm_Hook_WebServer'),
            'pmWebServer' => class_exists('pm_WebServer'),
            'pmApiCli' => class_exists('pm_ApiCli'),
            'processTemplate' => method_exists('pm_Hook_WebServer', 'processTemplate'),
        );
    }

    private function collectControl()
    {
        $status = new Modules_SkamasleOls_ControlPlaneStatus();
        return $status->get();
    }

    private function collectPhpHandlers()
    {
        if (!class_exists('pm_ApiCli')) {
            return array(
                'available' => false,
                'error' => 'pm_ApiCli is unavailable.',
            );
        }

        try {
            $result = pm_ApiCli::call(
                'php_handler',
                array('--list'),
                pm_ApiCli::RESULT_FULL
            );

            $parser = new Modules_SkamasleOls_PhpHandlerParser();
            $handlers = $parser->parse(
                isset($result['stdout']) ? $result['stdout'] : ''
            );

            return array(
                'available' => true,
                'exitCode' => isset($result['code']) ? (int) $result['code'] : null,
                'count' => count($handlers['items']),
                'items' => $handlers['items'],
                'unparsedLineCount' => $handlers['unparsedLineCount'],
                'hasErrors' => !empty($result['stderr']),
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] PHP handler diagnostics failed: '
                . $exception->getMessage()
            );

            return array(
                'available' => false,
                'error' => get_class($exception),
            );
        }
    }

    private function collectDomains()
    {
        if (!class_exists('pm_Domain')) {
            return array(
                'available' => false,
                'items' => array(),
            );
        }

        try {
            $domains = pm_Domain::getAllDomains();
            usort($domains, array($this, 'compareDomains'));
            $items = array();
            $scanner = new Modules_SkamasleOls_HtaccessScanner();
            $readinessEvaluator = new Modules_SkamasleOls_DomainReadiness();
            $server = array(
                'nginx' => $this->serviceStatus->getNginxStatus(),
            );

            foreach ($domains as $index => $domain) {
                $number = $index + 1;
                $alias = sprintf('domain-%03d.test', $number);
                $hasHosting = $domain->hasHosting();

                $documentRoot = $hasHosting
                    ? (string) $domain->getDocumentRoot()
                    : null;
                $htaccess = $hasHosting
                    ? $scanner->scan($documentRoot)
                    : array(
                        'status' => 'blocked',
                        'filesScanned' => 0,
                        'findingCount' => 1,
                        'findings' => array(array(
                            'classification' => 'scan-error',
                        )),
                    );
                $domainState = array(
                    'active' => (bool) $domain->isActive(),
                    'suspended' => (bool) $domain->isSuspended(),
                    'hosting' => (bool) $hasHosting,
                );
                $readiness = $readinessEvaluator->evaluate(
                    $domainState,
                    $htaccess,
                    $server
                );

                $items[] = array(
                    'alias' => $alias,
                    'guidHash' => substr(
                        hash('sha256', (string) $domain->getGuid()),
                        0,
                        16
                    ),
                    'active' => (bool) $domain->isActive(),
                    'suspended' => (bool) $domain->isSuspended(),
                    'disabled' => (bool) $domain->isDisabled(),
                    'hasHosting' => (bool) $hasHosting,
                    'systemUserAlias' => $hasHosting
                        ? sprintf('user-%03d', $number)
                        : null,
                    'documentRoot' => $hasHosting
                        ? $this->classifyDocumentRoot($domain)
                        : null,
                    'ipAddressCount' => count((array) $domain->getIpAddresses()),
                    'htaccess' => array(
                        'status' => $htaccess['status'],
                        'filesScanned' => $htaccess['filesScanned'],
                        'findingCount' => $htaccess['findingCount'],
                        'classifications' => $this->countClassifications(
                            $htaccess['findings']
                        ),
                    ),
                    'readiness' => $readiness['status'],
                );
            }

            return array(
                'available' => true,
                'count' => count($items),
                'items' => $items,
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Domain diagnostics failed: '
                . $exception->getMessage()
            );

            return array(
                'available' => false,
                'error' => get_class($exception),
                'items' => array(),
            );
        }
    }

    private function collectLsphpProbes()
    {
        if (!class_exists('pm_ApiCli')) {
            return array(
                'available' => false,
                'items' => array(),
                'error' => 'pm_ApiCli is unavailable.',
            );
        }

        try {
            $result = pm_ApiCli::callSbin(
                'skamasle-ols-lsphp-probe',
                array(),
                pm_ApiCli::RESULT_FULL
            );
            $items = array();
            $unparsed = 0;
            $lines = preg_split(
                '/\R/',
                isset($result['stdout']) ? (string) $result['stdout'] : ''
            );

            foreach ($lines as $line) {
                if ('' === trim($line)) {
                    continue;
                }

                $fields = explode("\t", $line, 5);
                if (5 !== count($fields)
                    || !preg_match('/^\d+\.\d+$/', $fields[0])
                    || !preg_match('/^[a-f0-9]{64}$/', $fields[2])
                ) {
                    $unparsed++;
                    continue;
                }

                $items[] = array(
                'branch' => $fields[0],
                'sapi' => $fields[1],
                'isLiteSpeedSapi' => false !== stripos($fields[3], 'litespeed')
                    || 'litespeed' === strtolower($fields[1]),
                'modulesSha256' => $fields[2],
                'versionSummary' => $fields[3],
                'iniSummary' => $fields[4],
            );
            }

            return array(
                'available' => true,
                'exitCode' => isset($result['code']) ? (int) $result['code'] : null,
                'count' => count($items),
                'items' => $items,
                'unparsedLineCount' => $unparsed,
                'hasErrors' => !empty($result['stderr']),
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] LSPHP probe failed: '
                . $exception->getMessage()
            );

            return array(
                'available' => false,
                'items' => array(),
                'error' => get_class($exception),
            );
        }
    }

    public function compareDomains($left, $right)
    {
        return strcmp($left->getName(), $right->getName());
    }

    private function classifyDocumentRoot($domain)
    {
        try {
            $relative = (string) $domain->getDocumentRoot(true);
            if ('' === $relative || false !== strpos($relative, '..')) {
                return null;
            }

            $normalized = trim(str_replace('\\', '/', $relative), '/');
            $segments = array_values(array_filter(explode('/', $normalized)));

            return array(
                'kind' => 'httpdocs' === $normalized ? 'default' : 'custom',
                'depth' => count($segments),
            );
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function countClassifications(array $findings)
    {
        $counts = array();
        foreach ($findings as $finding) {
            $classification = $finding['classification'];
            if (!isset($counts[$classification])) {
                $counts[$classification] = 0;
            }
            $counts[$classification]++;
        }
        ksort($counts);
        return $counts;
    }

    private function productInfo($method)
    {
        if (!class_exists('pm_ProductInfo')
            || !is_callable(array('pm_ProductInfo', $method))
        ) {
            return null;
        }

        try {
            $value = call_user_func(array('pm_ProductInfo', $method));
            return is_scalar($value) || null === $value ? $value : null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function encodeForHash($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        return false === $json ? '' : $json;
    }
}
