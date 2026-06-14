<?php

class Modules_SkamasleOls_LiteSpeedEnterpriseDetector
{
    public function detect()
    {
        $filesystemEvidence = $this->detectFilesystemEvidence();
        $packageEvidence = $this->detectPackageEvidence();
        $evidence = array_values(array_unique(array_merge(
            $filesystemEvidence,
            $packageEvidence
        )));

        return array(
            'available' => true,
            'installed' => !empty($evidence),
            'evidence' => $evidence,
            'message' => !empty($evidence)
                ? 'LiteSpeed Enterprise appears to be installed.'
                : 'LiteSpeed Enterprise was not detected.',
        );
    }

    protected function detectFilesystemEvidence()
    {
        $candidates = array(
            '/usr/local/lsws/bin/lshttpd',
            '/usr/local/lsws/bin/lswsctrl',
            '/usr/local/lsws/admin/bin/lswsctrl',
        );
        $evidence = array();

        foreach ($candidates as $path) {
            if (is_file($path) && !is_link($path)) {
                $evidence[] = $path;
            }
        }

        return $evidence;
    }

    protected function detectPackageEvidence()
    {
        $candidates = array(
            'litespeed',
            'litespeed-enterprise',
            'lsws',
        );
        $evidence = array();

        foreach ($candidates as $package) {
            if ($this->isRpmPackageInstalled($package) || $this->isDpkgPackageInstalled($package)) {
                $evidence[] = $package;
            }
        }

        return $evidence;
    }

    protected function isRpmPackageInstalled($package)
    {
        $result = $this->runCommand('rpm -q ' . escapeshellarg($package));
        return 0 === $result['exitCode'];
    }

    protected function isDpkgPackageInstalled($package)
    {
        $result = $this->runCommand(
            'dpkg-query -W -f=\'${Status}\' ' . escapeshellarg($package)
        );

        return 0 === $result['exitCode'] && false !== stripos($result['stdout'], 'install ok installed');
    }

    protected function runCommand($command)
    {
        $output = array();
        $exitCode = 0;
        @exec($command . ' 2>&1', $output, $exitCode);

        return array(
            'command' => $command,
            'exitCode' => (int) $exitCode,
            'stdout' => trim(implode("\n", $output)),
        );
    }
}
