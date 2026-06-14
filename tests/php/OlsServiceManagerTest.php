<?php

require_once __DIR__
    . '/../../extension/plib/library/OlsServiceManager.php';

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

class TestOlsServiceManager extends Modules_SkamasleOls_OlsServiceManager
{
    private $commands;

    public function __construct(array $commands)
    {
        $this->commands = $commands;
    }

    protected function runCommand($command)
    {
        return isset($this->commands[$command])
            ? $this->commands[$command]
            : array('exitCode' => 1, 'output' => '');
    }
}

$manager = new TestOlsServiceManager(array(
    '/usr/local/lsws/bin/openlitespeed -t' => array(
        'exitCode' => 1,
        'output' => '[WARN] example vhost uses server uid',
    ),
    "systemctl reload 'lsws'" => array(
        'exitCode' => 0,
        'output' => '',
    ),
));

$test = $manager->testConfig();
assertSameValue(true, $test['valid'], 'Warning-only config test must work');

$reload = $manager->reload('lsws');
assertSameValue(true, $reload['reloaded'], 'Reload must succeed');
