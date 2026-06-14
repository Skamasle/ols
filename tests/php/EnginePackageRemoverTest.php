<?php

require_once __DIR__
    . '/../../extension/plib/library/EnginePackageRemover.php';

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

class TestEnginePackageRemover extends Modules_SkamasleOls_EnginePackageRemover
{
    private $commands;
    private $counts;

    public function __construct(array $commands)
    {
        $this->commands = $commands;
    }

    protected function isRoot()
    {
        return true;
    }

    protected function detectPackageManager()
    {
        return 'dnf';
    }

    protected function runCommand($command)
    {
        if (!isset($this->counts[$command])) {
            $this->counts[$command] = 0;
        }
        $this->counts[$command]++;

        if (!isset($this->commands[$command])) {
            return array('exitCode' => 1, 'output' => '');
        }

        if (is_array($this->commands[$command])
            && isset($this->commands[$command][0])
            && is_array($this->commands[$command][0])
        ) {
            $index = min(
                $this->counts[$command] - 1,
                count($this->commands[$command]) - 1
            );
            return $this->commands[$command][$index];
        }

        return $this->commands[$command];
    }
}

$repoFile = sys_get_temp_dir() . '/skamasle-ols-remove-' . bin2hex(random_bytes(6)) . '.repo';
file_put_contents($repoFile, "[repo]\n");

$remover = new TestEnginePackageRemover(array(
    'command -v systemctl' => array(
        'exitCode' => 0,
        'output' => '/usr/bin/systemctl',
    ),
    "systemctl stop 'lsws'" => array(
        'exitCode' => 0,
        'output' => '',
    ),
    "rpm -q 'openlitespeed'" => array(
        array(
            'exitCode' => 0,
            'output' => 'openlitespeed-1.0.0',
        ),
        array(
            'exitCode' => 1,
            'output' => 'package openlitespeed is not installed',
        ),
    ),
    "dnf remove -y 'openlitespeed'" => array(
        'exitCode' => 0,
        'output' => 'Removed',
    ),
));

$result = $remover->remove('openlitespeed', array(
    'removeRepository' => true,
    'repositoryPath' => $repoFile,
));

assertSameValue(true, $result['available'], 'Package removal must be available');
assertSameValue(true, $result['removed'], 'Package removal must succeed');
assertSameValue(true, $result['serviceStop']['stopped'], 'Service must be stopped');
assertSameValue(
    true,
    $result['repositoryRemoved']['removed'],
    'Repository file removal must be reported'
);
