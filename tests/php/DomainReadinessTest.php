<?php

require_once __DIR__
    . '/../../extension/plib/library/DomainReadiness.php';

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

$evaluator = new Modules_SkamasleOls_DomainReadiness();
$compatible = array(
    'status' => 'compatible',
    'filesScanned' => 1,
    'findingCount' => 0,
    'findings' => array(),
);
$serverReady = array(
    'nginx' => array(
        'available' => true,
        'active' => true,
        'state' => 'active',
    ),
);
$pending = $evaluator->evaluate(
    array('hosting' => true, 'active' => true, 'suspended' => false),
    $compatible,
    $serverReady
);
assertSameValue('pending', $pending['status'], 'Eligible domain must be pending');
assertSameValue(
    false,
    $pending['routingControlEnabled'],
    'Routing control must remain disabled'
);

$review = $evaluator->evaluate(
    array('hosting' => true, 'active' => true, 'suspended' => false),
    array(
        'status' => 'review',
        'filesScanned' => 1,
        'findingCount' => 1,
        'findings' => array(),
    ),
    $serverReady
);
assertSameValue('review', $review['status'], 'Known incompatibility must warn');
assertSameValue(
    true,
    $review['acknowledgementRequired'],
    'Compatibility warning must require acknowledgement'
);

$notScanned = $evaluator->evaluate(
    array('hosting' => true, 'active' => true, 'suspended' => false),
    array(
        'status' => 'not-scanned',
        'filesScanned' => 0,
        'findingCount' => 0,
        'findings' => array(),
    ),
    $serverReady
);
assertSameValue(
    'review',
    $notScanned['status'],
    'An unscanned .htaccess must require an explicit manual scan first'
);

$blocked = $evaluator->evaluate(
    array('hosting' => true, 'active' => true, 'suspended' => false),
    array(
        'status' => 'blocked',
        'filesScanned' => 0,
        'findingCount' => 1,
        'findings' => array(),
    ),
    $serverReady
);
assertSameValue('blocked', $blocked['status'], 'Scan failure must block');

$nginxDown = $evaluator->evaluate(
    array('hosting' => true, 'active' => true, 'suspended' => false),
    $compatible,
    array(
        'nginx' => array(
            'available' => true,
            'active' => false,
            'state' => 'inactive',
        ),
    )
);
assertSameValue(
    'blocked',
    $nginxDown['status'],
    'Nginx downtime must block activation'
);
