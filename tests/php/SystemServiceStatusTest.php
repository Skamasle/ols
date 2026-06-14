<?php

require_once __DIR__
    . '/../../extension/plib/library/SystemServiceStatus.php';

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

class TestSystemServiceStatus extends Modules_SkamasleOls_SystemServiceStatus
{
    private $response;

    public function __construct(array $response)
    {
        $this->response = $response;
    }

    protected function runCommand($command)
    {
        return $this->response;
    }
}

$active = new TestSystemServiceStatus(array(
    'available' => true,
    'exitCode' => 0,
    'stdout' => 'active',
));
$activeStatus = $active->getNginxStatus();
assertSameValue(true, $activeStatus['available'], 'Active status must be available');
assertSameValue(true, $activeStatus['active'], 'Active status must be true');
assertSameValue('active', $activeStatus['state'], 'Active status must be reported');

$inactive = new TestSystemServiceStatus(array(
    'available' => true,
    'exitCode' => 3,
    'stdout' => 'inactive',
));
$inactiveStatus = $inactive->getNginxStatus();
assertSameValue(true, $inactiveStatus['available'], 'Inactive status must be available');
assertSameValue(false, $inactiveStatus['active'], 'Inactive status must be false');
assertSameValue('inactive', $inactiveStatus['state'], 'Inactive status must be reported');
