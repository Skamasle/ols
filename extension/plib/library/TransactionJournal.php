<?php

class Modules_SkamasleOls_TransactionJournal
{
    private $directory;

    public function __construct($directory)
    {
        if (!is_string($directory) || '' === $directory) {
            throw new InvalidArgumentException(
                'Transaction directory path is required.'
            );
        }
        $this->directory = $directory;
    }

    public function begin($operation, array $preconditions)
    {
        if (!in_array(
            $operation,
            array('install-engine', 'reconcile', 'uninstall-engine'),
            true
        )) {
            throw new InvalidArgumentException('Unknown transaction operation.');
        }

        $id = $this->createId();
        $transaction = array(
            'schemaVersion' => 1,
            'id' => $id,
            'operation' => $operation,
            'status' => 'running',
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'preconditions' => $preconditions,
            'steps' => array(),
            'previousFiles' => array(),
            'createdResources' => array(),
            'validations' => array(),
            'rollback' => array(
                'attempted' => false,
                'completed' => false,
                'steps' => array(),
            ),
            'error' => null,
        );
        $this->write($transaction);
        return $transaction;
    }

    public function recordStep(array $transaction, $step, $status, array $details)
    {
        $this->assertActive($transaction);
        if (!is_string($step) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $step)) {
            throw new InvalidArgumentException('Transaction step is invalid.');
        }
        if (!in_array($status, array('completed', 'skipped'), true)) {
            throw new InvalidArgumentException('Transaction step status is invalid.');
        }

        $transaction['steps'][] = array(
            'name' => $step,
            'status' => $status,
            'recordedAt' => gmdate('c'),
            'details' => $details,
        );
        $this->write($transaction);
        return $transaction;
    }

    public function complete(array $transaction, array $validations)
    {
        $this->assertActive($transaction);
        $transaction['status'] = 'completed';
        $transaction['finishedAt'] = gmdate('c');
        $transaction['validations'] = $validations;
        $this->write($transaction);
        return $transaction;
    }

    public function recordPreviousFile(
        array $transaction,
        $path,
        $sha256
    ) {
        $this->assertActive($transaction);
        $this->validateAbsolutePath($path);
        $this->validateSha256($sha256);
        $transaction['previousFiles'][] = array(
            'path' => $path,
            'sha256' => $sha256,
        );
        $this->write($transaction);
        return $transaction;
    }

    public function recordCreatedResource(
        array $transaction,
        $type,
        $identifier
    ) {
        $this->assertActive($transaction);
        if (!in_array(
            $type,
            array('file', 'directory', 'package', 'repository', 'service'),
            true
        ) || !is_string($identifier) || '' === $identifier
        ) {
            throw new InvalidArgumentException('Created resource is invalid.');
        }
        $transaction['createdResources'][] = array(
            'type' => $type,
            'identifier' => $identifier,
        );
        $this->write($transaction);
        return $transaction;
    }

    public function fail(
        array $transaction,
        $error,
        array $rollbackSteps,
        $rollbackCompleted
    ) {
        $this->assertActive($transaction);
        if (!is_string($error) || '' === $error) {
            throw new InvalidArgumentException('Transaction error is required.');
        }
        if (!is_bool($rollbackCompleted)) {
            throw new InvalidArgumentException(
                'Rollback completion must be boolean.'
            );
        }

        $transaction['status'] = 'failed';
        $transaction['finishedAt'] = gmdate('c');
        $transaction['error'] = $error;
        $transaction['rollback'] = array(
            'attempted' => true,
            'completed' => $rollbackCompleted,
            'steps' => $rollbackSteps,
        );
        $this->write($transaction);
        return $transaction;
    }

    public function read($id)
    {
        $this->validateId($id);
        $file = $this->directory . '/' . $id . '.json';
        if (!is_file($file) || is_link($file)) {
            throw new RuntimeException('Transaction does not exist.');
        }
        $json = file_get_contents($file);
        $transaction = json_decode($json, true);
        if (false === $json
            || !is_array($transaction)
            || JSON_ERROR_NONE !== json_last_error()
        ) {
            throw new RuntimeException('Transaction journal is invalid.');
        }
        $this->validate($transaction);
        return $transaction;
    }

    private function write(array $transaction)
    {
        $this->validate($transaction);
        $this->ensureDirectory();
        $file = $this->directory . '/' . $transaction['id'] . '.json';
        $temporaryFile = tempnam($this->directory, '.transaction.');
        if (false === $temporaryFile) {
            throw new RuntimeException('Unable to create transaction journal.');
        }

        $json = json_encode(
            $transaction,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        try {
            if (false === $json
                || false === file_put_contents(
                    $temporaryFile,
                    $json . PHP_EOL,
                    LOCK_EX
                )
                || !chmod($temporaryFile, 0600)
                || !rename($temporaryFile, $file)
            ) {
                throw new RuntimeException('Unable to write transaction journal.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function validate(array $transaction)
    {
        $expected = array(
            'schemaVersion',
            'id',
            'operation',
            'status',
            'startedAt',
            'finishedAt',
            'preconditions',
            'steps',
            'previousFiles',
            'createdResources',
            'validations',
            'rollback',
            'error',
        );
        $actual = array_keys($transaction);
        sort($expected);
        sort($actual);
        if ($expected !== $actual
            || 1 !== $transaction['schemaVersion']
            || !in_array(
                $transaction['operation'],
                array('install-engine', 'reconcile', 'uninstall-engine'),
                true
            )
            || !in_array(
                $transaction['status'],
                array('running', 'completed', 'failed'),
                true
            )
            || !is_string($transaction['startedAt'])
            || !(null === $transaction['finishedAt']
                || is_string($transaction['finishedAt']))
            || !is_array($transaction['preconditions'])
            || !is_array($transaction['steps'])
            || !is_array($transaction['previousFiles'])
            || !is_array($transaction['createdResources'])
            || !is_array($transaction['validations'])
            || !is_array($transaction['rollback'])
            || !(null === $transaction['error']
                || is_string($transaction['error']))
        ) {
            throw new InvalidArgumentException('Transaction schema is invalid.');
        }
        $this->validateId($transaction['id']);

        $rollbackKeys = array_keys($transaction['rollback']);
        $expectedRollbackKeys = array('attempted', 'completed', 'steps');
        sort($rollbackKeys);
        sort($expectedRollbackKeys);
        if ($rollbackKeys !== $expectedRollbackKeys
            || !is_bool($transaction['rollback']['attempted'])
            || !is_bool($transaction['rollback']['completed'])
            || !is_array($transaction['rollback']['steps'])
        ) {
            throw new InvalidArgumentException(
                'Transaction rollback schema is invalid.'
            );
        }
    }

    private function assertActive(array $transaction)
    {
        $this->validate($transaction);
        if ('running' !== $transaction['status']) {
            throw new RuntimeException('Transaction is already finished.');
        }
    }

    private function ensureDirectory()
    {
        if (is_link($this->directory)) {
            throw new RuntimeException(
                'Transaction directory cannot be a symlink.'
            );
        }
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException('Unable to create transaction directory.');
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException('Unable to secure transaction directory.');
        }
    }

    private function createId()
    {
        return gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
    }

    private function validateId($id)
    {
        if (!is_string($id)
            || !preg_match('/^\d{14}-[a-f0-9]{16}$/', $id)
        ) {
            throw new InvalidArgumentException('Transaction ID is invalid.');
        }
    }

    private function validateAbsolutePath($path)
    {
        if (!is_string($path)
            || '/' !== substr($path, 0, 1)
            || false !== strpos($path, "\0")
            || 1 === preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
        ) {
            throw new InvalidArgumentException('Recorded file path is invalid.');
        }
    }

    private function validateSha256($sha256)
    {
        if (!is_string($sha256)
            || !preg_match('/^[a-f0-9]{64}$/', $sha256)
        ) {
            throw new InvalidArgumentException('Recorded file hash is invalid.');
        }
    }
}
