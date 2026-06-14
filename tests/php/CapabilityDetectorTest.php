<?php

class pm_ProductInfo
{
    public static function getVersion()
    {
        return '18.0.78';
    }

    public static function getPlatform()
    {
        return 'linux';
    }

    public static function getOsName()
    {
        return 'AlmaLinux';
    }

    public static function getOsVersion()
    {
        return '10.2';
    }

    public static function getOsArch()
    {
        return 'x86_64';
    }
}

class pm_Domain {}
class pm_Hook_WebServer {}
class pm_WebServer {}
class pm_Log
{
    public static function warning($message)
    {
    }
}

require_once __DIR__
    . '/../../extension/plib/library/SystemServiceStatus.php';
require_once __DIR__
    . '/../../extension/plib/library/CapabilityDetector.php';

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

class TestCapabilityServiceStatus extends Modules_SkamasleOls_SystemServiceStatus
{
    private $status;

    public function __construct(array $status)
    {
        $this->status = $status;
    }

    public function getNginxStatus()
    {
        return $this->status;
    }
}

class TestCapabilityDetector extends Modules_SkamasleOls_CapabilityDetector
{
    protected function callProductInfo($method)
    {
        $map = array(
            'getVersion' => '18.0.78',
            'getPlatform' => 'linux',
            'getOsName' => 'AlmaLinux',
            'getOsVersion' => '10.2',
            'getOsArch' => 'x86_64',
        );

        return isset($map[$method]) ? $map[$method] : 'Unavailable';
    }
}

$detector = new TestCapabilityDetector(
    new TestCapabilityServiceStatus(array(
        'available' => true,
        'active' => true,
        'state' => 'active',
    ))
);
$capabilities = $detector->detect();

assertSameValue(
    'Active',
    $capabilities['nginx service state'],
    'Nginx service state must be exposed'
);
assertSameValue(
    'Install + uninstall + port update',
    $capabilities['Current extension mode'],
    'Extension mode must describe the maintenance actions'
);
