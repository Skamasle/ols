<?php

if (!class_exists('pm_ApiCli')) {
class pm_ApiCli
{
    const RESULT_FULL = 1;

    public static function callSbin($command, array $arguments, $resultMode)
    {
        if ('skamasle-olsctl' !== $command || self::RESULT_FULL !== $resultMode) {
            throw new RuntimeException('Unexpected installer call.');
        }

        $payload = array(
            'ok' => true,
            'schemaVersion' => 1,
            'generation' => 0,
            'planFile' => '/tmp/install-engine-plan.json',
            'listener' => array(
                'bindAddress' => '127.0.0.1',
                'port' => 7088,
                'protocol' => 'http',
            ),
            'engine' => array(
                'status' => 'planned',
                'installed' => true,
            ),
            'layout' => array(
                'prepared' => true,
            ),
            'logPath' => '/tmp/ols-debug.log',
        );
        if (array('uninstall-engine') === $arguments) {
            $payload['engine']['status'] = 'removed';
            $payload['engine']['installed'] = false;
        } elseif (array('restore-template') === $arguments) {
            $payload['template'] = array(
                'available' => true,
                'configured' => true,
                'restored' => true,
                'restoredFromBackup' => true,
            );
        } elseif (array('set-listener-port', '7090') === $arguments) {
            $payload['listener']['port'] = 7090;
            $payload['engine']['listener']['port'] = 7090;
        } elseif (array('prepare-domain-vhost', '{11111111-1111-4111-8111-111111111111}', '{}') === $arguments) {
            return array(
                'code' => 64,
                'stdout' => '',
                'stderr' => json_encode(array(
                    'ok' => false,
                    'error' => 'Invalid domain payload.',
                    'logPath' => '/tmp/ols-debug.log',
                )),
            );
        } elseif (array('prepare-domain-vhost', '{123e4567-e89b-42d3-a456-426614174000}') === $arguments) {
            $payload['vhost'] = array(
                'domain' => 'example.test',
                'guid' => '123e4567-e89b-42d3-a456-426614174000',
                'path' => '/tmp/example.test.conf',
                'staged' => true,
            );
        } elseif (array(
            'install-engine',
            json_encode(array('mode' => 'already-installed')),
        ) === $arguments) {
            $payload['engine']['status'] = 'installed';
        } elseif (array('install-engine') !== $arguments) {
            throw new RuntimeException('Unexpected installer arguments.');
        }

        return array(
            'code' => 0,
            'stdout' => json_encode($payload),
            'stderr' => '',
        );
    }
}
}

require_once __DIR__
    . '/../../extension/plib/library/EngineInstaller.php';

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

$installer = new Modules_SkamasleOls_EngineInstaller();
$result = $installer->install();

assertSameValue(true, $result['available'], 'Installer must be available');
assertSameValue(
    '/tmp/install-engine-plan.json',
    $result['planFile'],
    'Installer must expose the plan file'
);

$existingInstall = $installer->install(array('mode' => 'already-installed'));
assertSameValue(
    true,
    $existingInstall['available'],
    'Installer must pass provisioning options to the control utility'
);

$uninstall = $installer->run(array('uninstall-engine'));
assertSameValue(
    'removed',
    $uninstall['engine']['status'],
    'Generic runner must support uninstall-engine'
);

$portUpdate = $installer->run(array('set-listener-port', '7090'));
assertSameValue(
    7090,
    $portUpdate['listener']['port'],
    'Generic runner must support listener port updates'
);

$vhost = $installer->run(array('prepare-domain-vhost', '{123e4567-e89b-42d3-a456-426614174000}'));
assertSameValue(
    '/tmp/example.test.conf',
    $vhost['vhost']['path'],
    'Generic runner must support domain vhost preparation'
);

$invalidPayload = $installer->run(array(
    'prepare-domain-vhost',
    '{11111111-1111-4111-8111-111111111111}',
    '{}',
));
assertSameValue(false, $invalidPayload['available'], 'Installer must expose sbin errors');
assertSameValue(
    'Invalid domain payload.',
    $invalidPayload['error'],
    'Installer must decode JSON errors from stderr'
);
assertSameValue(
    '/tmp/ols-debug.log',
    $invalidPayload['logPath'],
    'Installer must expose the debug log from stderr'
);
