<?php

class Modules_SkamasleOls_SystemServiceStatus
{
    public function getNginxStatus()
    {
        $state = $this->probeNginxStatus();

        return array(
            'available' => null !== $state,
            'active' => $state,
            'state' => null === $state
                ? 'unknown'
                : ($state ? 'active' : 'inactive'),
        );
    }

    protected function probeNginxStatus()
    {
        $systemctl = $this->runCommand(
            'command -v systemctl >/dev/null 2>&1'
            . ' && systemctl is-active nginx 2>/dev/null'
        );

        if ($systemctl['available']) {
            if ('active' === $systemctl['stdout']) {
                return true;
            }

            if (
                '' !== $systemctl['stdout']
                || 0 === $systemctl['exitCode']
                || 3 === $systemctl['exitCode']
            ) {
                return false;
            }
        }

        $service = $this->runCommand(
            'command -v service >/dev/null 2>&1'
            . ' && service nginx status 2>/dev/null'
        );

        if ($service['available']) {
            if (
                false !== stripos($service['stdout'], 'running')
                || false !== stripos($service['stdout'], 'active')
            ) {
                return true;
            }

            return false;
        }

        return null;
    }

    protected function runCommand($command)
    {
        $output = array();
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        return array(
            'available' => 0 === $exitCode || !empty($output),
            'exitCode' => (int) $exitCode,
            'stdout' => trim(implode("\n", $output)),
        );
    }
}
