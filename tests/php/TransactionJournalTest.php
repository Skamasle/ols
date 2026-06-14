<?php

require_once __DIR__
    . '/../../extension/plib/library/TransactionJournal.php';

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

$directory = sys_get_temp_dir() . '/skamasle-ols-journal-' . bin2hex(random_bytes(6));
$journal = new Modules_SkamasleOls_TransactionJournal($directory);

try {
    $transaction = $journal->begin(
        'reconcile',
        array('generation' => 4)
    );
    assertSameValue('running', $transaction['status'], 'Transaction must start');

    $transaction = $journal->recordPreviousFile(
        $transaction,
        '/etc/skamasle-ols/server.conf',
        str_repeat('a', 64)
    );
    $transaction = $journal->recordCreatedResource(
        $transaction,
        'directory',
        '/var/lib/skamasle-ols/generations/5'
    );
    $transaction = $journal->recordStep(
        $transaction,
        'render',
        'completed',
        array('generation' => 5)
    );
    $transaction = $journal->complete(
        $transaction,
        array('configTest' => true)
    );

    $stored = $journal->read($transaction['id']);
    assertSameValue('completed', $stored['status'], 'Transaction must complete');
    assertSameValue(1, count($stored['steps']), 'Step must be persisted');
    assertSameValue(
        0600,
        fileperms($directory . '/' . $transaction['id'] . '.json') & 0777,
        'Journal must be private'
    );

    try {
        $journal->begin('shell', array());
        throw new RuntimeException('Unknown operation must be rejected.');
    } catch (InvalidArgumentException $exception) {
        assertSameValue(
            true,
            false !== strpos($exception->getMessage(), 'Unknown'),
            'Unknown operation must be reported'
        );
    }
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
