<?php

class Modules_SkamasleOls_EngineInstaller
{
    public function install(array $options = array())
    {
        $arguments = array('install-engine');
        if (!empty($options)) {
            $arguments[] = json_encode($options);
        }

        return $this->run($arguments);
    }

    public function run(array $arguments)
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
                $arguments,
                pm_ApiCli::RESULT_FULL
            );
            $stdout = isset($result['stdout']) ? (string) $result['stdout'] : '';
            $stderr = isset($result['stderr']) ? (string) $result['stderr'] : '';
            $payload = json_decode($stdout, true);
            $exitCode = isset($result['code']) ? (int) $result['code'] : null;

            if (0 !== $exitCode || !is_array($payload) || empty($payload['ok'])) {
                $errorPayload = json_decode($stderr, true);
                if (!is_array($errorPayload)) {
                    $errorPayload = is_array($payload) ? $payload : array();
                }
                $error = 'Unable to install OpenLiteSpeed.';
                if (isset($errorPayload['error']) && '' !== trim($errorPayload['error'])) {
                    $error = (string) $errorPayload['error'];
                } elseif (isset($errorPayload['message']) && '' !== trim($errorPayload['message'])) {
                    $error = (string) $errorPayload['message'];
                }

                return array(
                    'available' => false,
                    'exitCode' => $exitCode,
                    'error' => $error,
                    'logPath' => isset($errorPayload['logPath'])
                        ? (string) $errorPayload['logPath']
                        : null,
                    'details' => $errorPayload,
                    'stderr' => '' === trim($stderr) ? null : trim($stderr),
                );
            }

            return array(
                'available' => true,
                'exitCode' => $exitCode,
                'command' => $arguments,
                'schemaVersion' => isset($payload['schemaVersion'])
                    ? (int) $payload['schemaVersion']
                    : null,
                'generation' => isset($payload['generation'])
                    ? (int) $payload['generation']
                    : null,
                'planFile' => isset($payload['planFile'])
                    ? (string) $payload['planFile']
                    : null,
                'listener' => isset($payload['listener']) && is_array($payload['listener'])
                    ? $payload['listener']
                    : array(),
                'engine' => isset($payload['engine']) && is_array($payload['engine'])
                    ? $payload['engine']
                    : array(),
                'vhost' => isset($payload['vhost']) && is_array($payload['vhost'])
                    ? $payload['vhost']
                    : array(),
                'domain' => isset($payload['domain']) && is_array($payload['domain'])
                    ? $payload['domain']
                    : array(),
                'logPath' => isset($payload['logPath'])
                    ? (string) $payload['logPath']
                    : null,
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Engine install failed: '
                . $exception->getMessage()
            );
            return array(
                'available' => false,
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            );
        }
    }
}
