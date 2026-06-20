<?php

class Modules_SkamasleOls_DomainInventory
{
    const DEFAULT_PAGE_SIZE = 25;
    const MAX_PAGE_SIZE = 100;
    const HTACCESS_SCAN_SETTING = 'skamasle-ols.htaccess-scan';

    private $lastError;
    private $htaccessScanner;
    private $readiness;
    private $serviceStatus;

    public function __construct(
        $htaccessScanner = null,
        $readiness = null,
        $serviceStatus = null
    )
    {
        $this->htaccessScanner = $htaccessScanner
            ? $htaccessScanner
            : new Modules_SkamasleOls_HtaccessScanner();
        $this->readiness = $readiness
            ? $readiness
            : new Modules_SkamasleOls_DomainReadiness();
        $this->serviceStatus = $serviceStatus
            ? $serviceStatus
            : new Modules_SkamasleOls_SystemServiceStatus();
    }

    public function getSummary($search = '', $page = 1, $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        $this->lastError = null;

        if (!class_exists('pm_Domain')) {
            $this->lastError = 'The Plesk domain API is unavailable.';
            return array();
        }

        try {
            $domains = pm_Domain::getAllDomains();
            $summary = array();
            $server = array(
                'nginx' => $this->serviceStatus->getNginxStatus(),
            );
            $searchTerm = $this->normalizeSearchTerm($search);
            $page = max(1, (int) $page);
            $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int) $pageSize));

            foreach ($domains as $domain) {
                $hasHosting = $domain->hasHosting();
                $item = array(
                    'name' => $domain->getDisplayName(),
                    'guid' => $domain->getGuid(),
                    'active' => $domain->isActive(),
                    'suspended' => $domain->isSuspended(),
                    'hosting' => $hasHosting,
                    'systemUser' => $hasHosting ? $domain->getSysUserLogin() : null,
                    'documentRoot' => $hasHosting ? $domain->getDocumentRoot() : null,
                );
                $item['nativeWebMode'] = $hasHosting
                    ? $this->detectNativeWebMode($domain)
                    : null;
                $htaccess = $hasHosting
                    ? $this->readCachedHtaccessResult($domain)
                    : $this->hostingUnavailableHtaccessResult();
                $item['htaccess'] = $htaccess;
                $item['readiness'] = $this->readiness->evaluate(
                    $item,
                    $htaccess,
                    $server
                );
                $item['prepared'] = '1' === $domain->getSetting(
                    'skamasle-ols.prepared',
                    '0'
                );
                $item['cacheEnabled'] = '1' === $domain->getSetting(
                    'skamasle-ols.lscache',
                    '0'
                );
                $item['cachePrivateEnabled'] = $item['cacheEnabled']
                    && '1' === $domain->getSetting(
                    'skamasle-ols.lscache_private',
                    '0'
                );
                $item['lsapi'] = $this->readLsapiSettings($domain);
                $item['requestedRouting'] = $domain->getSetting(
                    'skamasle-ols.routing',
                    'native'
                );
                if ('' !== $searchTerm && !$this->matchesSearch($item, $searchTerm)) {
                    continue;
                }
                $summary[] = $item;
            }

            $total = count($summary);
            $offset = ($page - 1) * $pageSize;
            $pageItems = array_slice($summary, $offset, $pageSize);
            $pageCount = 0 === $total ? 0 : (int) ceil($total / $pageSize);
            if ($pageCount > 0 && $page > $pageCount) {
                $page = $pageCount;
                $offset = ($page - 1) * $pageSize;
                $pageItems = array_slice($summary, $offset, $pageSize);
            }

            return array(
                'total' => count($domains),
                'filtered' => $total,
                'displayed' => count($pageItems),
                'page' => $page,
                'pageSize' => $pageSize,
                'pages' => $pageCount,
                'search' => $searchTerm,
                'items' => $pageItems,
            );
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            error_log(
                '[skamasle-ols] Domain inventory failed: '
                . $exception->getMessage()
            );
            return array();
        }
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    private function normalizeSearchTerm($search)
    {
        $term = strtolower(trim((string) $search));
        return preg_replace('/\s+/', ' ', $term);
    }

    private function matchesSearch(array $domain, $searchTerm)
    {
        $haystack = array(
            isset($domain['name']) ? $domain['name'] : '',
            isset($domain['systemUser']) ? $domain['systemUser'] : '',
            isset($domain['documentRoot']) ? $domain['documentRoot'] : '',
            isset($domain['readiness']['label']) ? $domain['readiness']['label'] : '',
        );
        foreach ($haystack as $value) {
            if ('' !== $value && false !== strpos(strtolower((string) $value), $searchTerm)) {
                return true;
            }
        }

        return false;
    }

    private function readCachedHtaccessResult($domain)
    {
        if (!is_object($domain) || !method_exists($domain, 'getSetting')) {
            return $this->notScannedHtaccessResult();
        }

        $raw = (string) $domain->getSetting(self::HTACCESS_SCAN_SETTING, '');
        if ('' === $raw) {
            return $this->notScannedHtaccessResult();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->notScannedHtaccessResult();
        }

        $status = isset($decoded['status']) ? (string) $decoded['status'] : '';
        if (!in_array($status, array('compatible', 'review', 'blocked'), true)) {
            return $this->notScannedHtaccessResult();
        }

        return array(
            'status' => $status,
            'filesScanned' => isset($decoded['filesScanned'])
                ? max(0, (int) $decoded['filesScanned'])
                : 0,
            'findingCount' => isset($decoded['findingCount'])
                ? max(0, (int) $decoded['findingCount'])
                : 0,
            'summary' => isset($decoded['summary']) && is_array($decoded['summary'])
                ? $decoded['summary']
                : array(),
            'findings' => array(),
            'scannedAt' => isset($decoded['scannedAt'])
                ? (string) $decoded['scannedAt']
                : null,
            'scanDepth' => isset($decoded['scanDepth'])
                ? (string) $decoded['scanDepth']
                : null,
        );
    }

    private function readLsapiSettings($domain)
    {
        $defaults = array(
            'maxConnections' => 8,
            'children' => 8,
            'instances' => 1,
            'backlog' => 100,
            'initTimeout' => 60,
            'retryTimeout' => 0,
            'persistentConnection' => true,
            'responseBuffering' => false,
        );
        if (!is_object($domain) || !method_exists($domain, 'getSetting')) {
            return $defaults;
        }
        $decoded = json_decode(
            (string) $domain->getSetting('skamasle-ols.lsapi', ''),
            true
        );
        if (!is_array($decoded)) {
            return $defaults;
        }

        $settings = array_merge($defaults, array_intersect_key($decoded, $defaults));
        $settings['children'] = $settings['maxConnections'];
        return $settings;
    }

    private function detectNativeWebMode($domain)
    {
        if (is_object($domain) && method_exists($domain, 'getProperty')) {
            try {
                $value = $domain->getProperty('nginxServePhp');
                $mode = $this->nativeWebModeFromFlag($value);
                if (null !== $mode) {
                    return $mode;
                }
            } catch (Throwable $exception) {
                // Older Plesk builds do not expose this property directly.
            }
        }

        $domainName = method_exists($domain, 'getName')
            ? (string) $domain->getName()
            : (method_exists($domain, 'getDisplayName')
                ? (string) $domain->getDisplayName()
                : '');
        if ('' === $domainName) {
            return null;
        }

        $pleskBinary = $this->findPleskBinary();
        if (null === $pleskBinary) {
            return null;
        }

        $sql = 'SELECT wsp.value '
            . 'FROM domains d '
            . 'JOIN dom_param dp ON dp.dom_id = d.id '
            . 'JOIN WebServerSettingsParameters wsp ON wsp.webServerSettingsId = dp.val '
            . 'WHERE dp.param = ' . $this->sqlLiteral('webServerSettingsId') . ' '
            . 'AND d.name = ' . $this->sqlLiteral($domainName) . ' '
            . 'AND wsp.name = ' . $this->sqlLiteral('nginxServePhp') . ' '
            . 'LIMIT 1';
        $command = escapeshellarg($pleskBinary)
            . ' db -Ne ' . escapeshellarg($sql);
        $output = array();
        $exitCode = 1;
        exec('LC_ALL=C ' . $command . ' 2>/dev/null', $output, $exitCode);
        if (0 !== $exitCode || empty($output)) {
            return null;
        }

        return $this->nativeWebModeFromFlag(trim((string) $output[0]));
    }

    private function nativeWebModeFromFlag($value)
    {
        if (is_bool($value)) {
            return $value ? 'nginx-only' : 'proxy';
        }

        $normalized = strtolower(trim((string) $value));
        if ('true' === $normalized || '1' === $normalized || 'yes' === $normalized) {
            return 'nginx-only';
        }
        if ('false' === $normalized || '0' === $normalized || 'no' === $normalized) {
            return 'proxy';
        }

        return null;
    }

    private function findPleskBinary()
    {
        foreach (array('/usr/sbin/plesk', '/usr/local/psa/admin/bin/plesk') as $plesk) {
            if (is_executable($plesk)) {
                return $plesk;
            }
        }

        return null;
    }

    private function sqlLiteral($value)
    {
        return "'" . str_replace(
            array('\\', "'"),
            array('\\\\', "\\'"),
            (string) $value
        ) . "'";
    }

    private function notScannedHtaccessResult()
    {
        return array(
            'status' => 'not-scanned',
            'filesScanned' => 0,
            'findingCount' => 0,
            'summary' => array(),
            'findings' => array(),
            'scannedAt' => null,
            'scanDepth' => null,
        );
    }

    private function hostingUnavailableHtaccessResult()
    {
        return array(
            'status' => 'blocked',
            'filesScanned' => 0,
            'findingCount' => 1,
            'summary' => array(array(
                'directive' => 'hosting',
                'classification' => 'scan-error',
                'count' => 1,
                'exampleFile' => '.',
                'exampleLine' => 0,
            )),
            'findings' => array(array(
                'file' => '.',
                'line' => 0,
                'directive' => 'hosting',
                'classification' => 'scan-error',
                'message' => 'Physical web hosting is unavailable.',
            )),
            'scannedAt' => null,
            'scanDepth' => null,
        );
    }
}
