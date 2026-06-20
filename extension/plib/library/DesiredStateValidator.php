<?php

class Modules_SkamasleOls_DesiredStateValidator
{
    const SCHEMA_VERSION = 1;

    private static function stateRoot()
    {
        if (class_exists('pm_Context') && method_exists('pm_Context', 'getVarDir')) {
            return rtrim(pm_Context::getVarDir(), '/');
        }

        return '/usr/local/psa/var/modules/skamasle-ols';
    }
    const MAX_STATE_BYTES = 8388608;
    const MAX_DOMAINS = 10000;
    const MAX_ALIASES = 100;

    public function decodeAndValidate($json)
    {
        if (!is_string($json)) {
            throw new InvalidArgumentException('State must be a JSON string.');
        }

        if (strlen($json) > self::MAX_STATE_BYTES) {
            throw new InvalidArgumentException('State exceeds the size limit.');
        }

        $decoded = json_decode($json);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException(
                'Invalid JSON: ' . json_last_error_msg()
            );
        }
        $this->validateJsonShape($decoded);

        $state = json_decode($json, true);
        $this->validate($state);
        return $state;
    }

    public function validate(array $state)
    {
        $this->assertKeys(
            $state,
            array('schemaVersion', 'generation', 'server', 'domains'),
            'state'
        );
        $this->assertInteger($state['schemaVersion'], 'schemaVersion', 1);
        if (self::SCHEMA_VERSION !== $state['schemaVersion']) {
            throw new InvalidArgumentException('Unsupported schemaVersion.');
        }

        $this->assertInteger($state['generation'], 'generation', 0);
        $this->validateServer($state['server']);

        if (!is_array($state['domains']) || !$this->isList($state['domains'])) {
            throw new InvalidArgumentException('domains must be a JSON array.');
        }
        if (count($state['domains']) > self::MAX_DOMAINS) {
            throw new InvalidArgumentException('domains exceeds the item limit.');
        }

        $guids = array();
        $names = array();
        foreach ($state['domains'] as $index => $domain) {
            $path = 'domains[' . $index . ']';
            $this->validateDomain($domain, $path);

            $guid = strtolower(trim($domain['guid'], '{}'));
            $name = strtolower($domain['name']);
            if (isset($guids[$guid])) {
                throw new InvalidArgumentException($path . '.guid is duplicated.');
            }
            if (isset($names[$name])) {
                throw new InvalidArgumentException($path . '.name is duplicated.');
            }
            $guids[$guid] = true;
            $names[$name] = true;
        }
    }

    private function validateServer($server)
    {
        if (!is_array($server)) {
            throw new InvalidArgumentException('server must be an object.');
        }
        $this->assertKeys($server, array('defaultRouting', 'listener'), 'server');
        if ('native' !== $server['defaultRouting']) {
            throw new InvalidArgumentException(
                'server.defaultRouting must be native.'
            );
        }
        $this->validateListener($server['listener']);
    }

    private function validateListener($listener)
    {
        if (!is_array($listener)) {
            throw new InvalidArgumentException('server.listener must be an object.');
        }
        $this->assertKeys(
            $listener,
            array('bindAddress', 'port', 'protocol'),
            'server.listener'
        );
        if (!is_string($listener['bindAddress'])
            || !in_array($listener['bindAddress'], array('127.0.0.1', '::1'), true)
        ) {
            throw new InvalidArgumentException(
                'server.listener.bindAddress is invalid.'
            );
        }
        $this->assertInteger($listener['port'], 'server.listener.port', 1024);
        if ($listener['port'] > 65535) {
            throw new InvalidArgumentException(
                'server.listener.port is invalid.'
            );
        }
        if ('http' !== $listener['protocol']) {
            throw new InvalidArgumentException(
                'server.listener.protocol is invalid.'
            );
        }
    }

    private function validateJsonShape($state)
    {
        if (!is_object($state)
            || !isset($state->server)
            || !is_object($state->server)
            || !isset($state->domains)
            || !is_array($state->domains)
        ) {
            throw new InvalidArgumentException('State JSON shape is invalid.');
        }

        foreach ($state->domains as $domain) {
            if (!is_object($domain)
                || !isset($domain->aliases)
                || !is_array($domain->aliases)
                || !isset($domain->nativeProfile)
                || !is_object($domain->nativeProfile)
                || !isset($domain->php)
                || !is_object($domain->php)
            ) {
                throw new InvalidArgumentException(
                    'Domain JSON shape is invalid.'
                );
            }
        }
    }

    private function validateDomain($domain, $path)
    {
        if (!is_array($domain)) {
            throw new InvalidArgumentException($path . ' must be an object.');
        }

        $this->assertKeys(
            $domain,
            array(
                'guid',
                'pleskId',
                'name',
                'aliases',
                'documentRoot',
                'systemUser',
                'systemGroup',
                'nativeProfile',
                'php',
                'requestedRouting',
                'appliedRouting',
            ),
            array('cacheEnabled', 'cachePrivateEnabled', 'vhostRoot'),
            $path
        );

        $guid = $this->normalizeGuid($domain['guid'], $path . '.guid');
        $this->assertInteger($domain['pleskId'], $path . '.pleskId', 1);
        $this->validateDomainName($domain['name'], $path . '.name');
        $this->validateAliases($domain['aliases'], $domain['name'], $path);
        $this->validateAbsolutePath(
            $domain['documentRoot'],
            $path . '.documentRoot'
        );
        if (isset($domain['vhostRoot'])) {
            $this->validateAbsolutePath(
                $domain['vhostRoot'],
                $path . '.vhostRoot'
            );
            if (0 !== strpos(
                rtrim($domain['documentRoot'], '/') . '/',
                rtrim($domain['vhostRoot'], '/') . '/'
            )) {
                throw new InvalidArgumentException(
                    $path . '.documentRoot is outside its vhost root.'
                );
            }
        }
        $this->validateAccountName($domain['systemUser'], $path . '.systemUser');
        $this->validateAccountName($domain['systemGroup'], $path . '.systemGroup');
        $this->validateNativeProfile($domain['nativeProfile'], $path);
        $this->validatePhp($domain['php'], $domain['guid'], $domain['name'], $path);
        $this->validateRouting($domain['requestedRouting'], $path . '.requestedRouting');
        $this->validateRouting($domain['appliedRouting'], $path . '.appliedRouting');
        if (array_key_exists('cacheEnabled', $domain) && !is_bool($domain['cacheEnabled'])) {
            throw new InvalidArgumentException($path . '.cacheEnabled must be boolean.');
        }
        if (array_key_exists('cachePrivateEnabled', $domain)
            && !is_bool($domain['cachePrivateEnabled'])
        ) {
            throw new InvalidArgumentException(
                $path . '.cachePrivateEnabled must be boolean.'
            );
        }
        if (!empty($domain['cachePrivateEnabled']) && empty($domain['cacheEnabled'])) {
            throw new InvalidArgumentException(
                $path . '.cachePrivateEnabled requires cacheEnabled.'
            );
        }
    }

    private function validateAliases($aliases, $domainName, $path)
    {
        if (!is_array($aliases) || !$this->isList($aliases)) {
            throw new InvalidArgumentException($path . '.aliases must be an array.');
        }
        if (count($aliases) > self::MAX_ALIASES) {
            throw new InvalidArgumentException(
                $path . '.aliases exceeds the item limit.'
            );
        }

        $seen = array(strtolower($domainName) => true);
        foreach ($aliases as $index => $alias) {
            $aliasPath = $path . '.aliases[' . $index . ']';
            $this->validateDomainName($alias, $aliasPath);
            $normalized = strtolower($alias);
            if (isset($seen[$normalized])) {
                throw new InvalidArgumentException($aliasPath . ' is duplicated.');
            }
            $seen[$normalized] = true;
        }
    }

    private function validateNativeProfile($profile, $path)
    {
        if (!is_array($profile)) {
            throw new InvalidArgumentException(
                $path . '.nativeProfile must be an object.'
            );
        }
        $this->assertKeys(
            $profile,
            array('webMode', 'proxyMode', 'phpHandlerId'),
            $path . '.nativeProfile'
        );
        if (!in_array($profile['webMode'], array('proxy', 'nginx-only'), true)) {
            throw new InvalidArgumentException(
                $path . '.nativeProfile.webMode is invalid.'
            );
        }
        if (!is_bool($profile['proxyMode'])) {
            throw new InvalidArgumentException(
                $path . '.nativeProfile.proxyMode must be boolean.'
            );
        }
        if (('proxy' === $profile['webMode']) !== $profile['proxyMode']) {
            throw new InvalidArgumentException(
                $path . '.nativeProfile web mode is inconsistent.'
            );
        }
        $this->validateHandlerId(
            $profile['phpHandlerId'],
            $path . '.nativeProfile.phpHandlerId'
        );
    }

    private function validatePhp($php, $guid, $domainName, $path)
    {
        if (!is_array($php)) {
            throw new InvalidArgumentException($path . '.php must be an object.');
        }
        $this->assertKeys(
            $php,
            array(
                'pleskHandlerId',
                'version',
                'lsphpBinary',
                'socket',
            ),
            array('lsapi'),
            $path . '.php'
        );
        $this->validateHandlerId(
            $php['pleskHandlerId'],
            $path . '.php.pleskHandlerId'
        );
        if (!is_string($php['version'])
            || !preg_match('/^\d+\.\d+$/', $php['version'])
        ) {
            throw new InvalidArgumentException($path . '.php.version is invalid.');
        }

        $expectedBinary = '/opt/plesk/php/' . $php['version'] . '/bin/lsphp';
        if ($expectedBinary !== $php['lsphpBinary']) {
            throw new InvalidArgumentException(
                $path . '.php.lsphpBinary does not match the PHP version.'
            );
        }

        $expectedSocket = self::stateRoot() . '/run/lsphp/sk-'
            . substr(hash('sha256', $guid), 0, 24) . '.sock';
        if ($expectedSocket !== $php['socket']) {
            throw new InvalidArgumentException(
                $path . '.php.socket does not match the domain GUID.'
            );
        }

        if (isset($php['lsapi'])) {
            $this->validateLsapi($php['lsapi'], $path . '.php.lsapi');
        }
    }

    private function validateLsapi($lsapi, $path)
    {
        if (!is_array($lsapi)) {
            throw new InvalidArgumentException($path . ' must be an object.');
        }
        $this->assertKeys(
            $lsapi,
            array(
                'maxConnections',
                'children',
                'instances',
                'backlog',
                'initTimeout',
                'retryTimeout',
                'persistentConnection',
                'responseBuffering',
            ),
            $path
        );
        $limits = array(
            'maxConnections' => array(1, 1000),
            'children' => array(1, 1000),
            'instances' => array(1, 100),
            'backlog' => array(1, 10000),
            'initTimeout' => array(1, 3600),
            'retryTimeout' => array(0, 3600),
        );
        foreach ($limits as $key => $range) {
            $this->assertInteger($lsapi[$key], $path . '.' . $key, $range[0]);
            if ($lsapi[$key] > $range[1]) {
                throw new InvalidArgumentException($path . '.' . $key . ' is too large.');
            }
        }
        foreach (array('persistentConnection', 'responseBuffering') as $key) {
            if (!is_bool($lsapi[$key])) {
                throw new InvalidArgumentException($path . '.' . $key . ' must be boolean.');
            }
        }
    }

    private function normalizeGuid($value, $path)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($path . ' must be a string.');
        }
        $guid = strtolower($value);
        if ('{' === substr($guid, 0, 1) && '}' === substr($guid, -1)) {
            $guid = substr($guid, 1, -1);
        }
        if (!preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-'
            . '[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            $guid
        )) {
            throw new InvalidArgumentException($path . ' is invalid.');
        }
        return $guid;
    }

    private function validateDomainName($value, $path)
    {
        if (!is_string($value)
            || strlen($value) > 253
            || strtolower($value) !== $value
            || !preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}'
                . '[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $value
            )
        ) {
            throw new InvalidArgumentException($path . ' is not a valid DNS name.');
        }
    }

    private function validatePath($value, $root, $path)
    {
        if (!is_string($value)
            || 0 !== strpos($value, $root)
            || false !== strpos($value, "\0")
            || 1 === preg_match('#(?:^|/)\.\.(?:/|$)#', $value)
            || false !== strpos($value, '//')
        ) {
            throw new InvalidArgumentException($path . ' is outside its allowed root.');
        }
    }

    private function validateAbsolutePath($value, $path)
    {
        if (!is_string($value)
            || '/' !== substr($value, 0, 1)
            || '/' === $value
            || false !== strpos($value, "\0")
            || 1 === preg_match('#(?:^|/)\.\.(?:/|$)#', $value)
            || false !== strpos($value, '//')
        ) {
            throw new InvalidArgumentException($path . ' is not a safe absolute path.');
        }
    }

    private function validateAccountName($value, $path)
    {
        if (!is_string($value)
            || !preg_match('/^[a-z_][a-z0-9_.-]{0,63}$/', $value)
        ) {
            throw new InvalidArgumentException($path . ' is invalid.');
        }
    }

    private function validateHandlerId($value, $path)
    {
        if (!is_string($value)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value)
        ) {
            throw new InvalidArgumentException($path . ' is invalid.');
        }
    }

    private function validateRouting($value, $path)
    {
        if (!in_array($value, array('native', 'ols'), true)) {
            throw new InvalidArgumentException($path . ' is invalid.');
        }
    }

    private function assertKeys(array $value, array $expected, $optionalOrPath = array(), $path = '')
    {
        if (is_string($optionalOrPath) && '' === $path) {
            $path = $optionalOrPath;
            $optional = array();
        } else {
            $optional = is_array($optionalOrPath) ? $optionalOrPath : array();
        }
        $actual = array_keys($value);
        sort($actual);
        $allowed = array_values(array_unique(array_merge($expected, $optional)));
        sort($allowed);
        $missing = array_diff($expected, $actual);
        $unknown = array_diff($actual, $allowed);
        if (!empty($missing) || !empty($unknown)) {
            throw new InvalidArgumentException(
                $path . ' contains missing or unknown properties.'
            );
        }
    }

    private function assertInteger($value, $path, $minimum)
    {
        if (!is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException($path . ' must be an integer.');
        }
    }

    private function isList(array $value)
    {
        return array() === $value
            || array_keys($value) === range(0, count($value) - 1);
    }
}
