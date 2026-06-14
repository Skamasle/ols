<?php

class Modules_SkamasleOls_OlsConfigManager
{
    private $serverRoot;
    private $configRoot;
    private $stateRoot;
    private $runtimeRoot;

    private static function defaultStateRoot()
    {
        if (class_exists('pm_Context') && method_exists('pm_Context', 'getVarDir')) {
            return rtrim(pm_Context::getVarDir(), '/');
        }

        return '/usr/local/psa/var/modules/skamasle-ols';
    }

    public function __construct(
        $serverRoot = '/usr/local/lsws/conf',
        $configRoot = '/usr/local/lsws/conf/skamasle-ols',
        $stateRoot = null,
        $runtimeRoot = null
    ) {
        $this->serverRoot = rtrim($serverRoot, '/');
        $this->configRoot = rtrim($configRoot, '/');
        $this->stateRoot = rtrim(
            null === $stateRoot ? self::defaultStateRoot() : $stateRoot,
            '/'
        );
        $this->runtimeRoot = rtrim(
            null === $runtimeRoot ? $this->stateRoot . '/run' : $runtimeRoot,
            '/'
        );
    }

    public function getConfigRoot()
    {
        return $this->configRoot;
    }

    public function getStateRoot()
    {
        return $this->stateRoot;
    }

    public function getRuntimeRoot()
    {
        return $this->runtimeRoot;
    }

    public function getHttpdConfigPath()
    {
        return $this->serverRoot . '/httpd_config.conf';
    }

    public function getAdminConfigPath()
    {
        return dirname($this->serverRoot) . '/admin/conf/admin_config.conf';
    }

    public function getListenerPath($port)
    {
        return $this->configRoot . '/listeners/listener-' . (int) $port . '.conf';
    }

    public function getVhostPath($identifier)
    {
        return $this->configRoot . '/vhosts/' . $this->sanitizeIdentifier($identifier) . '.conf';
    }

    public function getVhostConfigPath($identifier)
    {
        return $this->stateRoot . '/vhosts/'
            . $this->sanitizeIdentifier($identifier) . '/vhconf.conf';
    }

    public function getSocketPath($identifier)
    {
        return $this->getSocketDirectory() . '/sk-'
            . substr(hash('sha256', $this->sanitizeIdentifier($identifier)), 0, 24)
            . '.sock';
    }

    public function getSocketDirectory()
    {
        return $this->runtimeRoot . '/lsphp';
    }

    public function getListenerSslDirectory()
    {
        return $this->serverRoot . '/ssl';
    }

    public function getListenerSslKeyPath()
    {
        return $this->getListenerSslDirectory() . '/skamasle-ols.key';
    }

    public function getListenerSslCertPath()
    {
        return $this->getListenerSslDirectory() . '/skamasle-ols.crt';
    }

    public function getDomainRootPath(array $domain)
    {
        if (!empty($domain['vhostRoot'])) {
            return rtrim((string) $domain['vhostRoot'], '/');
        }

        return dirname(rtrim((string) $domain['documentRoot'], '/'));
    }

    public function getCachePath(array $domain)
    {
        return $this->getDomainRootPath($domain) . '/lscache';
    }

    public function getLogPath()
    {
        return $this->stateRoot . '/logs/ols-debug.log';
    }

    public function getRoutingPath($identifier)
    {
        return $this->stateRoot . '/nginx-routing/'
            . $this->sanitizeIdentifier($identifier) . '.conf';
    }

    public function getDomainArtifacts($identifier, $port, $domainName = null)
    {
        $listenerPath = $this->getListenerPath($port);
        $vhostPath = $this->getVhostPath(
            null !== $domainName ? $domainName : $identifier
        );
        $vhostConfigPath = $this->getVhostConfigPath($identifier);
        $routingPath = $this->getRoutingPath($identifier);

        return array(
            'listener' => $this->diagnosePath($listenerPath),
            'listenerBackup' => $this->diagnosePath($listenerPath . '0'),
            'vhost' => $this->diagnosePath($vhostPath),
            'vhostBackup' => $this->diagnosePath($vhostPath . '0'),
            'vhostConfig' => $this->diagnosePath($vhostConfigPath),
            'routing' => $this->diagnosePath($routingPath),
        );
    }

    public function ensureLayout()
    {
        $this->ensureLogLayout();
        $this->ensureDirectory($this->configRoot);
        $this->ensureDirectory($this->configRoot . '/listeners');
        $this->ensureDirectory($this->configRoot . '/vhosts');
        $this->ensureDirectory($this->getListenerSslDirectory());
        $this->ensureDirectory($this->stateRoot . '/vhosts');
        $this->ensureDirectory($this->stateRoot . '/php');
        $this->ensureDirectory($this->stateRoot . '/nginx-routing');
        $this->ensureDirectoryWithMode($this->getSocketDirectory(), 01777);
        $this->ensureDirectory($this->runtimeRoot);
        $this->ensureDirectory($this->runtimeRoot . '/lsphp');
    }

    public function logEvent($event, array $context = array())
    {
        $this->ensureLogLayout();
        $this->appendLog($event, $context);
        return $this->getLogPath();
    }

    public function getDiagnostics()
    {
        return array(
            'identity' => $this->runtimeIdentity(),
            'php' => array(
                'binary' => defined('PHP_BINARY') ? PHP_BINARY : null,
                'version' => PHP_VERSION,
                'openBasedir' => ini_get('open_basedir'),
            ),
            'paths' => array(
                'serverRoot' => $this->diagnosePath($this->serverRoot),
                'httpdConfig' => $this->diagnosePath($this->getHttpdConfigPath()),
                'adminConfig' => $this->diagnosePath($this->getAdminConfigPath()),
                'configRoot' => $this->diagnosePath($this->configRoot),
                'configParent' => $this->diagnosePath(dirname($this->configRoot)),
                'stateRoot' => $this->diagnosePath($this->stateRoot),
                'logDirectory' => $this->diagnosePath(dirname($this->getLogPath())),
                'logFile' => $this->diagnosePath($this->getLogPath()),
                'runtimeRoot' => $this->diagnosePath($this->runtimeRoot),
            ),
        );
    }

    public function syncIncludeBlock()
    {
        $this->ensureLayout();
        $path = $this->getHttpdConfigPath();
        if (!is_file($path) || is_link($path)) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'OLS httpd_config.conf was not found.',
                'diagnostics' => $this->getDiagnostics(),
            );
        }

        $original = file_get_contents($path);
        if (false === $original) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'Unable to read httpd_config.conf.' . $this->lastErrorSuffix(),
                'diagnostics' => $this->getDiagnostics(),
            );
        }

        $updated = $original;
        $block = $this->includeBlock();
        if (false === strpos($updated, $block)) {
            $updated = rtrim($updated) . PHP_EOL . PHP_EOL . $block . PHP_EOL;
        }
        if ($updated === $original) {
            return array(
                'available' => true,
                'configured' => true,
                'path' => $path,
                'changed' => false,
                'message' => 'OLS managed server settings are already present.',
            );
        }

        $result = $this->writeAtomic($path, $updated);
        $result['path'] = $path;
        $result['changed'] = true;
        return $result;
    }

    public function syncCacheModule()
    {
        $this->ensureLayout();
        $path = $this->getHttpdConfigPath();
        if (!is_file($path) || is_link($path)) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'OLS httpd_config.conf was not found.',
                'diagnostics' => $this->getDiagnostics(),
            );
        }

        $original = file_get_contents($path);
        if (false === $original) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'Unable to read httpd_config.conf.' . $this->lastErrorSuffix(),
                'diagnostics' => $this->getDiagnostics(),
            );
        }

        $block = $this->serverCacheModuleBlock();
        $pattern = '/# BEGIN SKAMASLE OLS LSCache\R.*?'
            . '# END SKAMASLE OLS LSCache/s';
        if (preg_match($pattern, $original)) {
            $updated = preg_replace($pattern, $block, $original, 1);
        } else {
            $updated = rtrim($original) . PHP_EOL . PHP_EOL . $block . PHP_EOL;
        }
        if ($updated === $original) {
            return array(
                'available' => true,
                'configured' => true,
                'path' => $path,
                'changed' => false,
                'message' => 'OLS cache module is already registered.',
            );
        }

        $result = $this->writeAtomic($path, $updated);
        $result['path'] = $path;
        $result['changed'] = true;
        return $result;
    }

    public function syncAdminListener($port = 8070)
    {
        $path = $this->getAdminConfigPath();
        if (!is_file($path) || is_link($path)) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'OLS admin_config.conf was not found.',
            );
        }
        $original = file_get_contents($path);
        if (false === $original) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'Unable to read admin_config.conf.' . $this->lastErrorSuffix(),
            );
        }

        $updated = preg_replace_callback(
            '/(listener\s+adminListener\s*\{.*?^\s*address\s+)(\S+)/ms',
            static function ($matches) use ($port) {
                return $matches[1] . '127.0.0.1:' . (int) $port;
            },
            $original,
            1,
            $count
        );
        if (1 !== $count) {
            return array(
                'available' => false,
                'configured' => false,
                'path' => $path,
                'error' => 'Unable to locate the OLS adminListener address.',
            );
        }
        if ($updated === $original) {
            return array(
                'available' => true,
                'configured' => true,
                'path' => $path,
                'changed' => false,
                'address' => '127.0.0.1:' . (int) $port,
            );
        }

        $result = $this->writeAtomic($path, $updated, 0600);
        $result['path'] = $path;
        $result['changed'] = true;
        $result['address'] = '127.0.0.1:' . (int) $port;
        return $result;
    }

    public function syncServerIdentity($user = 'apache', $group = 'apache')
    {
        $user = $this->normalizeAccountName($user, 'user');
        $group = $this->normalizeAccountName($group, 'group');
        $paths = array(
            $this->getHttpdConfigPath(),
            $this->getHttpdConfigPath() . '0',
            $this->serverRoot . '/httpd_config.txt',
        );

        $results = array();
        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $original = file_get_contents($path);
            if (false === $original) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'path' => $path,
                    'error' => 'Unable to read server identity file.' . $this->lastErrorSuffix(),
                );
            }

            $updated = preg_replace(
                array(
                    '/^(\s*user\s+)\S+(\s*)$/mi',
                    '/^(\s*group\s+)\S+(\s*)$/mi',
                ),
                array(
                    '$1' . $user . '$2',
                    '$1' . $group . '$2',
                ),
                $original
            );

            if (null === $updated) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'path' => $path,
                    'error' => 'Unable to rewrite server identity configuration.' . $this->lastErrorSuffix(),
                );
            }

            if ($updated === $original) {
                $results[] = array(
                    'available' => true,
                    'configured' => true,
                    'path' => $path,
                    'changed' => false,
                    'user' => $user,
                    'group' => $group,
                );
                continue;
            }

            $writeResult = $this->writeAtomic($path, $updated, 0640);
            if (empty($writeResult['available'])) {
                return $writeResult;
            }
            $writeResult['changed'] = true;
            $writeResult['user'] = $user;
            $writeResult['group'] = $group;
            $results[] = $writeResult;
        }

        return array(
            'available' => true,
            'configured' => true,
            'user' => $user,
            'group' => $group,
            'files' => $results,
        );
    }

    public function writeListener(array $listener, array $domains = array())
    {
        $this->ensureLayout();
        $listenerSslResult = $this->syncListenerSslCertificate();
        if (empty($listenerSslResult['configured'])) {
            return $listenerSslResult;
        }
        $path = $this->getListenerPath($this->listenerPort($listener));
        $content = $this->renderListener($listener, $domains);
        $result = $this->writeAtomic($path, $content);
        $result['path'] = $path;
        $result['listenerSsl'] = $listenerSslResult;
        return $result;
    }

    public function syncListenerSslCertificate()
    {
        $this->ensureLayout();
        return $this->ensureListenerSslCertificate();
    }

    public function writeVhost(array $domain)
    {
        $this->ensureLayout();
        $identifier = isset($domain['name']) ? $domain['name'] : $domain['guid'];
        $path = $this->getVhostPath($identifier);
        $content = $this->renderVhost($domain);
        $result = $this->writeAtomic($path, $content);
        $result['path'] = $path;
        return $result;
    }

    public function writeVhostConfig(array $domain)
    {
        $this->ensureDomainLayout($domain);
        $identifier = isset($domain['guid']) ? $domain['guid'] : $domain['name'];
        $path = $this->getVhostConfigPath($identifier);
        $content = $this->renderVhostConfig($domain);
        $result = $this->writeAtomic($path, $content, 0644);
        $result['path'] = $path;
        return $result;
    }

    public function writeRoutingConfig(array $domain, array $listener, $routing)
    {
        $this->ensureLayout();
        $content = $this->renderRoutingConfig($domain, $listener, $routing);
        $paths = array();
        if (!empty($domain['name'])) {
            $paths[] = $this->getRoutingPath($domain['name']);
        }
        if (!empty($domain['guid'])) {
            $paths[] = $this->getRoutingPath($domain['guid']);
        }
        $paths = array_values(array_unique($paths));

        $primaryResult = null;
        $results = array();
        foreach ($paths as $path) {
            $result = $this->writeAtomic($path, $content, 0644);
            $result['path'] = $path;
            $results[] = $result;
            if (null === $primaryResult) {
                $primaryResult = $result;
            }
        }
        if (null === $primaryResult) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to determine routing paths.',
            );
        }

        $primaryResult['paths'] = $results;
        return $primaryResult;
    }

    public function clearDomainArtifacts(array $domain, array $listener, array $remainingDomains = array())
    {
        $this->ensureLayout();

        $name = isset($domain['name'])
            ? $this->sanitizeDomainName($domain['name'])
            : null;
        $guid = isset($domain['guid'])
            ? $this->sanitizeIdentifier($domain['guid'])
            : null;
        $paths = array();
        if (null !== $name) {
            $paths[] = $this->getVhostPath($name);
            $paths[] = $this->getRoutingPath($name);
        }
        if (null !== $guid) {
            $paths[] = $this->getVhostPath($guid);
            $paths[] = $this->getRoutingPath($guid);
            $paths[] = $this->getVhostConfigPath($guid);
            $paths[] = $this->getVhostPath($guid) . '0';
        }

        $removed = array();
        foreach (array_values(array_unique($paths)) as $path) {
            if ($this->removeFileIfManaged($path)) {
                $removed[] = $path;
            }
        }

        $listenerResult = $this->writeListener($listener, $remainingDomains);
        if (empty($listenerResult['available'])) {
            return $listenerResult;
        }

        if (null !== $guid) {
            $this->removeEmptyParentDirectories(
                dirname($this->getVhostConfigPath($guid)),
                $this->stateRoot
            );
        }

        return array(
            'available' => true,
            'configured' => true,
            'listener' => $listenerResult,
            'removed' => $removed,
        );
    }

    public function stageDomain(
        array $domain,
        array $listener,
        array $managedDomains = array()
    )
    {
        $this->ensureLogLayout();
        $this->appendLog('stage-domain.begin', array(
            'domain' => isset($domain['name']) ? $domain['name'] : null,
            'guid' => isset($domain['guid']) ? $domain['guid'] : null,
            'listenerPort' => $this->listenerPort($listener),
            'diagnostics' => $this->getDiagnostics(),
        ));
        $includeResult = $this->syncIncludeBlock();
        if (empty($includeResult['configured'])) {
            $this->appendLog('stage-domain.include-failed', $includeResult);
            return $includeResult;
        }
        $this->appendLog('stage-domain.include-ok', $includeResult);

        $adminResult = $this->syncAdminListener(8070);
        if (empty($adminResult['configured'])) {
            $this->appendLog('stage-domain.admin-listener-failed', $adminResult);
            return $adminResult;
        }
        $this->appendLog('stage-domain.admin-listener-ok', $adminResult);

        $cacheResult = $this->syncCacheModule();
        if (empty($cacheResult['configured'])) {
            $this->appendLog('stage-domain.cache-module-failed', $cacheResult);
            return $cacheResult;
        }
        $this->appendLog('stage-domain.cache-module-ok', $cacheResult);

        $this->ensureDomainLayout($domain);
        $this->appendLog('stage-domain.layout-ok', array(
            'vhostRoot' => dirname($this->getVhostConfigPath($domain['guid'])),
            'runtimeRoot' => $this->runtimeRoot . '/lsphp',
        ));

        $vhostConfigResult = $this->writeVhostConfig($domain);
        if (empty($vhostConfigResult['available'])) {
            $this->appendLog('stage-domain.vhost-config-failed', $vhostConfigResult);
            return $vhostConfigResult;
        }
        $this->appendLog('stage-domain.vhost-config-ok', array(
            'path' => $vhostConfigResult['path'],
            'file' => $this->diagnosePath($vhostConfigResult['path']),
        ));

        $routingResult = $this->writeRoutingConfig(
            $domain,
            $listener,
            isset($domain['appliedRouting']) ? $domain['appliedRouting'] : 'native'
        );
        if (empty($routingResult['available'])) {
            $this->appendLog('stage-domain.routing-failed', $routingResult);
            return $routingResult;
        }
        $this->appendLog('stage-domain.routing-ok', array(
            'path' => $routingResult['path'],
            'routing' => isset($domain['appliedRouting']) ? $domain['appliedRouting'] : 'native',
            'file' => $this->diagnosePath($routingResult['path']),
        ));

        $domains = $this->mergeDomains($managedDomains, $domain);
        $listenerResult = $this->writeListener($listener, $domains);
        if (empty($listenerResult['available'])) {
            $this->appendLog('stage-domain.listener-failed', $listenerResult);
            return $listenerResult;
        }
        $this->appendLog('stage-domain.listener-ok', array(
            'path' => $listenerResult['path'],
            'mappedDomains' => count($domains),
            'file' => $this->diagnosePath($listenerResult['path']),
        ));

        $vhostResult = $this->writeVhost($domain);
        if (empty($vhostResult['available'])) {
            $this->appendLog('stage-domain.vhost-failed', $vhostResult);
            return $vhostResult;
        }
        $this->appendLog('stage-domain.vhost-ok', array(
            'path' => $vhostResult['path'],
            'file' => $this->diagnosePath($vhostResult['path']),
        ));

        $this->appendLog('stage-domain.done', array(
            'domain' => isset($domain['name']) ? $domain['name'] : null,
            'guid' => isset($domain['guid']) ? $domain['guid'] : null,
            'listenerPath' => $listenerResult['path'],
            'vhostPath' => $vhostResult['path'],
            'vhostConfigPath' => $vhostConfigResult['path'],
        ));

        return array(
            'available' => true,
            'configured' => true,
            'include' => $includeResult,
            'listener' => $listenerResult,
            'vhost' => $vhostResult,
            'vhostConfig' => $vhostConfigResult,
            'routing' => $routingResult,
            'logPath' => $this->getLogPath(),
        );
    }

    private function includeBlock()
    {
        return implode(PHP_EOL, array(
            '# BEGIN SKAMASLE OLS',
            'include $SERVER_ROOT/conf/skamasle-ols/vhosts/*.conf',
            'include $SERVER_ROOT/conf/skamasle-ols/listeners/*.conf',
            '# END SKAMASLE OLS',
        ));
    }

    private function renderListener(array $listener, array $domains)
    {
        $bindAddress = isset($listener['bindAddress']) ? (string) $listener['bindAddress'] : '127.0.0.1';
        $port = $this->listenerPort($listener);
        $lines = array(
            '# Managed by skamasle-ols',
            'listener skamasle-ols-' . $port . ' {',
            '  address ' . $bindAddress . ':' . $port,
            '  secure 1',
            '  keyFile                 ' . $this->getListenerSslKeyPath(),
            '  certFile                ' . $this->getListenerSslCertPath(),
        );
        foreach ($domains as $domain) {
            if (empty($domain['name'])) {
                continue;
            }
            $name = $this->sanitizeDomainName($domain['name']);
            $aliases = isset($domain['aliases']) && is_array($domain['aliases'])
                ? $domain['aliases']
                : array();
            $hosts = array_merge(array($name), $aliases);
            if (0 !== strpos($name, 'www.')) {
                $hosts[] = 'www.' . $name;
            }
            $hosts = array_values(array_unique(array_map(
                array($this, 'sanitizeDomainName'),
                $hosts
            )));
            $lines[] = '  map ' . $name . ' ' . implode(',', $hosts);
        }
        $lines[] = '}';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function renderVhost(array $domain)
    {
        $name = isset($domain['name']) ? (string) $domain['name'] : 'unknown.local';
        $guid = isset($domain['guid']) ? (string) $domain['guid'] : 'unknown';
        $vhostRoot = $this->getDomainRootPath($domain);
        $configPath = $this->getVhostConfigPath($guid);

        return implode(PHP_EOL, array(
            '# Managed by skamasle-ols',
            'virtualHost ' . $this->sanitizeDomainName($name) . ' {',
            '  vhRoot ' . $vhostRoot . '/',
            '  configFile ' . $configPath,
            '  allowSymbolLink 1',
            '  enableScript 1',
            '  restrained 0',
            '  setUIDMode 2',
            '}',
            '',
        ));
    }

    private function ensureListenerSslCertificate()
    {
        $directory = $this->getListenerSslDirectory();
        $keyPath = $this->getListenerSslKeyPath();
        $certPath = $this->getListenerSslCertPath();
        $subject = '/C=ES/ST=Plesk/L=Local/O=skamasle-OLS Backend/CN=localhost';
        $validDays = 3650;

        $this->ensureDirectory($directory);

        if (is_file($keyPath) && is_file($certPath) && is_readable($keyPath) && is_readable($certPath)) {
            return array(
                'available' => true,
                'configured' => true,
                'created' => false,
                'keyFile' => $keyPath,
                'certFile' => $certPath,
                'path' => $directory,
            );
        }

        $generated = $this->generateSelfSignedCertificate(
            $subject,
            $validDays,
            $keyPath,
            $certPath
        );
        if (empty($generated['configured'])) {
            return $generated;
        }

        return array(
            'available' => true,
            'configured' => true,
            'created' => true,
            'keyFile' => $keyPath,
            'certFile' => $certPath,
            'path' => $directory,
        );
    }

    private function generateSelfSignedCertificate($subject, $validDays, $keyPath, $certPath)
    {
        if (!function_exists('openssl_pkey_new')
            || !function_exists('openssl_csr_new')
            || !function_exists('openssl_csr_sign')
            || !function_exists('openssl_x509_export')
            || !function_exists('openssl_pkey_export')
        ) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'PHP OpenSSL support is required to generate the OLS listener certificate.',
                'target' => $this->diagnosePath($certPath),
            );
        }

        $privateKey = @openssl_pkey_new(array(
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ));
        if (false === $privateKey) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to create the OLS listener private key.',
                'target' => $this->diagnosePath($keyPath),
            );
        }

        $csr = @openssl_csr_new(array(
            'countryName' => 'ES',
            'stateOrProvinceName' => 'Plesk',
            'localityName' => 'Local',
            'organizationName' => 'skamasle-OLS Backend',
            'organizationalUnitName' => 'skamasle-ols',
            'commonName' => 'localhost',
        ), $privateKey, array('digest_alg' => 'sha256'));
        if (false === $csr) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to create the OLS listener certificate request.',
                'target' => $this->diagnosePath($certPath),
            );
        }

        $certificate = @openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            $validDays,
            array('digest_alg' => 'sha256')
        );
        if (false === $certificate) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to sign the OLS listener certificate.',
                'target' => $this->diagnosePath($certPath),
            );
        }

        $privateKeyPem = null;
        if (!@openssl_pkey_export($privateKey, $privateKeyPem)) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to export the OLS listener private key.',
                'target' => $this->diagnosePath($keyPath),
            );
        }

        $certificatePem = null;
        if (!@openssl_x509_export($certificate, $certificatePem)) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Unable to export the OLS listener certificate.',
                'target' => $this->diagnosePath($certPath),
            );
        }

        $keyResult = $this->writeAtomic($keyPath, $privateKeyPem, 0600);
        if (empty($keyResult['configured'])) {
            return $keyResult;
        }

        $certResult = $this->writeAtomic($certPath, $certificatePem, 0644);
        if (empty($certResult['configured'])) {
            return $certResult;
        }

        return array(
            'available' => true,
            'configured' => true,
            'created' => true,
            'keyFile' => $keyPath,
            'certFile' => $certPath,
            'subject' => $subject,
            'validDays' => $validDays,
        );
    }

    private function renderVhostConfig(array $domain)
    {
        $name = $this->sanitizeDomainName($domain['name']);
        $guid = $this->sanitizeIdentifier($domain['guid']);
        $documentRoot = rtrim((string) $domain['documentRoot'], '/');
        $systemUser = (string) $domain['systemUser'];
        $systemGroup = (string) $domain['systemGroup'];
        $php = isset($domain['php']) && is_array($domain['php'])
            ? $domain['php']
            : array();
        $socket = isset($php['socket'])
            ? (string) $php['socket']
            : $this->getSocketPath($guid);
        $lsphpBinary = isset($php['lsphpBinary'])
            ? (string) $php['lsphpBinary']
            : '';
        $phpIniDir = isset($domain['phpIniDir'])
            ? rtrim((string) $domain['phpIniDir'], '/')
            : '/var/www/vhosts/system/' . $name . '/etc';
        $cachePath = $this->getCachePath($domain);
        $socketAddress = 'uds://' . ltrim($socket, '/');
        $cacheEnabled = !empty($domain['cacheEnabled']);
        $aliases = isset($domain['aliases']) && is_array($domain['aliases'])
            ? $domain['aliases']
            : array();
        $hosts = array_merge(array($name), $aliases);
        if (0 !== strpos($name, 'www.')) {
            $hosts[] = 'www.' . $name;
        }
        $hosts = array_values(array_unique(array_map(
            array($this, 'sanitizeDomainName'),
            $hosts
        )));

        return implode(PHP_EOL, array(
            '# Managed by skamasle-ols',
            'docRoot ' . $documentRoot,
            'vhDomain ' . $name,
            'vhAliases ' . implode(',', array_values(array_diff(
                $hosts,
                array($name)
            ))),
            'enableGzip 1',
            'enableBr 1',
            '',
            'index {',
            '  useServer 0',
            '  indexFiles index.php,index.html,index.htm',
            '}',
            '',
            'extProcessor lsphp {',
            '    type lsapi',
            '    address ' . $socketAddress,
            '    maxConns 10',
            '    env PHP_LSAPI_CHILDREN=10',
            '    env PHPRC=' . $phpIniDir,
            '    env LSPHP_ENABLE_USER_INI=on',
            '    env HTTPS=on',
            '    env SERVER_PORT=443',
            '    env REQUEST_SCHEME=https',
            '    initTimeout 60',
            '    retryTimeout 0',
            '    persistConn 1',
            '    respBuffer 0',
            '    autoStart 1',
            '    path ' . $lsphpBinary,
            '    backlog 100',
            '    instances 1',
            '    extUser ' . $systemUser,
            '    extGroup ' . $systemGroup,
            '}',
            '',
            'scriptHandler {',
            '  add lsapi:lsphp php',
            '}',
            '',
            'rewrite {',
            '  enable 1',
            '  autoLoadHtaccess 1',
            '}',
            '',
            $this->renderVhostCacheModule($cacheEnabled, $cachePath),
        ));
    }

    private function serverCacheModuleBlock()
    {
        return implode(PHP_EOL, array(
            '# BEGIN SKAMASLE OLS LSCache',
            'module cache {',
            '  internal                1',
            '  ls_enabled              1',
            '',
            '  storagePath $SERVER_ROOT/cachedata',
            '  checkPrivateCache   0',
            '  checkPublicCache    1',
            '  maxCacheObjSize     10000000',
            '  maxStaleAge         0',
            '  qsCache             1',
            '  reqCookieCache      1',
            '  respCookieCache     0',
            '  ignoreReqCacheCtrl  1',
            '  ignoreRespCacheCtrl 0',
            '',
            '  enableCache         0',
            '  expireInSeconds     3600',
            '  enablePrivateCache  0',
            '  privateExpireInSeconds 3600',
            '}',
            '# END SKAMASLE OLS LSCache',
        ));
    }

    private function renderVhostCacheModule($enabled, $cachePath)
    {
        return implode(PHP_EOL, array(
            'module cache {',
            '  ls_enabled              1',
            '  storagePath ' . $cachePath,
            '  checkPrivateCache   0',
            '  checkPublicCache    1',
            '  maxCacheObjSize     10000000',
            '  maxStaleAge         0',
            '  qsCache             1',
            '  reqCookieCache      1',
            '  respCookieCache     0',
            '  ignoreReqCacheCtrl  1',
            '  ignoreRespCacheCtrl 0',
            '',
            '  enableCache         ' . ($enabled ? '1' : '0'),
            '  expireInSeconds     3600',
            '  enablePrivateCache  0',
            '  privateExpireInSeconds 3600',
            '}',
            '',
        ));
    }

    private function renderRoutingConfig(array $domain, array $listener, $routing)
    {
        $name = isset($domain['name'])
            ? $this->sanitizeDomainName($domain['name'])
            : 'unknown.local';
        $port = $this->listenerPort($listener);
        $lines = array(
            '# Managed by skamasle-ols',
            '# domain ' . $name,
            '# routing ' . (string) $routing,
        );
        if ('ols' === $routing) {
            $lines[] = 'set $skamasle_ols_proxy_port ' . $port . ';';
        }
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function listenerPort(array $listener)
    {
        if (!isset($listener['port'])
            || !is_int($listener['port'])
            || $listener['port'] < 1024
            || $listener['port'] > 65535
        ) {
            throw new InvalidArgumentException(
                'A valid listener port is required.'
            );
        }

        return $listener['port'];
    }

    private function writeAtomic($path, $content, $mode = 0640)
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $temporaryFile = tempnam($directory, '.skamasle-ols.');
        if (false === $temporaryFile) {
            return $this->writeError(
                'tempnam',
                $path,
                null,
                'Unable to create temporary file.'
            );
        }

        try {
            if (false === file_put_contents($temporaryFile, $content, LOCK_EX)) {
                return $this->writeError(
                    'write',
                    $path,
                    $temporaryFile,
                    'Unable to write temporary file.'
                );
            }
            if (!chmod($temporaryFile, $mode)) {
                return $this->writeError(
                    'chmod',
                    $path,
                    $temporaryFile,
                    'Unable to set permissions on configuration.'
                );
            }
            if (!rename($temporaryFile, $path)) {
                return $this->writeError(
                    'rename',
                    $path,
                    $temporaryFile,
                    'Unable to activate configuration file.'
                );
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return array(
            'available' => true,
            'configured' => true,
            'error' => null,
            'path' => $path,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'file' => $this->diagnosePath($path),
        );
    }

    private function ensureDirectory($directory)
    {
        if (is_link($directory)) {
            throw new RuntimeException(
                'Configuration directory cannot be a symlink: ' . $directory
            );
        }
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    'Unable to create configuration directory: ' . $directory
                    . $this->lastErrorSuffix()
                );
            }
            if (!chmod($directory, 0750)) {
                throw new RuntimeException(
                    'Unable to set configuration directory permissions: '
                    . $directory . $this->lastErrorSuffix()
                );
            }
        }
    }

    private function ensureDomainLayout(array $domain)
    {
        $guid = $this->sanitizeIdentifier($domain['guid']);
        $name = $this->sanitizeDomainName($domain['name']);
        $this->ensureDirectoryWithMode($this->stateRoot, 0755);
        $this->ensureDirectoryWithMode($this->stateRoot . '/vhosts', 0755);
        $this->ensureDirectoryWithMode($this->stateRoot . '/vhosts/' . $guid, 0755);
        $this->ensureDirectoryWithMode($this->stateRoot . '/php', 0755);
        $this->ensureDirectoryWithMode($this->stateRoot . '/php/' . $guid, 0750);
        $this->ensureDirectoryWithMode($this->runtimeRoot, 0755);
        $this->ensureDirectoryWithMode($this->runtimeRoot . '/lsphp', 01777);

        $iniDirectory = $this->stateRoot . '/php/' . $guid;
        $cacheDirectory = $this->getCachePath($domain);
        $domainRoot = $this->getDomainRootPath($domain);
        if (!is_dir($domainRoot) || is_link($domainRoot)) {
            throw new RuntimeException(
                'Plesk domain root is unavailable or unsafe: ' . $domainRoot
            );
        }
        $this->ensureDirectoryWithMode($cacheDirectory, 0770);
        if (isset($domain['systemUser'])) {
            if (!chown($iniDirectory, (string) $domain['systemUser'])) {
                throw new RuntimeException(
                    'Unable to set PHP ini directory owner: ' . $iniDirectory
                    . $this->lastErrorSuffix()
                );
            }
            if (!chown($cacheDirectory, (string) $domain['systemUser'])) {
                throw new RuntimeException(
                    'Unable to set LSCache directory owner: ' . $cacheDirectory
                    . $this->lastErrorSuffix()
                );
            }
        }
        if (isset($domain['systemGroup'])) {
            if (!chgrp($iniDirectory, (string) $domain['systemGroup'])) {
                throw new RuntimeException(
                    'Unable to set PHP ini directory group: ' . $iniDirectory
                    . $this->lastErrorSuffix()
                );
            }
            if (!chgrp($cacheDirectory, (string) $domain['systemGroup'])) {
                throw new RuntimeException(
                    'Unable to set LSCache directory group: ' . $cacheDirectory
                    . $this->lastErrorSuffix()
                );
            }
        }
    }

    private function appendLog($event, array $context = array())
    {
        $line = array(
            'ts' => gmdate('c'),
            'event' => $event,
            'context' => $context,
        );
        $result = file_put_contents(
            $this->getLogPath(),
            json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        if (false === $result) {
            throw new RuntimeException(
                'Unable to write debug log at ' . $this->getLogPath()
            );
        }
    }

    private function bootstrapLogFile()
    {
        $logPath = $this->getLogPath();
        if (is_file($logPath) && !is_link($logPath)) {
            return;
        }

        $this->appendLog('bootstrap', array(
            'configRoot' => $this->configRoot,
            'stateRoot' => $this->stateRoot,
            'runtimeRoot' => $this->runtimeRoot,
        ));
    }

    private function ensureLogLayout()
    {
        $this->ensureDirectory($this->stateRoot);
        $this->ensureDirectory($this->stateRoot . '/logs');
        $this->bootstrapLogFile();
    }

    private function ensureDirectoryWithMode($directory, $mode)
    {
        $this->ensureDirectory($directory);
        if (!chmod($directory, $mode)) {
            throw new RuntimeException(
                'Unable to set runtime directory permissions: ' . $directory
                . $this->lastErrorSuffix()
            );
        }
    }

    private function writeError($operation, $path, $temporaryFile, $message)
    {
        return array(
            'available' => false,
            'configured' => false,
            'operation' => $operation,
            'path' => $path,
            'temporaryFile' => $temporaryFile,
            'error' => $message . ' Path: ' . $path . $this->lastErrorSuffix(),
            'identity' => $this->runtimeIdentity(),
            'target' => $this->diagnosePath($path),
            'directory' => $this->diagnosePath(dirname($path)),
        );
    }

    private function lastErrorSuffix()
    {
        $error = error_get_last();
        if (!is_array($error) || empty($error['message'])) {
            return '';
        }
        return ' PHP error: ' . $error['message'];
    }

    private function runtimeIdentity()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $gid = function_exists('posix_getegid') ? posix_getegid() : null;
        $user = null;
        $group = null;
        if (null !== $uid && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid($uid);
            $user = is_array($entry) && isset($entry['name']) ? $entry['name'] : null;
        }
        if (null !== $gid && function_exists('posix_getgrgid')) {
            $entry = posix_getgrgid($gid);
            $group = is_array($entry) && isset($entry['name']) ? $entry['name'] : null;
        }

        return array(
            'uid' => $uid,
            'gid' => $gid,
            'user' => $user,
            'group' => $group,
        );
    }

    private function diagnosePath($path)
    {
        clearstatcache(true, $path);
        $exists = file_exists($path) || is_link($path);
        $permissions = $exists ? fileperms($path) : false;
        $owner = $exists ? fileowner($path) : false;
        $group = $exists ? filegroup($path) : false;

        return array(
            'path' => $path,
            'exists' => $exists,
            'isFile' => is_file($path),
            'isDirectory' => is_dir($path),
            'isLink' => is_link($path),
            'readable' => is_readable($path),
            'writable' => is_writable($path),
            'permissions' => false === $permissions
                ? null
                : substr(sprintf('%o', $permissions), -4),
            'owner' => false === $owner ? null : $owner,
            'group' => false === $group ? null : $group,
            'size' => is_file($path) ? filesize($path) : null,
            'parentWritable' => is_writable(dirname($path)),
        );
    }

    private function mergeDomains(array $domains, array $domain)
    {
        $result = array();
        foreach (array_merge($domains, array($domain)) as $item) {
            if (!is_array($item) || empty($item['guid']) || empty($item['name'])) {
                continue;
            }
            $result[$this->sanitizeIdentifier($item['guid'])] = $item;
        }
        ksort($result);
        return array_values($result);
    }

    private function sanitizeDomainName($value)
    {
        $value = strtolower(rtrim(trim((string) $value), '.'));
        if (!preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $value
        )) {
            throw new InvalidArgumentException('Invalid domain name.');
        }
        return $value;
    }

    private function removeFileIfManaged($path)
    {
        if (!is_string($path) || '' === $path || is_link($path) || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    private function removeEmptyParentDirectories($directory, $stopAt)
    {
        $directory = rtrim((string) $directory, '/');
        $stopAt = rtrim((string) $stopAt, '/');
        while ('' !== $directory
            && 0 === strpos($directory, $stopAt)
            && $directory !== $stopAt
        ) {
            if (!is_dir($directory) || is_link($directory)) {
                break;
            }
            $entries = array_diff(scandir($directory), array('.', '..'));
            if (!empty($entries)) {
                break;
            }
            @rmdir($directory);
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
    }

    private function sanitizeIdentifier($value)
    {
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = trim($value, '-._');
        return '' === $value ? 'default' : $value;
    }

    private function normalizeAccountName($value, $label)
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z_][a-z0-9_-]*[$]?$/', $value)) {
            throw new InvalidArgumentException('Invalid ' . $label . ' name.');
        }

        return $value;
    }
}
