<?php

require_once __DIR__ . '/../library/DesiredStateValidator.php';
require_once __DIR__ . '/../library/StateStore.php';

pm_Settings::set('routing.default', 'native');
pm_Settings::set('extension.mode', 'per-domain-routing');

$stateDirectory = rtrim(pm_Context::getVarDir(), '/');
$runtimeDirectory = $stateDirectory . '/run';
$runtimeRoots = array(
    $stateDirectory,
    $stateDirectory . '/logs',
    $stateDirectory . '/vhosts',
    $stateDirectory . '/php',
    $runtimeDirectory,
    $runtimeDirectory . '/lsphp',
);
foreach ($runtimeRoots as $directory) {
    if (is_link($directory)) {
        fwrite(STDERR, 'Refusing to use symlinked runtime path: ' . $directory . PHP_EOL);
        exit(1);
    }
    if (!is_dir($directory)) {
        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            fwrite(STDERR, 'Unable to create runtime path: ' . $directory . PHP_EOL);
            exit(1);
        }
    }
}

try {
    $stateFile = pm_Context::getVarDir() . '/desired-state.json';
    if (is_file($stateFile)) {
        try {
        if (class_exists('pm_ApiCli')) {
            $result = pm_ApiCli::callSbin(
                'skamasle-olsctl',
                array('status'),
                pm_ApiCli::RESULT_FULL
            );
            $payload = json_decode(
                isset($result['stdout']) ? (string) $result['stdout'] : '',
                true
            );
            if (0 === (isset($result['code']) ? (int) $result['code'] : 1)
                && is_array($payload)
                && !empty($payload['ok'])
                && isset($payload['listener']['port'])
            ) {
                pm_Settings::set(
                    'listener.port',
                    (string) $payload['listener']['port']
                );
            }
        }

        if ('' === (string) pm_Settings::get('listener.port', '')) {
            pm_Settings::set('listener.port', '7088');
        }
        } catch (Throwable $exception) {
            if ('' === (string) pm_Settings::get('listener.port', '')) {
                pm_Settings::set('listener.port', '7088');
            }
        }
    } else {
        $validator = new Modules_SkamasleOls_DesiredStateValidator();
        $stateStore = new Modules_SkamasleOls_StateStore($stateFile, $validator);
        $state = $stateStore->initialize();
        pm_Settings::set(
            'listener.port',
            (string) $state['server']['listener']['port']
        );
    }
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Unable to initialize desired state: ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
