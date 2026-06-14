<?php

class Modules_SkamasleOls_EnginePlanStore
{
    private $path;

    private function stateRoot()
    {
        if (class_exists('pm_Context') && method_exists('pm_Context', 'getVarDir')) {
            return rtrim(pm_Context::getVarDir(), '/');
        }

        return '/usr/local/psa/var/modules/skamasle-ols';
    }

    public function __construct($directory)
    {
        if (!is_string($directory) || '' === $directory) {
            throw new InvalidArgumentException('Plan directory is required.');
        }

        $this->path = rtrim($directory, '/') . '/install-engine-plan.json';
    }

    public function getPath()
    {
        return $this->path;
    }

    public function read()
    {
        if (!is_file($this->path) || is_link($this->path)) {
            return $this->defaultPlan();
        }

        $json = file_get_contents($this->path);
        if (false === $json) {
            throw new RuntimeException('Unable to read installation plan.');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid installation plan JSON.');
        }

        return $this->normalizePlan($decoded);
    }

    public function write(array $plan)
    {
        $plan = $this->normalizePlan($plan);
        $directory = dirname($this->path);
        if (is_link($directory)) {
            throw new RuntimeException('Plan directory cannot be a symlink.');
        }
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create plan directory.');
            }
            chmod($directory, 0700);
        }

        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new RuntimeException('Unable to encode installation plan.');
        }

        $temporaryFile = tempnam($directory, '.install-engine-plan.');
        if (false === $temporaryFile) {
            throw new RuntimeException('Unable to create temporary plan file.');
        }

        try {
            if (false === file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX)) {
                throw new RuntimeException('Unable to write temporary plan file.');
            }
            chmod($temporaryFile, 0600);
            if (!rename($temporaryFile, $this->path)) {
                throw new RuntimeException('Unable to activate installation plan.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return $plan;
    }

    private function defaultPlan()
    {
        return array(
            'installed' => false,
            'status' => 'unplanned',
            'listener' => array(
                'bindAddress' => '127.0.0.1',
                'port' => 7088,
                'protocol' => 'http',
            ),
            'provisioning' => array(
                'mode' => 'recommended-bootstrap',
                'customRepoUrl' => null,
            ),
            'repository' => array(
                'name' => 'openlitespeed-official',
                'verification' => 'fingerprint-verified',
                'configured' => false,
                'mode' => 'recommended-bootstrap',
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
                'No persisted installation receipt has been created yet.',
                'Choose a provisioning mode before running install-engine.',
                'Run install-engine to install or integrate OpenLiteSpeed.',
            ),
        );
    }

    private function normalizePlan(array $plan)
    {
        $defaults = $this->defaultPlan();

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $plan) || null === $plan[$key]) {
                $plan[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($plan[$key])) {
                foreach ($value as $subKey => $subValue) {
                    if (!array_key_exists($subKey, $plan[$key])) {
                        $plan[$key][$subKey] = $subValue;
                    }
                }
            }
        }

        return $plan;
    }
}
