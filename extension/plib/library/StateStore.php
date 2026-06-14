<?php

class Modules_SkamasleOls_StateStore
{
    private $stateFile;
    private $validator;

    public function __construct(
        $stateFile,
        Modules_SkamasleOls_DesiredStateValidator $validator
    ) {
        if (!is_string($stateFile) || '' === $stateFile) {
            throw new InvalidArgumentException('State file path is required.');
        }
        $this->stateFile = $stateFile;
        $this->validator = $validator;
    }

    public function getPath()
    {
        return $this->stateFile;
    }

    public function getDirectory()
    {
        return dirname($this->stateFile);
    }

    public function initialize()
    {
        $directory = dirname($this->stateFile);
        $this->ensureDirectory($directory);

        $lock = $this->acquireLock();
        try {
            if (is_file($this->stateFile)) {
                return $this->readOrMigrateUnlocked();
            }

            $state = array(
                'schemaVersion' =>
                    Modules_SkamasleOls_DesiredStateValidator::SCHEMA_VERSION,
                'generation' => 0,
                'server' => array(
                    'defaultRouting' => 'native',
                    'listener' => array(
                        'bindAddress' => '127.0.0.1',
                        'port' => 7088,
                        'protocol' => 'http',
                    ),
                ),
                'domains' => array(),
            );
            $this->writeUnlocked($state);
            return $state;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function read()
    {
        $lock = $this->acquireLock(LOCK_SH);
        try {
            return $this->readUnlocked();
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function write(array $state, $expectedGeneration)
    {
        if (!is_int($expectedGeneration) || $expectedGeneration < 0) {
            throw new InvalidArgumentException(
                'Expected generation must be a non-negative integer.'
            );
        }

        $this->validator->validate($state);
        $lock = $this->acquireLock();
        try {
            $current = $this->readUnlocked();
            if ($current['generation'] !== $expectedGeneration) {
                throw new RuntimeException('Desired state generation conflict.');
            }
            if ($state['generation'] !== $expectedGeneration + 1) {
                throw new InvalidArgumentException(
                    'New desired state must increment generation by one.'
                );
            }

            $this->writeUnlocked($state);
            return $state;
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function readUnlocked()
    {
        if (!is_file($this->stateFile) || is_link($this->stateFile)) {
            throw new RuntimeException('Desired state is not initialized.');
        }
        $json = file_get_contents($this->stateFile);
        if (false === $json) {
            throw new RuntimeException('Unable to read desired state.');
        }

        return $this->validator->decodeAndValidate($json);
    }

    private function readOrMigrateUnlocked()
    {
        try {
            return $this->readUnlocked();
        } catch (InvalidArgumentException $exception) {
            $legacyState = $this->readLegacyStateUnlocked();
            if (null === $legacyState) {
                throw $exception;
            }

            $migrated = $this->migrateLegacyState($legacyState);
            $this->writeUnlocked($migrated);

            return $migrated;
        }
    }

    private function readLegacyStateUnlocked()
    {
        $json = file_get_contents($this->stateFile);
        if (false === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['server']) || !is_array($decoded['server'])) {
            return null;
        }

        return $decoded;
    }

    private function migrateLegacyState(array $state)
    {
        $migrated = array(
            'schemaVersion' => isset($state['schemaVersion'])
                ? (int) $state['schemaVersion']
                : Modules_SkamasleOls_DesiredStateValidator::SCHEMA_VERSION,
            'generation' => isset($state['generation'])
                ? (int) $state['generation']
                : 0,
            'server' => array(
                'defaultRouting' => isset($state['server']['defaultRouting'])
                    ? (string) $state['server']['defaultRouting']
                    : 'native',
                'listener' => array(
                    'bindAddress' => '127.0.0.1',
                    'port' => 7088,
                    'protocol' => 'http',
                ),
            ),
            'domains' => array(),
        );

        if (isset($state['server']['listener']) && is_array($state['server']['listener'])) {
            $listener = $state['server']['listener'];
            if (isset($listener['bindAddress'])) {
                $migrated['server']['listener']['bindAddress'] = $listener['bindAddress'];
            }
            if (isset($listener['port'])) {
                $migrated['server']['listener']['port'] = (int) $listener['port'];
            }
            if (isset($listener['protocol'])) {
                $migrated['server']['listener']['protocol'] = $listener['protocol'];
            }
        }

        if (isset($state['domains']) && is_array($state['domains'])) {
            $migrated['domains'] = $state['domains'];
        }

        return $migrated;
    }

    private function writeUnlocked(array $state)
    {
        $this->validator->validate($state);
        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (false === $json) {
            throw new RuntimeException('Unable to encode desired state.');
        }

        $directory = dirname($this->stateFile);
        $temporaryFile = tempnam($directory, '.desired-state.');
        if (false === $temporaryFile) {
            throw new RuntimeException('Unable to create temporary state.');
        }

        try {
            if (false === file_put_contents(
                $temporaryFile,
                $json . PHP_EOL,
                LOCK_EX
            )) {
                throw new RuntimeException('Unable to write temporary state.');
            }
            if (!chmod($temporaryFile, 0600)) {
                throw new RuntimeException('Unable to secure temporary state.');
            }
            if (!rename($temporaryFile, $this->stateFile)) {
                throw new RuntimeException('Unable to activate desired state.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function ensureDirectory($directory)
    {
        if (is_link($directory)) {
            throw new RuntimeException('State directory cannot be a symlink.');
        }
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create state directory.');
            }
            if (!chmod($directory, 0700)) {
                throw new RuntimeException('Unable to secure state directory.');
            }
        }
    }

    private function acquireLock($operation = LOCK_EX)
    {
        $directory = dirname($this->stateFile);
        $this->ensureDirectory($directory);
        $lockFile = $this->stateFile . '.lock';
        if (is_link($lockFile)) {
            throw new RuntimeException('State lock cannot be a symlink.');
        }

        $handle = fopen($lockFile, 'c');
        if (false === $handle) {
            throw new RuntimeException('Unable to open state lock.');
        }
        if (!chmod($lockFile, 0600) || !flock($handle, $operation)) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire state lock.');
        }

        return $handle;
    }

    private function releaseLock($handle)
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
