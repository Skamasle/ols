<?php

require_once __DIR__ . '/LiteSpeedEnterpriseDetector.php';

class Modules_SkamasleOls_CapabilityDetector
{
    private $serviceStatus;
    private $enterpriseDetector;

    public function __construct($serviceStatus = null)
    {
        $this->serviceStatus = $serviceStatus
            ? $serviceStatus
            : new Modules_SkamasleOls_SystemServiceStatus();
        $this->enterpriseDetector = new Modules_SkamasleOls_LiteSpeedEnterpriseDetector();
    }

    public function detect()
    {
        $nginxStatus = $this->serviceStatus->getNginxStatus();
        $enterpriseStatus = $this->enterpriseDetector->detect();

        return array(
            'Plesk version' => $this->callProductInfo('getVersion'),
            'Platform' => $this->callProductInfo('getPlatform'),
            'Operating system' => $this->formatOperatingSystem(),
            'Architecture' => $this->callProductInfo('getOsArch'),
            'Plesk domain API' => $this->yesNo(class_exists('pm_Domain')),
            'Plesk web server hook API' => $this->yesNo(class_exists('pm_Hook_WebServer')),
            'Plesk web server update API' => $this->yesNo(class_exists('pm_WebServer')),
            'Apache binary detected' => $this->yesNo(
                is_file('/usr/sbin/httpd') || is_file('/usr/sbin/apache2')
            ),
            'nginx binary detected' => $this->yesNo(
                is_file('/usr/sbin/nginx') || is_file('/usr/local/sbin/nginx')
            ),
            'nginx service state' => $this->formatServiceState($nginxStatus),
            'OpenLiteSpeed detected' => $this->yesNo(
                is_file('/usr/local/lsws/bin/openlitespeed')
            ),
            'LiteSpeed Enterprise detected' => $this->yesNo(
                !empty($enterpriseStatus['installed'])
            ),
            'Current extension mode' => 'Install + uninstall + port update',
        );
    }

    private function formatOperatingSystem()
    {
        $name = $this->callProductInfo('getOsName');
        $version = $this->callProductInfo('getOsVersion');

        return trim($name . ' ' . $version);
    }

    protected function callProductInfo($method)
    {
        if (!class_exists('pm_ProductInfo') || !is_callable(array('pm_ProductInfo', $method))) {
            return 'Unavailable';
        }

        try {
            $value = call_user_func(array('pm_ProductInfo', $method));
            return is_scalar($value) ? (string) $value : 'Unavailable';
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[skamasle-ols] Capability %s failed: %s',
                $method,
                $exception->getMessage()
            ));
            return 'Unavailable';
        }
    }

    protected function formatServiceState(array $status)
    {
        if (!array_key_exists('state', $status)) {
            return 'Unknown';
        }

        if ('active' === $status['state']) {
            return 'Active';
        }

        if ('inactive' === $status['state']) {
            return 'Inactive';
        }

        return 'Unknown';
    }

    protected function yesNo($value)
    {
        return $value ? 'Yes' : 'No';
    }
}
