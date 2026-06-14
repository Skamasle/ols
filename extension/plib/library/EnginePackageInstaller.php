<?php

class Modules_SkamasleOls_EnginePackageInstaller
{
    const MODE_RECOMMENDED_BOOTSTRAP = 'recommended-bootstrap';
    const MODE_CUSTOM_REPO_URL = 'custom-repo-url';
    const MODE_REPO_READY = 'repo-ready';
    const MODE_ALREADY_INSTALLED = 'already-installed';

    public function install($packageName = 'openlitespeed', array $options = array())
    {
        if (!is_string($packageName) || '' === $packageName) {
            throw new InvalidArgumentException('Package name is required.');
        }

        $mode = $this->normalizeInstallMode($options);
        if (self::MODE_ALREADY_INSTALLED === $mode) {
            return $this->validateExistingInstall($packageName, $mode);
        }

        if (!$this->isRoot()) {
            return array(
                'available' => false,
                'installed' => false,
                'mode' => $mode,
                'error' => 'Package installation requires root privileges.',
            );
        }

        $packageManager = $this->detectPackageManager();
        if (null === $packageManager) {
            return array(
                'available' => false,
                'installed' => false,
                'mode' => $mode,
                'error' => 'No supported package manager was found.',
            );
        }

        $repository = $this->prepareRepository($mode, $options, $packageManager);
        if (empty($repository['configured'])) {
            return array(
                'available' => false,
                'installed' => false,
                'mode' => $mode,
                'packageManager' => $packageManager,
                'packageName' => $packageName,
                'repository' => $repository,
                'error' => isset($repository['error'])
                    ? $repository['error']
                    : 'OpenLiteSpeed repository could not be configured.',
            );
        }

        $alreadyInstalled = $this->runCommand(
            sprintf('rpm -q %s', escapeshellarg($packageName))
        );
        if (0 === $alreadyInstalled['exitCode']) {
            return array(
                'available' => true,
                'installed' => true,
                'mode' => $mode,
                'packageManager' => $packageManager,
                'packageName' => $packageName,
                'repository' => $repository,
                'exitCode' => 0,
                'output' => $alreadyInstalled['output'],
                'message' => 'Package is already installed.',
            );
        }

        $command = sprintf(
            '%s install -y %s',
            escapeshellcmd($packageManager),
            escapeshellarg($packageName)
        );
        $result = $this->runCommand($command);
        $installed = 0 === $result['exitCode'] && 0 === $this->runCommand(
            sprintf('rpm -q %s', escapeshellarg($packageName))
        )['exitCode'];

        return array(
            'available' => 0 === $result['exitCode'],
            'installed' => $installed,
            'mode' => $mode,
            'packageManager' => $packageManager,
            'packageName' => $packageName,
            'repository' => $repository,
            'command' => $command,
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'message' => $installed
                ? 'Package installation completed.'
                : 'Package installation failed.',
        );
    }

    protected function normalizeInstallMode(array $options)
    {
        $mode = isset($options['mode']) ? (string) $options['mode'] : '';
        if (in_array($mode, array(
            self::MODE_RECOMMENDED_BOOTSTRAP,
            self::MODE_CUSTOM_REPO_URL,
            self::MODE_REPO_READY,
            self::MODE_ALREADY_INSTALLED,
        ), true)) {
            return $mode;
        }

        return self::MODE_RECOMMENDED_BOOTSTRAP;
    }

    protected function prepareRepository($mode, array $options, $packageManager)
    {
        if (self::MODE_REPO_READY === $mode) {
            $repository = $this->configuredRepositoryMetadata($mode);
            if (!empty($repository['configured'])) {
                $repository['message'] = 'Repository already configured.';
                return $repository;
            }

            return array(
                'available' => false,
                'configured' => false,
                'mode' => $mode,
                'error' => 'No supported OpenLiteSpeed repository is configured.',
            );
        }

        if (self::MODE_CUSTOM_REPO_URL === $mode) {
            $customRepoUrl = isset($options['customRepoUrl'])
                ? trim((string) $options['customRepoUrl'])
                : '';
            if ('' === $customRepoUrl || !$this->isSafeRepositoryUrl($customRepoUrl)) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'mode' => $mode,
                    'error' => 'A valid custom repository URL is required.',
                );
            }

            return $this->configureCustomRepository($customRepoUrl, $packageManager);
        }

        return $this->ensureRepository();
    }

    protected function validateExistingInstall($packageName, $mode)
    {
        $binaryCheck = $this->runCommand('test -x /usr/local/lsws/bin/openlitespeed');
        if (0 !== $binaryCheck['exitCode']) {
            return array(
                'available' => false,
                'installed' => false,
                'mode' => $mode,
                'packageName' => $packageName,
                'repository' => array(
                    'configured' => false,
                    'managedByModule' => false,
                    'mode' => $mode,
                    'name' => 'user-managed',
                    'verification' => 'user-managed',
                ),
                'error' => 'OpenLiteSpeed is not installed at /usr/local/lsws/bin/openlitespeed.',
            );
        }

        return array(
            'available' => true,
            'installed' => true,
            'mode' => $mode,
            'packageManager' => $this->detectPackageManager(),
            'packageName' => $packageName,
            'repository' => array(
                'available' => true,
                'configured' => false,
                'managedByModule' => false,
                'mode' => $mode,
                'name' => 'user-managed',
                'verification' => 'user-managed',
                'path' => null,
                'message' => 'OpenLiteSpeed was provided by an existing installation.',
            ),
            'exitCode' => 0,
            'output' => '/usr/local/lsws/bin/openlitespeed',
            'message' => 'Existing OpenLiteSpeed installation verified.',
        );
    }

    protected function ensureRepository()
    {
        $configured = $this->configuredRepositoryMetadata(self::MODE_RECOMMENDED_BOOTSTRAP);
        if (!empty($configured['configured'])) {
            $configured['message'] = 'LiteSpeed repository already configured.';
            return $configured;
        }

        $bootstrapCommand = $this->repositoryBootstrapCommand();
        if (null === $bootstrapCommand) {
            return array(
                'available' => false,
                'configured' => false,
                'mode' => self::MODE_RECOMMENDED_BOOTSTRAP,
                'error' => 'No repository bootstrap command was found.',
            );
        }

        $result = $this->runCommand($bootstrapCommand);
        $repository = $this->configuredRepositoryMetadata(self::MODE_RECOMMENDED_BOOTSTRAP);
        $configured = 0 === $result['exitCode'] && !empty($repository['configured']);

        return array_merge($repository, array(
            'available' => $configured,
            'configured' => $configured,
            'command' => $bootstrapCommand,
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'message' => $configured
                ? 'LiteSpeed repository configured.'
                : 'LiteSpeed repository bootstrap failed.',
        ));
    }

    protected function configureCustomRepository($customRepoUrl, $packageManager)
    {
        $path = $this->customRepositoryPath();
        $directory = dirname($path);
        if (is_link($directory)) {
            return array(
                'available' => false,
                'configured' => false,
                'mode' => self::MODE_CUSTOM_REPO_URL,
                'error' => 'Repository directory cannot be a symlink.',
            );
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return array(
                'available' => false,
                'configured' => false,
                'mode' => self::MODE_CUSTOM_REPO_URL,
                'error' => 'Unable to create the repository directory.',
            );
        }

        $contents = implode(PHP_EOL, array(
            '[skamasle-ols-custom]',
            'name=Skamasle OLS Custom',
            'baseurl=' . $customRepoUrl,
            'enabled=1',
            'gpgcheck=0',
            'repo_gpgcheck=0',
            'skip_if_unavailable=0',
            '',
        ));
        if (false === file_put_contents($path, $contents)) {
            return array(
                'available' => false,
                'configured' => false,
                'mode' => self::MODE_CUSTOM_REPO_URL,
                'error' => 'Unable to write the custom repository file.',
            );
        }
        @chmod($path, 0644);

        return array(
            'available' => true,
            'configured' => true,
            'mode' => self::MODE_CUSTOM_REPO_URL,
            'managedByModule' => true,
            'name' => 'openlitespeed-custom',
            'verification' => 'user-provided',
            'customRepoUrl' => $customRepoUrl,
            'packageManager' => $packageManager,
            'path' => $path,
            'message' => 'Custom repository file written.',
        );
    }

    protected function configuredRepositoryMetadata($mode)
    {
        $path = $this->detectedRepositoryPath();
        if (null === $path) {
            return array(
                'available' => false,
                'configured' => false,
                'mode' => $mode,
                'managedByModule' => false,
                'name' => 'openlitespeed-official',
                'verification' => 'fingerprint-verified',
                'path' => null,
            );
        }

        $isCustom = $path === $this->customRepositoryPath();
        return array(
            'available' => true,
            'configured' => true,
            'mode' => $mode,
            'managedByModule' => $isCustom || $path === $this->officialRepositoryPath(),
            'name' => $isCustom ? 'openlitespeed-custom' : 'openlitespeed-official',
            'verification' => $isCustom ? 'user-provided' : 'fingerprint-verified',
            'path' => $path,
        );
    }

    protected function detectedRepositoryPath()
    {
        foreach (array($this->customRepositoryPath(), $this->officialRepositoryPath()) as $path) {
            $result = $this->runCommand('test -f ' . escapeshellarg($path));
            if (0 === $result['exitCode']) {
                return $path;
            }
        }

        return null;
    }

    protected function officialRepositoryPath()
    {
        return $this->repositoryDirectory() . '/litespeed.repo';
    }

    protected function customRepositoryPath()
    {
        return $this->repositoryDirectory() . '/skamasle-ols-custom.repo';
    }

    protected function repositoryDirectory()
    {
        return '/etc/yum.repos.d';
    }

    protected function isSafeRepositoryUrl($url)
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http', 'https'), true);
    }

    protected function repositoryBootstrapCommand()
    {
        $wget = $this->runCommand('command -v wget');
        if (0 === $wget['exitCode'] && '' !== trim($wget['output'])) {
            return 'wget -O - https://repo.litespeed.sh | bash';
        }

        $curl = $this->runCommand('command -v curl');
        if (0 === $curl['exitCode'] && '' !== trim($curl['output'])) {
            return 'curl -fsSL https://repo.litespeed.sh | bash';
        }

        return null;
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
