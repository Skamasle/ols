<?php

class Modules_SkamasleOls_EngineInstallPlanner
{
    private function stateRoot()
    {
        if (class_exists('pm_Context') && method_exists('pm_Context', 'getVarDir')) {
            return rtrim(pm_Context::getVarDir(), '/');
        }

        return '/usr/local/psa/var/modules/skamasle-ols';
    }

    public function build(array $state, array $provisioning = array())
    {
        $listener = $this->listenerFromState($state);
        $mode = isset($provisioning['mode']) ? (string) $provisioning['mode'] : 'recommended-bootstrap';
        $customRepoUrl = isset($provisioning['customRepoUrl'])
            ? trim((string) $provisioning['customRepoUrl'])
            : null;

        return array(
            'status' => 'planned',
            'listener' => $listener,
            'provisioning' => array(
                'mode' => $mode,
                'customRepoUrl' => $customRepoUrl,
            ),
            'repository' => array(
                'name' => 'openlitespeed-official',
                'verification' => 'fingerprint-verified',
                'configured' => false,
                'mode' => $mode,
            ),
            'packages' => array('openlitespeed'),
            'services' => array('lsws'),
            'paths' => array(
                'configRoot' => '/usr/local/lsws/conf/skamasle-ols/',
                'listenerRoot' => '/usr/local/lsws/conf/skamasle-ols/listeners/',
                'vhostRoot' => '/usr/local/lsws/conf/skamasle-ols/vhosts/',
                'stateRoot' => $this->stateRoot() . '/',
                'runtimeRoot' => $this->stateRoot() . '/run/',
            ),
            'publicPorts' => array(80, 443),
            'notes' => array(
                'Provisioning mode: ' . $mode . '.',
                'OLS remains private on loopback.',
                'The public nginx frontend is not modified by this step.',
                'Package installation is executed by install-engine.',
            ),
        );
    }

    private function listenerFromState(array $state)
    {
        if (isset($state['server']['listener']) && is_array($state['server']['listener'])) {
            return array(
                'bindAddress' => isset($state['server']['listener']['bindAddress'])
                    ? $state['server']['listener']['bindAddress']
                    : '127.0.0.1',
                'port' => isset($state['server']['listener']['port'])
                    ? (int) $state['server']['listener']['port']
                    : 7088,
                'protocol' => isset($state['server']['listener']['protocol'])
                    ? $state['server']['listener']['protocol']
                    : 'http',
            );
        }

        return array(
            'bindAddress' => '127.0.0.1',
            'port' => 7088,
            'protocol' => 'http',
        );
    }
}
