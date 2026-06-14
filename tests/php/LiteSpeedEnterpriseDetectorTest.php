<?php

require_once __DIR__
    . '/../../extension/plib/library/LiteSpeedEnterpriseDetector.php';

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

class TestLiteSpeedEnterpriseDetector extends Modules_SkamasleOls_LiteSpeedEnterpriseDetector
{
    private $filesystemEvidence;
    private $packageEvidence;

    public function __construct(array $filesystemEvidence = array(), array $packageEvidence = array())
    {
        $this->filesystemEvidence = $filesystemEvidence;
        $this->packageEvidence = $packageEvidence;
    }

    protected function detectFilesystemEvidence()
    {
        return $this->filesystemEvidence;
    }

    protected function detectPackageEvidence()
    {
        return $this->packageEvidence;
    }
}

$detector = new TestLiteSpeedEnterpriseDetector();
$status = $detector->detect();

assertSameValue(false, $status['installed'], 'Enterprise must be absent when no evidence is returned');
assertSameValue(array(), $status['evidence'], 'Evidence should be empty when nothing is detected');

$detector = new TestLiteSpeedEnterpriseDetector(
    array('/usr/local/lsws/bin/lshttpd'),
    array('litespeed')
);
$status = $detector->detect();

assertSameValue(true, $status['installed'], 'Enterprise must be detected when evidence exists');
assertSameValue(
    array('/usr/local/lsws/bin/lshttpd', 'litespeed'),
    $status['evidence'],
    'Evidence should include filesystem and package indicators'
);
