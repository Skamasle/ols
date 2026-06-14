<?php

if (!class_exists('pm_ApiCli')) {
class pm_ApiCli
{
    const RESULT_FULL = 1;

    public static function callSbin($command, array $arguments, $resultMode)
    {
        if ('skamasle-olsctl' !== $command
            || self::RESULT_FULL !== $resultMode
        ) {
            throw new RuntimeException('Unexpected control utility call.');
        }

        if (array('template-status') === $arguments) {
            $hash = hash('sha256', 'template');
            return array(
                'code' => 0,
                'stdout' => json_encode(array(
                    'ok' => true,
                    'template' => array(
                        'available' => true,
                        'installed' => true,
                        'refreshRequired' => false,
                        'sourceHash' => $hash,
                        'targetHash' => $hash,
                    ),
                )),
                'stderr' => '',
            );
        }

        if (array('restore-template') === $arguments) {
            return array(
                'code' => 0,
                'stdout' => json_encode(array(
                    'ok' => true,
                    'template' => array(
                        'available' => true,
                        'configured' => true,
                        'restored' => true,
                        'restoredFromBackup' => true,
                    ),
                )),
                'stderr' => '',
            );
        }

        if (array('status') !== $arguments) {
            throw new RuntimeException('Unexpected control utility call.');
        }

        return array(
            'code' => 0,
            'stdout' => json_encode(array(
                'ok' => true,
                'schemaVersion' => 1,
                'generation' => 0,
                'defaultRouting' => 'native',
                'planFile' => '/usr/local/psa/var/modules/skamasle-ols/install-engine-plan.json',
                'listener' => array(
                    'bindAddress' => '127.0.0.1',
                    'port' => 7088,
                    'protocol' => 'http',
                ),
                'engine' => array(
                    'status' => 'installed',
                    'installed' => true,
                    'listener' => array(
                        'bindAddress' => '127.0.0.1',
                        'port' => 7088,
                        'protocol' => 'http',
                    ),
                    'repository' => array(),
                    'packages' => array(),
                    'services' => array(),
                    'paths' => array(),
                    'publicPorts' => array(),
                    'notes' => array(),
                ),
                'domainCount' => 0,
                'routing' => array(
                    'requested' => array('native' => 0, 'ols' => 0),
                    'applied' => array('native' => 0, 'ols' => 0),
                ),
            )),
            'stderr' => '',
        );
    }
}
}

require_once __DIR__
    . '/../../extension/plib/library/SystemServiceStatus.php';
require_once __DIR__
    . '/../../extension/plib/library/ControlPlaneStatus.php';

class TestControlServiceStatus extends Modules_SkamasleOls_SystemServiceStatus
{
    public function getNginxStatus()
    {
        return array(
            'available' => true,
            'active' => true,
            'state' => 'active',
        );
    }
}

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

$status = new Modules_SkamasleOls_ControlPlaneStatus(
    new TestControlServiceStatus()
);
$result = $status->get();

assertSameValue(true, $result['available'], 'Control utility must be available');
assertSameValue(0, $result['generation'], 'Initial generation must be zero');
assertSameValue('native', $result['defaultRouting'], 'Routing must be native');
assertSameValue(
    '/usr/local/psa/var/modules/skamasle-ols/install-engine-plan.json',
    $result['planFile'],
    'Plan file must be exposed'
);
assertSameValue(
    'active',
    $result['nginxService']['state'],
    'Nginx service status must be exposed'
);
assertSameValue(
    true,
    $result['engine']['installed'],
    'Engine install flag must be exposed'
);
assertSameValue(
    true,
    isset($result['templateStatus']['available']),
    'Template status must be exposed'
);
