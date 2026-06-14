<?php

require_once __DIR__
    . '/../../extension/plib/library/EngineInstallPlanner.php';

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

$planner = new Modules_SkamasleOls_EngineInstallPlanner();
$plan = $planner->build(array(
    'server' => array(
        'listener' => array(
            'bindAddress' => '127.0.0.1',
            'port' => 7088,
            'protocol' => 'http',
        ),
    ),
));

assertSameValue('planned', $plan['status'], 'Engine plan must be planned');
assertSameValue(
    '127.0.0.1',
    $plan['listener']['bindAddress'],
    'Listener must stay loopback'
);
assertSameValue(
    7088,
    $plan['listener']['port'],
    'Listener port must be preserved'
);
assertSameValue(
    'recommended-bootstrap',
    $plan['provisioning']['mode'],
    'Planner must default to the recommended bootstrap mode'
);
