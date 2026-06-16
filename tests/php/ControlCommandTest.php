<?php

require_once __DIR__
    . '/../../extension/plib/library/DesiredStateValidator.php';
require_once __DIR__
    . '/../../extension/plib/library/StateStore.php';
require_once __DIR__
    . '/../../extension/plib/library/EngineInstallPlanner.php';
require_once __DIR__
    . '/../../extension/plib/library/EnginePackageInstaller.php';
require_once __DIR__
    . '/../../extension/plib/library/EnginePlanStore.php';
require_once __DIR__
    . '/../../extension/plib/library/ControlCommand.php';

if (!function_exists('assertSameValue')) {
    function assertSameValue($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
            );
        }
    }
}

class TestEnginePackageInstaller extends Modules_SkamasleOls_EnginePackageInstaller
{
    public function install($packageName = 'openlitespeed', array $options = array())
    {
        $mode = isset($options['mode']) ? (string) $options['mode'] : 'recommended-bootstrap';
        return array(
            'available' => true,
            'installed' => true,
            'mode' => $mode,
            'packageManager' => 'dnf',
            'packageName' => $packageName,
            'repository' => array(
                'available' => true,
                'configured' => true,
                'managedByModule' => true,
                'mode' => $mode,
                'path' => '/etc/yum.repos.d/litespeed.repo',
                'command' => 'wget -O - https://repo.litespeed.sh | bash',
                'exitCode' => 0,
                'output' => 'Repository configured',
                'message' => 'LiteSpeed repository configured.',
            ),
            'exitCode' => 0,
            'output' => '',
            'message' => 'Package installation completed.',
        );
    }
}

class TestEnginePackageRemover extends Modules_SkamasleOls_EnginePackageRemover
{
    public function remove($packageName = 'openlitespeed', array $options = array())
    {
        return array(
            'available' => true,
            'removed' => true,
            'packageManager' => 'dnf',
            'packageName' => $packageName,
            'serviceStop' => array('stopped' => true),
            'repositoryRemoved' => array('removed' => true),
            'exitCode' => 0,
            'output' => '',
            'message' => 'Package removal completed.',
        );
    }
}

class TestOlsConfigManager extends Modules_SkamasleOls_OlsConfigManager
{
    public $logEntries = array();
    public $routingWrites = array();
    public $identitySyncs = array();
    public $exampleIdentitySyncs = array();
    public $cacheModuleSyncs = array();
    public $vhostConfigWrites = array();
    public $lsapiWrites = array();
    public $clearCalls = array();

    public function __construct()
    {
    }

    public function getConfigRoot()
    {
        return '/tmp/config';
    }

    public function getStateRoot()
    {
        return '/usr/local/psa/var/modules/skamasle-ols';
    }

    public function getRuntimeRoot()
    {
        return '/usr/local/psa/var/modules/skamasle-ols/run';
    }

    public function getSocketPath($identifier)
    {
        return '/usr/local/psa/var/modules/skamasle-ols/run/lsphp/sk-'
            . substr(hash('sha256', strtolower(trim($identifier, '{}'))), 0, 24)
            . '.sock';
    }

    public function getDiagnostics()
    {
        return array(
            'identity' => array('uid' => 0, 'gid' => 0, 'user' => 'root', 'group' => 'root'),
            'paths' => array(),
        );
    }

    public function syncIncludeBlock()
    {
        return array('available' => true, 'configured' => true, 'changed' => false);
    }

    public function syncCacheModule()
    {
        $this->cacheModuleSyncs[] = array('configured' => true);

        return array('available' => true, 'configured' => true, 'changed' => false);
    }

    public function syncServerIdentity($user = 'apache', $group = 'apache')
    {
        $this->identitySyncs[] = array(
            'user' => $user,
            'group' => $group,
        );

        return array(
            'available' => true,
            'configured' => true,
            'user' => $user,
            'group' => $group,
            'files' => array(),
        );
    }

    public function syncExampleVhostIdentity($user = 'apache', $group = 'apache')
    {
        $this->exampleIdentitySyncs[] = array(
            'user' => $user,
            'group' => $group,
        );

        return array(
            'available' => true,
            'configured' => true,
            'changed' => true,
            'path' => '/usr/local/lsws/Example/html',
            'user' => $user,
            'group' => $group,
        );
    }

    public function syncListenerSslCertificate()
    {
        return array(
            'available' => true,
            'configured' => true,
            'created' => false,
            'keyFile' => '/tmp/skamasle-ols.key',
            'certFile' => '/tmp/skamasle-ols.crt',
        );
    }

    public function clearDomainArtifacts(
        array $domain,
        array $listener,
        array $remainingDomains = array()
    ) {
        $this->clearCalls[] = array(
            'domain' => $domain['name'],
            'remainingDomains' => array_map(
                function ($item) {
                    return $item['name'];
                },
                $remainingDomains
            ),
        );
        $this->logEntries[] = array(
            'event' => 'clear-domain-artifacts',
            'context' => array(
                'domain' => $domain['name'],
                'remainingDomains' => array_map(
                    function ($item) {
                        return $item['name'];
                    },
                    $remainingDomains
                ),
            ),
        );

        return array(
            'available' => true,
            'configured' => true,
            'listener' => array('available' => true, 'configured' => true),
            'removed' => array('/tmp/example.test.conf'),
        );
    }

    public function syncAdminListener($port = 8070)
    {
        return array(
            'available' => true,
            'configured' => true,
            'changed' => false,
            'address' => '127.0.0.1:' . $port,
        );
    }

    public function writeListener(array $listener, array $domains = array())
    {
        return array(
            'available' => true,
            'configured' => true,
            'path' => '/tmp/listener.conf',
            'listenerSsl' => $this->syncListenerSslCertificate(),
        );
    }

    public function writeVhostConfig(array $domain)
    {
        $this->vhostConfigWrites[] = array(
            'guid' => $domain['guid'],
            'cacheEnabled' => !empty($domain['cacheEnabled']),
        );
        if (isset($domain['php']['lsapi'])) {
            $this->lsapiWrites[] = $domain['php']['lsapi'];
        }

        return array(
            'available' => true,
            'configured' => true,
            'path' => '/tmp/' . $domain['guid'] . '/vhconf.conf',
        );
    }

    public function stageDomain(
        array $domain,
        array $listener,
        array $managedDomains = array()
    )
    {
        return array(
            'available' => true,
            'configured' => true,
            'listener' => array('path' => '/tmp/listener.conf'),
            'vhost' => array(
                'path' => '/tmp/' . $domain['guid'] . '.conf',
            ),
            'logPath' => '/tmp/ols-debug.log',
        );
    }

    public function getListenerPath($port)
    {
        return '/tmp/listener-' . $port . '.conf';
    }

    public function getVhostPath($identifier)
    {
        return '/tmp/' . $identifier . '.conf';
    }

    public function getVhostConfigPath($identifier)
    {
        return '/tmp/' . $identifier . '/vhconf.conf';
    }

    public function getDomainArtifacts($identifier, $port, $domainName = null)
    {
        return array(
            'listener' => array('path' => '/tmp/listener-' . $port . '.conf', 'exists' => true),
            'listenerBackup' => array('path' => '/tmp/listener-' . $port . '.conf0', 'exists' => false),
            'vhost' => array('path' => '/tmp/' . $identifier . '.conf', 'exists' => true),
            'vhostBackup' => array('path' => '/tmp/' . $identifier . '.conf0', 'exists' => false),
            'vhostConfig' => array(
                'path' => '/tmp/' . $identifier . '/vhconf.conf',
                'exists' => true,
            ),
            'routing' => array(
                'path' => '/tmp/' . $identifier . '-routing.conf',
                'exists' => true,
            ),
        );
    }

    public function getRoutingPath($identifier)
    {
        return '/tmp/' . $identifier . '-routing.conf';
    }

    public function writeRoutingConfig(array $domain, array $listener, $routing)
    {
        $this->routingWrites[] = array(
            'domain' => $domain['name'],
            'port' => $listener['port'],
            'routing' => $routing,
        );
        return array(
            'available' => true,
            'configured' => true,
            'path' => $this->getRoutingPath($domain['name']),
        );
    }

    public function logEvent($event, array $context = array())
    {
        $this->logEntries[] = array(
            'event' => $event,
            'context' => $context,
        );
        return '/tmp/ols-debug.log';
    }
}

class TestOlsServiceManager extends Modules_SkamasleOls_OlsServiceManager
{
    public function testConfig()
    {
        return array('exitCode' => 0, 'output' => 'syntax ok', 'valid' => true);
    }

    public function reload($serviceName = 'lsws')
    {
        return array('available' => true, 'reloaded' => true, 'exitCode' => 0, 'output' => '');
    }
}

class TestStateStore extends Modules_SkamasleOls_StateStore
{
    public $failNextWrite = false;

    public function write(array $state, $expectedGeneration)
    {
        if ($this->failNextWrite) {
            $this->failNextWrite = false;
            throw new RuntimeException('Desired state generation conflict.');
        }

        return parent::write($state, $expectedGeneration);
    }
}

class pm_Domain
{
    public static $getAllCalls = 0;
    public static $available = true;

    public static function getAllDomains()
    {
        self::$getAllCalls++;
        if (!self::$available) {
            return array();
        }
        return array(
            new TestPleskDomain(),
        );
    }
}

class TestPleskDomain extends pm_Domain
{
    public static $settings = array();
    public static $phpHandlerId = 'plesk-php83-fpm';
    public static $guid = '{123e4567-e89b-42d3-a456-426614174000}';

    public function getGuid()
    {
        return self::$guid;
    }

    public function getDisplayName()
    {
        return 'example.test';
    }

    public function getName()
    {
        return 'example.test';
    }

    public function getId()
    {
        return 10;
    }

    public function hasHosting()
    {
        return true;
    }

    public function getDocumentRoot()
    {
        return '/var/www/vhosts/example.test/httpdocs';
    }

    public function getSysUserLogin()
    {
        return 'example';
    }

    public function getSysGroupLogin()
    {
        return 'psacln';
    }

    public function getProperty($name)
    {
        if ('php_handler_id' === $name) {
            return self::$phpHandlerId;
        }
        throw new RuntimeException('Unknown property.');
    }

    public function getSetting($name, $default = null)
    {
        return isset(self::$settings[$name]) ? self::$settings[$name] : $default;
    }

    public function setSetting($name, $value)
    {
        self::$settings[$name] = $value;
    }
}

class pm_WebServer
{
    public $updatedDomains = array();

    public function updateDomainConfiguration($domain)
    {
        $this->updatedDomains[] = $domain->getGuid();
        return true;
    }
}

$stateFile = tempnam(sys_get_temp_dir(), 'skamasle-ols-state-');
if (false === $stateFile) {
    throw new RuntimeException('Unable to create the test state file.');
}

try {
    TestPleskDomain::$phpHandlerId = 'plesk-php83-fpm';
    file_put_contents(
        $stateFile,
        json_encode(array(
            'schemaVersion' => 1,
            'generation' => 0,
            'server' => array(
                'defaultRouting' => 'native',
                'listener' => array(
                    'bindAddress' => '127.0.0.1',
                    'port' => 7088,
                    'protocol' => 'http',
                ),
            ),
            'domains' => array(),
        ))
    );
    $stateStore = new TestStateStore(
        $stateFile,
        new Modules_SkamasleOls_DesiredStateValidator()
    );
    $configManager = new TestOlsConfigManager();
    $command = new Modules_SkamasleOls_ControlCommand(
        $stateStore,
        null,
        new TestEnginePackageInstaller(),
        null,
        new TestEnginePackageRemover(),
        $configManager,
        new TestOlsServiceManager()
    );

    $status = $command->run(array('status'));
    assertSameValue(0, $status['exitCode'], 'Status must succeed');
    assertSameValue(0, $status['payload']['domainCount'], 'State must be empty');

    $validate = $command->run(array('validate'));
    assertSameValue(0, $validate['exitCode'], 'Validation must succeed');

    $install = $command->run(array('install-engine'));
    assertSameValue(0, $install['exitCode'], 'Install plan must succeed');
    assertSameValue(
        '127.0.0.1',
        $install['payload']['listener']['bindAddress'],
        'Install plan must expose the listener'
    );
    assertSameValue(
        dirname($stateFile) . '/install-engine-plan.json',
        $install['payload']['planFile'],
        'Install plan must expose the plan file'
    );
    assertSameValue(
        true,
        is_file(dirname($stateFile) . '/install-engine-plan.json'),
        'Install receipt must be persisted'
    );
    assertSameValue(
        true,
        $install['payload']['engine']['packageResult']['repository']['configured'],
        'Install command must record repository configuration'
    );
    assertSameValue(
        array(array('user' => 'apache', 'group' => 'apache')),
        $configManager->identitySyncs,
        'Install command must normalize the OLS server identity'
    );
    assertSameValue(
        array(array('user' => 'apache', 'group' => 'apache')),
        $configManager->exampleIdentitySyncs,
        'Install command must normalize the OLS Example/html ownership'
    );
    assertSameValue(
        1,
        count($configManager->cacheModuleSyncs),
        'Install command must register the LSCache module'
    );
    assertSameValue(
        'installed',
        $install['payload']['engine']['status'],
        'Install command must report installed status'
    );
    $existingInstall = $command->run(array(
        'install-engine',
        json_encode(array('mode' => 'already-installed')),
    ));
    assertSameValue(0, $existingInstall['exitCode'], 'Existing installation mode must succeed');
    assertSameValue(
        'already-installed',
        $existingInstall['payload']['engine']['provisioning']['mode'],
        'Install command must persist the selected provisioning mode'
    );

    $portUpdate = $command->run(array('set-listener-port', '7090'));
    assertSameValue(0, $portUpdate['exitCode'], 'Port update must succeed');
    assertSameValue(
        7090,
        $portUpdate['payload']['listener']['port'],
        'Port update must be reflected in the payload'
    );

    $uninstall = $command->run(array('uninstall-engine'));
    assertSameValue(0, $uninstall['exitCode'], 'Uninstall must succeed');
    assertSameValue(
        'removed',
        $uninstall['payload']['engine']['status'],
        'Uninstall must report removed status'
    );

    $prepare = $command->run(array('prepare-domain-vhost', '{123e4567-e89b-42d3-a456-426614174000}'));
    assertSameValue(0, $prepare['exitCode'], 'Vhost preparation must succeed');
    assertSameValue(
        'example.test.conf',
        basename($prepare['payload']['vhost']['path']),
        'Vhost preparation must report the staged path'
    );
    assertSameValue(
        'prepare-domain-vhost.begin',
        $configManager->logEntries[0]['event'],
        'Prepare command must log its start'
    );
    assertSameValue(
        'prepare-domain-vhost.inventory',
        $configManager->logEntries[1]['event'],
        'Prepare command must log the available domains'
    );
    assertSameValue(
        'prepare-domain-vhost.done',
        $configManager->logEntries[count($configManager->logEntries) - 1]['event'],
        'Prepare command must log its completion'
    );
    $preparedState = json_decode((string) file_get_contents($stateFile), true);
    assertSameValue(
        'plesk-php83-fpm',
        $preparedState['domains'][0]['php']['pleskHandlerId'],
        'Initial preparation must persist the current Plesk PHP handler'
    );
    assertSameValue(
        '8.3',
        $preparedState['domains'][0]['php']['version'],
        'Initial preparation must persist the resolved PHP version'
    );

    TestPleskDomain::$phpHandlerId = 'plesk-php84-fpm';
    $prepareAfterPhpChange = $command->run(array(
        'prepare-domain-vhost',
        '{123e4567-e89b-42d3-a456-426614174000}',
    ));
    assertSameValue(
        0,
        $prepareAfterPhpChange['exitCode'],
        'Preparation after a PHP handler change must succeed'
    );
    $updatedState = json_decode((string) file_get_contents($stateFile), true);
    assertSameValue(
        'plesk-php84-fpm',
        $updatedState['domains'][0]['php']['pleskHandlerId'],
        'Preparation must refresh the cached Plesk PHP handler from live domain data'
    );
    assertSameValue(
        '8.4',
        $updatedState['domains'][0]['php']['version'],
        'Preparation must refresh the cached PHP version after a handler change'
    );
    assertSameValue(
        '/opt/plesk/php/8.4/bin/lsphp',
        $updatedState['domains'][0]['php']['lsphpBinary'],
        'Preparation must refresh the lsphp binary path after a handler change'
    );

    $reset = $command->run(array('reset-domain-vhost', '{123e4567-e89b-42d3-a456-426614174000}'));
    assertSameValue(0, $reset['exitCode'], 'Vhost reset must succeed');
    assertSameValue(
        'native',
        $reset['payload']['domain']['appliedRouting'],
        'Reset command must return the domain to native routing'
    );
    assertSameValue(
        'clear-domain-artifacts',
        $configManager->logEntries[count($configManager->logEntries) - 1]['event'],
        'Reset command must clear the staged artifacts'
    );

    $payloadPrepare = $command->run(array(
        'prepare-domain-vhost',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode(array(
            'guid' => '123e4567-e89b-42d3-a456-426614174000',
            'pleskId' => 10,
            'name' => 'example.test',
            'documentRoot' => '/var/www/vhosts/example.test/httpdocs',
            'vhostRoot' => '/var/www/vhosts/example.test',
            'systemUser' => 'example',
            'systemGroup' => 'psacln',
            'phpHandlerId' => 'plesk-php83-fpm',
            'phpVersion' => '8.3',
        )),
    ));
    assertSameValue(0, $payloadPrepare['exitCode'], 'Payload vhost preparation must succeed');
    $payloadBegin = null;
    foreach (array_reverse($configManager->logEntries) as $entry) {
        if ('prepare-domain-vhost.begin' === $entry['event']) {
            $payloadBegin = $entry;
            break;
        }
    }
    assertSameValue(
        true,
        null !== $payloadBegin && !empty($payloadBegin['context']['payloadProvided']),
        'Prepare command must record that the payload was provided'
    );
    $invalidPayloadJson = $command->run(array(
        'prepare-domain-vhost',
        '{123e4567-e89b-42d3-a456-426614174000}',
        '{invalid',
    ));
    assertSameValue(64, $invalidPayloadJson['exitCode'], 'Invalid payload JSON must fail');
    assertSameValue(
        'prepare-domain-vhost.invalid-arguments',
        $configManager->logEntries[count($configManager->logEntries) - 1]['event'],
        'Invalid payload JSON must be logged'
    );
    $routing = $command->run(array(
        'set-domain-routing',
        '{123e4567-e89b-42d3-a456-426614174000}',
        'ols',
    ));
    assertSameValue(0, $routing['exitCode'], 'OLS routing activation must succeed');
    assertSameValue(
        'ols',
        $routing['payload']['domain']['appliedRouting'],
        'Applied routing must be persisted'
    );
    assertSameValue(
        true,
        isset($routing['payload']['routingConfig']['configured'])
            && $routing['payload']['routingConfig']['configured'],
        'Routing config must be written alongside routing state'
    );
    $domainLookupsBeforeCache = pm_Domain::$getAllCalls;
    $cache = $command->run(array(
        'set-domain-cache',
        '{123e4567-e89b-42d3-a456-426614174000}',
        '1',
    ));
    assertSameValue(0, $cache['exitCode'], 'LSCache toggle must succeed');
    assertSameValue(
        true,
        $cache['payload']['cacheEnabled'],
        'Cache toggle must be reflected in the payload'
    );
    assertSameValue(
        array(
            'guid' => '123e4567-e89b-42d3-a456-426614174000',
            'cacheEnabled' => true,
        ),
        $configManager->vhostConfigWrites[
            count($configManager->vhostConfigWrites) - 1
        ],
        'Cache toggle must rewrite the vhost config with cache enabled'
    );
    assertSameValue(
        'set-domain-cache.done',
        $configManager->logEntries[count($configManager->logEntries) - 1]['event'],
        'Cache toggle must log its completion'
    );
    assertSameValue(
        $domainLookupsBeforeCache + 1,
        pm_Domain::$getAllCalls,
        'Privileged cache command must refresh the domain from Plesk'
    );
    $lsapiSettings = array(
        'maxConnections' => 32,
        'instances' => 2,
        'backlog' => 100,
        'initTimeout' => 75,
        'retryTimeout' => 3,
        'persistentConnection' => false,
        'responseBuffering' => true,
    );
    $expectedLsapiSettings = array(
        'maxConnections' => 32,
        'children' => 32,
        'instances' => 2,
        'backlog' => 100,
        'initTimeout' => 75,
        'retryTimeout' => 3,
        'persistentConnection' => false,
        'responseBuffering' => true,
    );
    $lsapi = $command->run(array(
        'set-domain-lsapi',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode($lsapiSettings),
    ));
    assertSameValue(0, $lsapi['exitCode'], 'LSAPI update must succeed');
    assertSameValue(
        $expectedLsapiSettings,
        $lsapi['payload']['lsapi'],
        'LSAPI update must return normalized settings'
    );
    assertSameValue(
        $expectedLsapiSettings,
        $configManager->lsapiWrites[count($configManager->lsapiWrites) - 1],
        'LSAPI update must rewrite the vhost config'
    );
    $stateAfterLsapi = json_decode((string) file_get_contents($stateFile), true);
    assertSameValue(
        $expectedLsapiSettings,
        $stateAfterLsapi['domains'][0]['php']['lsapi'],
        'LSAPI update must persist settings in desired state'
    );
    assertSameValue(
        'set-domain-lsapi.done',
        $configManager->logEntries[count($configManager->logEntries) - 1]['event'],
        'LSAPI update must log its completion'
    );
    $lsapiDomainPayload = array(
        'guid' => '123e4567-e89b-42d3-a456-426614174000',
        'pleskId' => 10,
        'name' => 'example.test',
        'documentRoot' => '/var/www/vhosts/example.test/httpdocs',
        'vhostRoot' => '/var/www/vhosts/example.test',
        'systemUser' => 'example',
        'systemGroup' => 'psacln',
        'phpHandlerId' => 'plesk-php84-fpm',
        'phpVersion' => '8.4',
        'cacheEnabled' => true,
        'requestedRouting' => 'ols',
    );
    $clearCallsBeforePayloadUpdate = count($configManager->clearCalls);
    pm_Domain::$available = false;
    $lsapiFromPayload = $command->run(array(
        'set-domain-lsapi',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode($lsapiSettings),
        'example.test',
        json_encode($lsapiDomainPayload),
    ));
    pm_Domain::$available = true;
    assertSameValue(
        0,
        $lsapiFromPayload['exitCode'],
        'LSAPI update must use the controller payload when pm_Domain is unavailable'
    );
    assertSameValue(
        $clearCallsBeforePayloadUpdate,
        count($configManager->clearCalls),
        'LSAPI payload update must not clear an existing domain'
    );
    $stateStore->failNextWrite = true;
    $lsapiConflictRetry = $command->run(array(
        'set-domain-lsapi',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode($lsapiSettings),
        'example.test',
        json_encode($lsapiDomainPayload),
    ));
    assertSameValue(
        0,
        $lsapiConflictRetry['exitCode'],
        'LSAPI update must retry after a desired-state generation conflict'
    );
    $clearCallsBeforeNameFallback = count($configManager->clearCalls);
    $originalGuid = TestPleskDomain::$guid;
    TestPleskDomain::$guid = '{fedcba98-7654-4a10-8edc-ba9876543210}';
    $lsapiByName = $command->run(array(
        'set-domain-lsapi',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode($lsapiSettings),
        'example.test',
    ));
    assertSameValue(
        0,
        $lsapiByName['exitCode'],
        'LSAPI update must resolve a live domain by name when the GUID changed'
    );
    assertSameValue(
        $clearCallsBeforeNameFallback,
        count($configManager->clearCalls),
        'Name fallback must not clear stale artifacts for an existing domain'
    );
    TestPleskDomain::$guid = $originalGuid;
    $invalidLsapi = $command->run(array(
        'set-domain-lsapi',
        '{123e4567-e89b-42d3-a456-426614174000}',
        json_encode($lsapiSettings + array('unknown' => 1)),
    ));
    assertSameValue(64, $invalidLsapi['exitCode'], 'Unknown LSAPI settings must fail');

    assertSameValue(
        3,
        count($configManager->cacheModuleSyncs),
        'Cache toggle must keep the server cache module registered'
    );
    $portUpdateWithOls = $command->run(array('set-listener-port', '7091'));
    assertSameValue(
        0,
        $portUpdateWithOls['exitCode'],
        'Listener port update with an active OLS domain must succeed'
    );
    $lastRoutingWrite = $configManager->routingWrites[
        count($configManager->routingWrites) - 1
    ];
    assertSameValue(
        array(
            'domain' => 'example.test',
            'port' => 7091,
            'routing' => 'ols',
        ),
        $lastRoutingWrite,
        'Listener port update must rewrite the active domain routing config'
    );

    pm_Domain::$available = false;
    $clearCallsBeforeStaleLookup = count($configManager->clearCalls);
    $staleCache = $command->run(array(
        'set-domain-cache',
        '{123e4567-e89b-42d3-a456-426614174000}',
        '0',
    ));
    assertSameValue(
        0,
        $staleCache['exitCode'],
        'A missing Plesk domain with local state must still be handled'
    );
    assertSameValue(
        $clearCallsBeforeStaleLookup,
        count($configManager->clearCalls),
        'A missing Plesk domain with local state must not clear artifacts'
    );
    $stateAfterCleanup = json_decode((string) file_get_contents($stateFile), true);
    assertSameValue(
        1,
        count($stateAfterCleanup['domains']),
        'Existing desired state must be preserved when Plesk lookup is unavailable'
    );
    $cacheDomainPayload = array(
        'guid' => '123e4567-e89b-42d3-a456-426614174000',
        'pleskId' => 10,
        'name' => 'example.test',
        'documentRoot' => '/var/www/vhosts/example.test/httpdocs',
        'vhostRoot' => '/var/www/vhosts/example.test',
        'systemUser' => 'example',
        'systemGroup' => 'psacln',
        'phpHandlerId' => 'plesk-php84-fpm',
        'phpVersion' => '8.4',
        'cacheEnabled' => false,
        'requestedRouting' => 'ols',
    );
    $clearCallsBeforePayloadCache = count($configManager->clearCalls);
    $payloadCache = $command->run(array(
        'set-domain-cache',
        '{123e4567-e89b-42d3-a456-426614174000}',
        '1',
        'example.test',
        json_encode($cacheDomainPayload),
    ));
    assertSameValue(
        0,
        $payloadCache['exitCode'],
        'LSCache toggle must use the controller payload when Plesk lookup is unavailable'
    );
    assertSameValue(
        $clearCallsBeforePayloadCache,
        count($configManager->clearCalls),
        'LSCache payload toggle must not clear artifacts for an existing domain'
    );
    pm_Domain::$available = true;

    $unknown = $command->run(array('shell'));
    assertSameValue(64, $unknown['exitCode'], 'Unknown command must be rejected');

    $invalidArguments = $command->run(array('status', '--verbose'));
    assertSameValue(
        64,
        $invalidArguments['exitCode'],
        'Unexpected arguments must be rejected'
    );

    $parser = new ReflectionMethod(
        Modules_SkamasleOls_ControlCommand::class,
        'parsePhpHandlerId'
    );
    $parser->setAccessible(true);
    assertSameValue(
        'plesk-php83-fpm',
        $parser->invoke(
            $command,
            "PHP support: true\nPHP handler id: plesk-php83-fpm\n"
        ),
        'Site info output must expose the PHP handler ID'
    );
    $phpSupportParser = new ReflectionMethod(
        Modules_SkamasleOls_ControlCommand::class,
        'siteInfoHasPhpSupport'
    );
    $phpSupportParser->setAccessible(true);
    assertSameValue(
        true,
        $phpSupportParser->invoke($command, "PHP support:                            Yes\n"),
        'Site info must confirm PHP support'
    );
    $sitePathParser = new ReflectionMethod(
        Modules_SkamasleOls_ControlCommand::class,
        'parseSiteInfoPaths'
    );
    $sitePathParser->setAccessible(true);
    $sitePaths = $sitePathParser->invoke(
        $command,
        "Webspace root: /srv/plesk/example.test\n"
        . "WWW-Root: /srv/plesk/example.test/httpdocs\n"
    );
    assertSameValue(
        '/srv/plesk/example.test',
        $sitePaths['vhostRoot'],
        'Site info must expose the Plesk vhost root'
    );
    assertSameValue(
        '/srv/plesk/example.test/httpdocs',
        $sitePaths['documentRoot'],
        'Site info must expose the Plesk document root'
    );
    $runtimeParser = new ReflectionMethod(
        Modules_SkamasleOls_ControlCommand::class,
        'parsePhpRuntimeRow'
    );
    $runtimeParser->setAccessible(true);
    $runtime = $runtimeParser->invoke(
        $command,
        "plesk-php74-fpm\tsysuser_6"
    );
    assertSameValue(
        'plesk-php74-fpm',
        $runtime['handlerId'],
        'Plesk DB output must expose the handler ID'
    );
    assertSameValue(
        'sysuser_6',
        $runtime['systemUser'],
        'Plesk DB output must expose the system user'
    );

    $missingDomain = $command->run(array(
        'prepare-domain-vhost',
        '{11111111-1111-4111-8111-111111111111}',
    ));
    assertSameValue(2, $missingDomain['exitCode'], 'Missing domain must fail');
    assertSameValue(
        true,
        isset($missingDomain['payload']['availableDomains']),
        'Missing domain must report available domains'
    );

    file_put_contents($stateFile, '{"schemaVersion":2}');
    $invalidState = $command->run(array('validate'));
    assertSameValue(2, $invalidState['exitCode'], 'Invalid state must fail');
} finally {
    unlink($stateFile);
}
