<?php

require_once __DIR__ . '/PleskTemplateManager.php';
require_once __DIR__ . '/LiteSpeedEnterpriseDetector.php';
require_once __DIR__ . '/TemplateInstaller.php';

class Modules_SkamasleOls_ControlPlaneStatus
{
    private $serviceStatus;
    private $templateInstaller;
    private $enterpriseDetector;

    public function __construct($serviceStatus = null)
    {
        $this->serviceStatus = $serviceStatus
            ? $serviceStatus
            : new Modules_SkamasleOls_SystemServiceStatus();
        $this->templateInstaller = new Modules_SkamasleOls_TemplateInstaller();
        $this->enterpriseDetector = new Modules_SkamasleOls_LiteSpeedEnterpriseDetector();
    }

    public function get()
    {
        if (!class_exists('pm_ApiCli')) {
            return array(
                'available' => false,
                'error' => 'pm_ApiCli is unavailable.',
            );
        }

        try {
            $result = pm_ApiCli::callSbin(
                'skamasle-olsctl',
                array('status'),
                pm_ApiCli::RESULT_FULL
            );
            $payload = json_decode(
                isset($result['stdout']) ? (string) $result['stdout'] : '',
                true
            );
            $exitCode = isset($result['code']) ? (int) $result['code'] : null;
            if (0 !== $exitCode
                || !is_array($payload)
                || empty($payload['ok'])
                || !isset($payload['schemaVersion'], $payload['generation'])
                || !isset($payload['defaultRouting'], $payload['domainCount'])
                || !isset(
                    $payload['planFile'],
                    $payload['listener']['bindAddress'],
                    $payload['listener']['port'],
                    $payload['listener']['protocol'],
                    $payload['engine']['status'],
                    $payload['engine']['installed'],
                    $payload['engine']['listener']['bindAddress'],
                    $payload['engine']['listener']['port'],
                    $payload['routing']['requested']['native'],
                    $payload['routing']['requested']['ols'],
                    $payload['routing']['applied']['native'],
                    $payload['routing']['applied']['ols']
                )
            ) {
                return array(
                    'available' => false,
                    'exitCode' => $exitCode,
                    'error' => 'Invalid control utility response.',
                );
            }

            $templateResult = $this->templateInstaller->status();
            $templateStatus = !empty($templateResult['available'])
                && isset($templateResult['template'])
                && is_array($templateResult['template'])
                ? $templateResult['template']
                : array(
                    'available' => false,
                    'installed' => false,
                    'refreshRequired' => false,
                    'message' => isset($templateResult['error'])
                        ? $templateResult['error']
                        : 'Unable to inspect the nginx custom template.',
                );

            return array(
                'available' => true,
                'exitCode' => $exitCode,
                'schemaVersion' => (int) $payload['schemaVersion'],
                'generation' => (int) $payload['generation'],
                'defaultRouting' => (string) $payload['defaultRouting'],
                'planFile' => (string) $payload['planFile'],
                'listener' => array(
                    'bindAddress' => (string) $payload['listener']['bindAddress'],
                    'port' => (int) $payload['listener']['port'],
                    'protocol' => (string) $payload['listener']['protocol'],
                ),
                'engine' => array(
                    'status' => (string) $payload['engine']['status'],
                    'installed' => (bool) $payload['engine']['installed'],
                    'listener' => array(
                        'bindAddress' => (string) $payload['engine']['listener']['bindAddress'],
                        'port' => (int) $payload['engine']['listener']['port'],
                        'protocol' => (string) $payload['engine']['listener']['protocol'],
                    ),
                    'repository' => $payload['engine']['repository'],
                    'packages' => $payload['engine']['packages'],
                    'services' => $payload['engine']['services'],
                    'paths' => $payload['engine']['paths'],
                    'publicPorts' => $payload['engine']['publicPorts'],
                    'notes' => $payload['engine']['notes'],
                ),
                'domainCount' => (int) $payload['domainCount'],
                'nginxService' => $this->serviceStatus->getNginxStatus(),
                'templateStatus' => $templateStatus,
                'enterpriseStatus' => $this->enterpriseDetector->detect(),
                'routing' => array(
                    'requested' => array(
                        'native' => (int) $payload['routing']['requested']['native'],
                        'ols' => (int) $payload['routing']['requested']['ols'],
                    ),
                    'applied' => array(
                        'native' => (int) $payload['routing']['applied']['native'],
                        'ols' => (int) $payload['routing']['applied']['ols'],
                    ),
                ),
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Control status failed: '
                . $exception->getMessage()
            );
            return array(
                'available' => false,
                'error' => get_class($exception),
            );
        }
    }
}
