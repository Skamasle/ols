<?php

require_once __DIR__
    . '/../../extension/plib/library/DesiredStateValidator.php';
require_once __DIR__
    . '/../../extension/plib/library/StateStore.php';

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

$directory = sys_get_temp_dir() . '/skamasle-ols-state-' . bin2hex(random_bytes(6));
$stateFile = $directory . '/desired-state.json';
$store = new Modules_SkamasleOls_StateStore(
    $stateFile,
    new Modules_SkamasleOls_DesiredStateValidator()
);

try {
    $initial = $store->initialize();
    assertSameValue(0, $initial['generation'], 'Initial generation must be zero');
    assertSameValue(
        '127.0.0.1',
        $initial['server']['listener']['bindAddress'],
        'Initial listener must be loopback'
    );
    assertSameValue($initial, $store->initialize(), 'Initialize must be idempotent');
    assertSameValue(0600, fileperms($stateFile) & 0777, 'State must be private');
    assertSameValue(0700, fileperms($directory) & 0777, 'Directory must be private');

    $next = $initial;
    $next['generation'] = 1;
    $store->write($next, 0);
    assertSameValue(1, $store->read()['generation'], 'Generation must advance');

    try {
        $store->write($next, 0);
        throw new RuntimeException('Stale generation must be rejected.');
    } catch (RuntimeException $exception) {
        assertSameValue(
            true,
            false !== strpos($exception->getMessage(), 'conflict'),
            'Generation conflict must be reported'
        );
    }
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

$legacyDirectory = sys_get_temp_dir() . '/skamasle-ols-legacy-' . bin2hex(random_bytes(6));
$legacyStateFile = $legacyDirectory . '/desired-state.json';
$legacyStore = new Modules_SkamasleOls_StateStore(
    $legacyStateFile,
    new Modules_SkamasleOls_DesiredStateValidator()
);

try {
    mkdir($legacyDirectory, 0700, true);
    file_put_contents(
        $legacyStateFile,
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
                'engine' => array(
                    'installed' => false,
                    'status' => 'unplanned',
                ),
            ),
            'domains' => array(),
        ))
    );

    $migrated = $legacyStore->initialize();
    assertSameValue(
        '127.0.0.1',
        $migrated['server']['listener']['bindAddress'],
        'Legacy state must gain a loopback listener'
    );
    assertSameValue(
        false,
        isset($migrated['server']['engine']),
        'Legacy state must drop unknown server keys'
    );
} finally {
    foreach (glob($legacyDirectory . '/*') as $file) {
        unlink($file);
    }
    if (is_dir($legacyDirectory)) {
        rmdir($legacyDirectory);
    }
}
