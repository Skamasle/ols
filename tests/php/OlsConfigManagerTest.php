<?php

require_once __DIR__
    . '/../../extension/plib/library/OlsConfigManager.php';

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

$root = sys_get_temp_dir() . '/skamasle-ols-config-' . bin2hex(random_bytes(6));
$serverRoot = $root . '/server';
$configRoot = $root . '/conf/skamasle-ols';
$stateRoot = $root . '/state';
$runtimeRoot = $root . '/run';
$hostingRoot = $root . '/hosting';
mkdir($serverRoot, 0700, true);
mkdir($root . '/admin/conf', 0700, true);
mkdir($configRoot, 0700, true);
mkdir($configRoot . '/listeners', 0700, true);
mkdir($configRoot . '/vhosts', 0700, true);
mkdir($hostingRoot . '/example.test', 0700, true);
file_put_contents(
    $serverRoot . '/httpd_config.conf',
    "disableWebAdmin 0\nserver {\n}\n"
);
file_put_contents(
    $root . '/admin/conf/admin_config.conf',
    "listener adminListener{\n  address *:7080\n  secure 1\n}\n"
);

$manager = new Modules_SkamasleOls_OlsConfigManager(
    $serverRoot,
    $configRoot,
    $stateRoot,
    $runtimeRoot
);
$currentUser = get_current_user();
$currentGroup = 'psacln';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $userEntry = posix_getpwuid(posix_geteuid());
    if (is_array($userEntry) && isset($userEntry['name'])) {
        $currentUser = $userEntry['name'];
    }
}
if (function_exists('posix_getegid') && function_exists('posix_getgrgid')) {
    $groupEntry = posix_getgrgid(posix_getegid());
    if (is_array($groupEntry) && isset($groupEntry['name'])) {
        $currentGroup = $groupEntry['name'];
    }
}

try {
    $include = $manager->syncIncludeBlock();
    assertSameValue(true, $include['configured'], 'Include block must be written');
    assertSameValue(
        true,
        is_file($manager->getLogPath()),
        'Bootstrap log must be created during layout preparation'
    );
    $admin = $manager->syncAdminListener(8070);
    assertSameValue(true, $admin['configured'], 'Admin listener must be updated');
    assertSameValue(
        true,
        false !== strpos(
            file_get_contents($manager->getAdminConfigPath()),
            'address 127.0.0.1:8070'
        ),
        'Admin listener must use loopback port 8070'
    );
    $cacheModule = $manager->syncCacheModule();
    assertSameValue(true, $cacheModule['configured'], 'Cache module must be registered');
    assertSameValue(
        true,
        false !== strpos(
            file_get_contents($manager->getHttpdConfigPath()),
            'module cache {'
        ),
        'Server config must include the cache module block'
    );
    $serverConfig = file_get_contents($manager->getHttpdConfigPath());
    assertSameValue(
        true,
        false !== strpos($serverConfig, 'checkPrivateCache   0'),
        'Server cache registration must not query private cache'
    );
    assertSameValue(
        true,
        false !== strpos($serverConfig, 'respCookieCache     0'),
        'Server cache registration must reject responses that set cookies'
    );

    $domain = array(
        'name' => 'example.test',
        'guid' => '123e4567-e89b-42d3-a456-426614174000',
        'aliases' => array('alias.example.test'),
        'documentRoot' => '/var/www/vhosts/example.test/httpdocs',
        'vhostRoot' => $hostingRoot . '/example.test',
        'systemUser' => $currentUser,
        'systemGroup' => $currentGroup,
        'php' => array(
            'lsphpBinary' => '/opt/plesk/php/8.3/bin/lsphp',
            'socket' => $manager->getSocketPath(
                '123e4567-e89b-42d3-a456-426614174000'
            ),
        ),
    );
    $listener = $manager->writeListener(
        array(
            'bindAddress' => '127.0.0.1',
            'port' => 7089,
            'protocol' => 'http',
        ),
        array($domain)
    );
    assertSameValue(
        $configRoot . '/listeners/listener-7089.conf',
        $listener['path'],
        'Listener path must match port'
    );
    assertSameValue(
        true,
        $listener['file']['exists'],
        'Listener result must include filesystem diagnostics'
    );

    $stage = $manager->stageDomain(
        $domain,
        array(
            'bindAddress' => '127.0.0.1',
            'port' => 7089,
            'protocol' => 'http',
        )
    );
    $vhost = $stage['vhost'];
    assertSameValue(
        $configRoot . '/vhosts/example.test.conf',
        $vhost['path'],
        'Vhost path must be based on the domain name'
    );
    $listenerContent = file_get_contents($listener['path']);
    assertSameValue(
        true,
        false !== strpos(
            $listenerContent,
            'map example.test example.test,alias.example.test,www.example.test'
        ),
        'Listener must map the vhost domains'
    );
    assertSameValue(
        true,
        false !== strpos($listenerContent, 'secure 1'),
        'Listener must be configured for HTTPS'
    );
    assertSameValue(
        true,
        false !== strpos(
            $listenerContent,
            'keyFile                 ' . $manager->getListenerSslKeyPath()
        ),
        'Listener must reference the global SSL key'
    );
    assertSameValue(
        true,
        false !== strpos(
            $listenerContent,
            'certFile                ' . $manager->getListenerSslCertPath()
        ),
        'Listener must reference the global SSL certificate'
    );
    assertSameValue(
        true,
        is_file($manager->getListenerSslKeyPath()),
        'Listener SSL key must be generated'
    );
    assertSameValue(
        true,
        is_file($manager->getListenerSslCertPath()),
        'Listener SSL certificate must be generated'
    );
    $vhostContent = file_get_contents($vhost['path']);
    assertSameValue(
        true,
        false !== strpos(
            $vhostContent,
            'vhRoot ' . $hostingRoot . '/example.test/'
        ),
        'Server vhost declaration must use the Plesk domain root'
    );
    assertSameValue(
        true,
        false !== strpos(
            $vhostContent,
            'configFile ' . $stateRoot . '/vhosts/'
        ),
        'Server vhost declaration must reference its config file'
    );
    assertSameValue(
        true,
        false !== strpos($vhostContent, 'restrained 0'),
        'Vhost must allow its external Plesk document root'
    );
    assertSameValue(
        true,
        false !== strpos($vhostContent, 'setUIDMode 2'),
        'Vhost must run with per-vhost UID mode so lsphp uses the domain account'
    );
    $vhconfContent = file_get_contents($stage['vhostConfig']['path']);
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'add lsapi:lsphp php'),
        'Vhost config must register the PHP script handler'
    );
    assertSameValue(
        true,
        false !== strpos(
            $vhconfContent,
            'path /opt/plesk/php/8.3/bin/lsphp'
        ),
        'Vhost config must launch the selected Plesk LSPHP binary'
    );
    assertSameValue(
        true,
        false !== strpos(
            $vhconfContent,
            'address uds://' . ltrim(
                $manager->getSocketPath('123e4567-e89b-42d3-a456-426614174000'),
                '/'
            )
        ),
        'Vhost config must use the documented UDS socket syntax'
    );
    assertSameValue(
        true,
        false !== strpos(
            $vhconfContent,
            'env PHPRC=/var/www/vhosts/system/example.test/etc'
        ),
        'Vhost config must use the Plesk php.ini directory'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'module cache {'),
        'Vhost config must include the cache module block'
    );
    assertSameValue(
        true,
        false !== strpos(
            $vhconfContent,
            'storagePath ' . $hostingRoot . '/example.test/lscache'
        ),
        'Vhost cache must be stored below the Plesk domain root'
    );
    assertSameValue(
        true,
        is_dir($hostingRoot . '/example.test/lscache'),
        'Vhost staging must create the LSCache directory'
    );
    assertSameValue(
        '0770',
        substr(
            sprintf(
                '%o',
                fileperms($hostingRoot . '/example.test/lscache')
            ),
            -4
        ),
        'LSCache directory must use private group-writable permissions'
    );
    assertSameValue(
        fileowner($hostingRoot . '/example.test/lscache'),
        fileowner($stateRoot . '/php/123e4567-e89b-42d3-a456-426614174000'),
        'LSCache and PHP runtime directories must use the domain owner'
    );
    assertSameValue(
        filegroup($hostingRoot . '/example.test/lscache'),
        filegroup($stateRoot . '/php/123e4567-e89b-42d3-a456-426614174000'),
        'LSCache and PHP runtime directories must use the domain group'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'enableCache         0'),
        'Vhost cache must default to disabled'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'checkPrivateCache   0'),
        'Vhost must not query private cache by default'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'checkPublicCache    1'),
        'Vhost must query public cache'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'enablePrivateCache  0'),
        'Vhost private cache must remain disabled'
    );
    assertSameValue(
        true,
        false !== strpos($vhconfContent, 'respCookieCache     0'),
        'Responses that set cookies must not enter public cache by default'
    );
    $enabledVhost = $manager->writeVhostConfig($domain + array('cacheEnabled' => true));
    assertSameValue(true, $enabledVhost['available'], 'Vhost cache rewrite must succeed');
    assertSameValue(
        true,
        false !== strpos(
            file_get_contents($enabledVhost['path']),
            'enableCache         1'
        ),
        'Vhost cache must be enabled when requested'
    );
    $routingContent = file_get_contents($stage['routing']['path']);
    assertSameValue(
        true,
        false !== strpos($routingContent, '# routing native'),
        'Routing runtime config must default to native mode'
    );
    $olsRouting = $manager->writeRoutingConfig(
        $domain,
        array(
            'bindAddress' => '127.0.0.1',
            'port' => 7090,
            'protocol' => 'http',
        ),
        'ols'
    );
    assertSameValue(
        true,
        false !== strpos(
            file_get_contents($olsRouting['path']),
            'set $skamasle_ols_proxy_port 7090;'
        ),
        'OLS routing config must override only the configured proxy port'
    );
    assertSameValue(
        $stateRoot . '/nginx-routing/example.test.conf',
        $olsRouting['path'],
        'OLS routing config must use the domain name expected by the nginx template'
    );
    $cleanup = $manager->clearDomainArtifacts(
        $domain,
        array(
            'bindAddress' => '127.0.0.1',
            'port' => 7089,
            'protocol' => 'http',
        ),
        array()
    );
    assertSameValue(
        true,
        $cleanup['configured'],
        'Domain cleanup must succeed'
    );
    assertSameValue(
        false,
        is_file($vhost['path']),
        'Cleanup must remove the staged vhost file'
    );
    assertSameValue(
        false,
        is_file($stage['vhostConfig']['path']),
        'Cleanup must remove the staged vhost config file'
    );
    assertSameValue(
        false,
        is_file($olsRouting['path']),
        'Cleanup must remove the OLS routing file'
    );
    assertSameValue(
        true,
        false === strpos(file_get_contents($listener['path']), 'map example.test'),
        'Cleanup must rewrite the listener without the staged domain'
    );
    assertSameValue(
        true,
        is_file($manager->getLogPath()),
        'Stage debug log must be created'
    );
    $logContent = file_get_contents($manager->getLogPath());
    assertSameValue(
        true,
        false !== strpos($logContent, 'stage-domain.begin'),
        'Stage debug log must include the begin event'
    );
    assertSameValue(
        true,
        false !== strpos($logContent, 'stage-domain.done'),
        'Stage debug log must include the completion event'
    );
    assertSameValue(
        true,
        is_dir($runtimeRoot . '/lsphp'),
        'LSAPI runtime directory must be created'
    );
    assertSameValue(
        true,
        is_dir($stateRoot),
        'State root must be created during staging'
    );
    $diagnostics = $manager->getDiagnostics();
    assertSameValue(
        true,
        isset($diagnostics['identity']['uid']),
        'Diagnostics must include the effective identity'
    );
    assertSameValue(
        true,
        $diagnostics['paths']['configRoot']['writable'],
        'Diagnostics must report configuration directory writability'
    );
    $artifacts = $manager->getDomainArtifacts($domain['guid'], 7089, $domain['name']);
    assertSameValue(
        false,
        $artifacts['vhost']['exists'],
        'Artifact diagnostics must confirm cleanup removed the generated vhost'
    );
    assertSameValue(
        false,
        $artifacts['vhostBackup']['exists'],
        'Artifact diagnostics must distinguish optional OLS backup files'
    );
    assertSameValue(
        false,
        $artifacts['routing']['exists'],
        'Artifact diagnostics must confirm cleanup removed the routing runtime file'
    );
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
