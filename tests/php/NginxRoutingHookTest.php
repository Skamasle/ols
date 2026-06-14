<?php

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

if (!class_exists('pm_Hook_WebServer')) {
    class pm_Hook_WebServer
    {
    }
}

if (!class_exists('pm_Domain')) {
    class pm_Domain
    {
    }
}

if (!class_exists('TestPleskDomain')) {
    class TestPleskDomain extends pm_Domain
    {
        public static $settings = array();

        public function getSetting($name, $default = null)
        {
            return isset(self::$settings[$name])
                ? self::$settings[$name]
                : $default;
        }

        public function getName()
        {
            return 'example.test';
        }
    }
}

if (!class_exists('pm_Settings')) {
    class pm_Settings
    {
        public static $values = array();

        public static function get($name)
        {
            return isset(self::$values[$name]) ? self::$values[$name] : null;
        }
    }
}

require_once __DIR__ . '/../../extension/plib/hooks.php';

pm_Settings::$values['listener.port'] = '7090';
TestPleskDomain::$settings['skamasle-ols.routing'] = 'ols';
$hook = new Modules_SkamasleOls_WebServer();
$config = $hook->getDomainNginxConfig(new TestPleskDomain());

assertSameValue(
    true,
    false !== strpos($config, 'proxy_pass https://127.0.0.1:7090;'),
    'nginx hook must proxy to the configured OLS listener over HTTPS'
);
assertSameValue(
    true,
    false !== strpos($config, 'proxy_ssl_verify off;'),
    'nginx hook must not require backend certificate validation'
);
assertSameValue(
    true,
    false !== strpos($config, 'location @skamasle_ols'),
    'nginx hook must use a named location'
);

TestPleskDomain::$settings['skamasle-ols.routing'] = 'native';
assertSameValue(
    '',
    $hook->getDomainNginxConfig(new TestPleskDomain()),
    'Native routing must not alter nginx configuration'
);
