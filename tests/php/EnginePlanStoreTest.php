<?php

require_once __DIR__
    . '/../../extension/plib/library/EnginePlanStore.php';

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

$directory = sys_get_temp_dir() . '/skamasle-ols-plan-' . bin2hex(random_bytes(6));
$store = new Modules_SkamasleOls_EnginePlanStore($directory);

try {
    $empty = $store->read();
    assertSameValue('unplanned', $empty['status'], 'Plan store must start empty');
    assertSameValue(
        false,
        $empty['installed'],
        'Plan store must not report installed without a file'
    );
    assertSameValue(
        'recommended-bootstrap',
        $empty['provisioning']['mode'],
        'Plan store must default to the recommended bootstrap mode'
    );

    $plan = array(
        'status' => 'installed',
        'installed' => true,
        'listener' => array(
            'bindAddress' => '127.0.0.1',
            'port' => 7088,
            'protocol' => 'http',
        ),
    );
    $store->write($plan);

    $stored = $store->read();
    assertSameValue('installed', $stored['status'], 'Receipt must be persisted');
    assertSameValue(
        7088,
        $stored['listener']['port'],
        'Stored listener port must match'
    );

    $legacyPlan = array(
        'status' => 'failed',
        'installed' => false,
        'listener' => array(
            'bindAddress' => '127.0.0.1',
            'port' => 7088,
            'protocol' => 'http',
        ),
    );
    $store->write($legacyPlan);
    $normalized = $store->read();
    assertSameValue(
        'openlitespeed-official',
        $normalized['repository']['name'],
        'Legacy plan must gain repository metadata'
    );
    assertSameValue(
        false,
        $normalized['repository']['configured'],
        'Legacy plan must default to an unconfigured repository'
    );
    assertSameValue(
        'recommended-bootstrap',
        $normalized['provisioning']['mode'],
        'Legacy plan must gain provisioning metadata'
    );
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
