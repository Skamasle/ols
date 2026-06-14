<?php

$tests = glob(__DIR__ . '/*Test.php');
sort($tests);

$failures = 0;
foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    $output = array();
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if (0 === $exitCode) {
        fwrite(STDOUT, 'PASS ' . basename($test) . PHP_EOL);
    } else {
        $failures++;
        fwrite(STDERR, 'FAIL ' . basename($test) . PHP_EOL);
        if (!empty($output)) {
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
    }
}

exit($failures > 0 ? 1 : 0);
