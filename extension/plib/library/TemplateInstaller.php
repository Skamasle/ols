<?php

class Modules_SkamasleOls_TemplateInstaller
{
    public function install()
    {
        return $this->run(array('install-template'));
    }

    public function restore()
    {
        return $this->run(array('restore-template'));
    }

    public function status()
    {
        return $this->run(array('template-status'));
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
                $error = 'Unable to install the nginx custom template.';
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
                'template' => isset($payload['template']) && is_array($payload['template'])
                    ? $payload['template']
                    : array(),
                'logPath' => isset($payload['logPath'])
                    ? (string) $payload['logPath']
                    : null,
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Template install failed: '
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
