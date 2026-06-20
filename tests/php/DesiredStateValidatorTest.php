<?php

require_once __DIR__
    . '/../../extension/plib/library/DesiredStateValidator.php';

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

function assertInvalidState(array $state, $expectedMessage)
{
    $validator = new Modules_SkamasleOls_DesiredStateValidator();
    try {
        $validator->validate($state);
    } catch (InvalidArgumentException $exception) {
        if (false === strpos($exception->getMessage(), $expectedMessage)) {
            throw new RuntimeException(
                'Unexpected validation error: ' . $exception->getMessage()
            );
        }
        return;
    }

    throw new RuntimeException('Expected state validation to fail.');
}

function validDesiredState()
{
    $guid = '123e4567-e89b-42d3-a456-426614174000';
    $stateRoot = '/usr/local/psa/var/modules/skamasle-ols';

    return array(
                'schemaVersion' => 1,
                'generation' => 7,
                'server' => array(
                    'defaultRouting' => 'native',
                    'listener' => array(
                        'bindAddress' => '127.0.0.1',
                        'port' => 7088,
                        'protocol' => 'http',
                    ),
                ),
        'domains' => array(
            array(
                'guid' => $guid,
                'pleskId' => 12,
                'name' => 'example.test',
                'aliases' => array('www.example.test'),
                'documentRoot' => '/var/www/vhosts/example.test/httpdocs',
                'vhostRoot' => '/var/www/vhosts/example.test',
                'systemUser' => 'example',
                'systemGroup' => 'psacln',
                'nativeProfile' => array(
                    'webMode' => 'proxy',
                    'proxyMode' => true,
                    'phpHandlerId' => 'plesk-php83-fpm',
                ),
                'php' => array(
                    'pleskHandlerId' => 'plesk-php83-fpm',
                    'version' => '8.3',
                    'lsphpBinary' => '/opt/plesk/php/8.3/bin/lsphp',
                    'socket' => '/usr/local/psa/var/modules/skamasle-ols/run/lsphp/sk-'
                        . substr(hash('sha256', $guid), 0, 24) . '.sock',
                ),
                'requestedRouting' => 'native',
                'appliedRouting' => 'native',
            ),
        ),
    );
}

$validator = new Modules_SkamasleOls_DesiredStateValidator();
$emptyState = array(
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
);
$validator->validate($emptyState);
$decoded = $validator->decodeAndValidate(json_encode(validDesiredState()));
assertSameValue(1, count($decoded['domains']), 'Valid domain must parse');

$dottedUser = validDesiredState();
$dottedUser['domains'][0]['systemUser'] = 'analyzer.adw.es_t3ffr7y4qak';
$validator->validate($dottedUser);

$lsapiState = validDesiredState();
$lsapiState['domains'][0]['php']['lsapi'] = array(
    'maxConnections' => 20,
    'children' => 16,
    'instances' => 2,
    'backlog' => 200,
    'initTimeout' => 90,
    'retryTimeout' => 5,
    'persistentConnection' => true,
    'responseBuffering' => false,
);
$validator->validate($lsapiState);

$privateCacheState = validDesiredState();
$privateCacheState['domains'][0]['cacheEnabled'] = true;
$privateCacheState['domains'][0]['cachePrivateEnabled'] = true;
$validator->validate($privateCacheState);

$invalidLsapiState = $lsapiState;
$invalidLsapiState['domains'][0]['php']['lsapi']['children'] = 0;
assertInvalidState($invalidLsapiState, 'children must be an integer');

$invalidPrivateCacheState = validDesiredState();
$invalidPrivateCacheState['domains'][0]['cachePrivateEnabled'] = true;
assertInvalidState($invalidPrivateCacheState, 'cachePrivateEnabled requires cacheEnabled');

$unknownProperty = validDesiredState();
$unknownProperty['unexpected'] = true;
assertInvalidState($unknownProperty, 'missing or unknown properties');

$pathTraversal = validDesiredState();
$pathTraversal['domains'][0]['documentRoot'] =
    '/var/www/vhosts/example.test/../other';
assertInvalidState($pathTraversal, 'not a safe absolute path');

$outsideVhost = validDesiredState();
$outsideVhost['domains'][0]['documentRoot'] = '/srv/other/httpdocs';
assertInvalidState($outsideVhost, 'outside its vhost root');

$inconsistentMode = validDesiredState();
$inconsistentMode['domains'][0]['nativeProfile']['proxyMode'] = false;
assertInvalidState($inconsistentMode, 'web mode is inconsistent');

$wrongSocket = validDesiredState();
$wrongSocket['domains'][0]['php']['socket'] =
    '/usr/local/psa/var/modules/skamasle-ols/run/lsphp/other.sock';
assertInvalidState($wrongSocket, 'does not match the domain GUID');

$duplicateAlias = validDesiredState();
$duplicateAlias['domains'][0]['aliases'][] = 'example.test';
assertInvalidState($duplicateAlias, 'is duplicated');

try {
    $validator->decodeAndValidate(
        '{"schemaVersion":1,"generation":0,"server":'
        . '{"defaultRouting":"native"},"domains":{}}'
    );
    throw new RuntimeException('Object-shaped domains must be rejected.');
} catch (InvalidArgumentException $exception) {
    assertSameValue(
        true,
        false !== strpos($exception->getMessage(), 'JSON shape'),
        'Invalid JSON shape must be reported'
    );
}
