<?php

class Modules_SkamasleOls_OlsServiceManager
{
    public function reload($serviceName = 'lsws')
    {
        $reload = $this->tryReload($serviceName);
        if ($reload['reloaded']) {
            return $reload;
        }

        $restart = $this->tryRestart($serviceName);
        if ($restart['restarted']) {
            $restart['fallback'] = 'restart';
            return $restart;
        }

        return array(
            'available' => false,
            'reloaded' => false,
            'restarted' => false,
            'error' => isset($reload['error']) ? $reload['error'] : $restart['error'],
            'reloadAttempts' => isset($reload['attempts']) ? $reload['attempts'] : array($reload),
            'restartAttempts' => isset($restart['attempts']) ? $restart['attempts'] : array($restart),
        );
    }

    public function testConfig()
    {
        $binary = '/usr/local/lsws/bin/openlitespeed';
        $result = $this->runCommand($binary . ' -t');
        $result['binary'] = $binary;
        $result['binaryExists'] = is_file($binary);
        $result['binaryExecutable'] = is_executable($binary);
        $result['valid'] = $result['exitCode'] < 2
            && false === stripos($result['output'], '[ERROR]')
            && false === stripos($result['output'], 'Fatal error');
        return $result;
    }

    private function tryReload($serviceName)
    {
        $systemctl = $this->runCommand(
            sprintf('systemctl reload %s', escapeshellarg($serviceName))
        );
        if (0 === $systemctl['exitCode']) {
            return array(
                'available' => true,
                'reloaded' => true,
                'command' => 'systemctl reload ' . $serviceName,
                'exitCode' => 0,
                'output' => $systemctl['output'],
            );
        }

        $service = $this->runCommand(
            sprintf('service %s reload', escapeshellarg($serviceName))
        );
        return array(
            'available' => 0 === $service['exitCode'],
            'reloaded' => 0 === $service['exitCode'],
            'command' => 'service ' . $serviceName . ' reload',
            'exitCode' => $service['exitCode'],
            'output' => $service['output'],
            'error' => 'Unable to reload service.',
            'attempts' => array($systemctl, $service),
        );
    }

    private function tryRestart($serviceName)
    {
        $systemctl = $this->runCommand(
            sprintf('systemctl restart %s', escapeshellarg($serviceName))
        );
        if (0 === $systemctl['exitCode']) {
            return array(
                'available' => true,
                'restarted' => true,
                'command' => 'systemctl restart ' . $serviceName,
                'exitCode' => 0,
                'output' => $systemctl['output'],
            );
        }

        $service = $this->runCommand(
            sprintf('service %s restart', escapeshellarg($serviceName))
        );
        return array(
            'available' => 0 === $service['exitCode'],
            'restarted' => 0 === $service['exitCode'],
            'command' => 'service ' . $serviceName . ' restart',
            'exitCode' => $service['exitCode'],
            'output' => $service['output'],
            'error' => 'Unable to restart service.',
            'attempts' => array($systemctl, $service),
        );
    }

    protected function runCommand($command)
    {
        $output = array();
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        return array(
            'command' => $command,
            'exitCode' => (int) $exitCode,
            'output' => implode("\n", $output),
        );
    }
}
