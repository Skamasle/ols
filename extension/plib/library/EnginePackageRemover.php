<?php

class Modules_SkamasleOls_EnginePackageRemover
{
    public function remove($packageName = 'openlitespeed', array $options = array())
    {
        if (!is_string($packageName) || '' === $packageName) {
            throw new InvalidArgumentException('Package name is required.');
        }

        if (!$this->isRoot()) {
            return array(
                'available' => false,
                'removed' => false,
                'error' => 'Package removal requires root privileges.',
            );
        }

        $removePackage = !isset($options['removePackage'])
            || false !== $options['removePackage'];
        $removeRepository = !empty($options['removeRepository']);
        $repositoryPath = isset($options['repositoryPath'])
            ? (string) $options['repositoryPath']
            : '/etc/yum.repos.d/litespeed.repo';

        $serviceStop = $this->stopService('lsws');
        if (!$removePackage) {
            return array(
                'available' => true,
                'removed' => true,
                'packageManager' => $this->detectPackageManager(),
                'packageName' => $packageName,
                'serviceStop' => $serviceStop,
                'repositoryRemoved' => array(
                    'available' => true,
                    'removed' => false,
                    'path' => $repositoryPath,
                    'message' => 'Repository removal skipped.',
                ),
                'message' => 'OpenLiteSpeed package removal skipped by provisioning mode.',
            );
        }

        $packageManager = $this->detectPackageManager();
        if (null === $packageManager) {
            return array(
                'available' => false,
                'removed' => false,
                'error' => 'No supported package manager was found.',
            );
        }

        $alreadyInstalled = $this->runCommand(
            sprintf('rpm -q %s', escapeshellarg($packageName))
        );

        if (0 !== $alreadyInstalled['exitCode']) {
            $repoRemoved = $removeRepository
                ? $this->removeRepositoryFile($repositoryPath)
                : $this->skippedRepositoryRemoval($repositoryPath);
            return array(
                'available' => true,
                'removed' => true,
                'packageManager' => $packageManager,
                'packageName' => $packageName,
                'serviceStop' => $serviceStop,
                'repositoryRemoved' => $repoRemoved,
                'message' => 'Package is not installed.',
            );
        }

        $command = sprintf(
            '%s remove -y %s',
            escapeshellcmd($packageManager),
            escapeshellarg($packageName)
        );
        $result = $this->runCommand($command);
        $removed = 0 === $result['exitCode'] && 0 !== $this->runCommand(
            sprintf('rpm -q %s', escapeshellarg($packageName))
        )['exitCode'];
        $repoRemoved = $removeRepository
            ? $this->removeRepositoryFile($repositoryPath)
            : $this->skippedRepositoryRemoval($repositoryPath);

        return array(
            'available' => 0 === $result['exitCode'],
            'removed' => $removed,
            'packageManager' => $packageManager,
            'packageName' => $packageName,
            'serviceStop' => $serviceStop,
            'repositoryRemoved' => $repoRemoved,
            'command' => $command,
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'message' => $removed
                ? 'Package removal completed.'
                : 'Package removal failed.',
        );
    }

    protected function removeRepositoryFile($repoFile)
    {
        if (!is_string($repoFile) || '' === trim($repoFile)) {
            return $this->skippedRepositoryRemoval($repoFile);
        }
        if (!is_file($repoFile)) {
            return array(
                'available' => true,
                'removed' => true,
                'path' => $repoFile,
                'message' => 'Repository file was not present.',
            );
        }

        if (!@unlink($repoFile)) {
            return array(
                'available' => false,
                'removed' => false,
                'path' => $repoFile,
                'message' => 'Unable to remove repository file.',
            );
        }

        return array(
            'available' => true,
            'removed' => true,
            'path' => $repoFile,
            'message' => 'Repository file removed.',
        );
    }

    protected function skippedRepositoryRemoval($repoFile)
    {
        return array(
            'available' => true,
            'removed' => false,
            'path' => $repoFile,
            'message' => 'Repository removal skipped.',
        );
    }

    protected function stopService($serviceName)
    {
        if (!is_string($serviceName) || '' === $serviceName) {
            return array(
                'available' => false,
                'stopped' => false,
                'message' => 'Service name is required.',
            );
        }

        $systemctl = $this->runCommand('command -v systemctl');
        if (0 === $systemctl['exitCode'] && '' !== trim($systemctl['output'])) {
            $result = $this->runCommand(
                sprintf('systemctl stop %s', escapeshellarg($serviceName))
            );
            return array(
                'available' => true,
                'stopped' => 0 === $result['exitCode'],
                'command' => 'systemctl stop ' . $serviceName,
                'exitCode' => $result['exitCode'],
                'output' => $result['output'],
            );
        }

        $service = $this->runCommand('command -v service');
        if (0 === $service['exitCode'] && '' !== trim($service['output'])) {
            $result = $this->runCommand(
                sprintf('service %s stop', escapeshellarg($serviceName))
            );
            return array(
                'available' => true,
                'stopped' => 0 === $result['exitCode'],
                'command' => 'service ' . $serviceName . ' stop',
                'exitCode' => $result['exitCode'],
                'output' => $result['output'],
            );
        }

        return array(
            'available' => false,
            'stopped' => false,
            'message' => 'No supported service manager was found.',
        );
    }

    protected function detectPackageManager()
    {
        $dnf = $this->runCommand('command -v dnf');
        if (0 === $dnf['exitCode'] && '' !== trim($dnf['output'])) {
            return 'dnf';
        }

        $yum = $this->runCommand('command -v yum');
        if (0 === $yum['exitCode'] && '' !== trim($yum['output'])) {
            return 'yum';
        }

        return null;
    }

    protected function isRoot()
    {
        return function_exists('posix_geteuid')
            ? 0 === posix_geteuid()
            : true;
    }

    protected function runCommand($command)
    {
        $output = array();
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        return array(
            'exitCode' => (int) $exitCode,
            'output' => implode("\n", $output),
        );
    }
}
