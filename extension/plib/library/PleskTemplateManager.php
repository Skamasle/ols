<?php

class Modules_SkamasleOls_PleskTemplateManager
{
    const TEMPLATE_RELATIVE_PATH = 'domain/service/proxy.php';
    const LEGACY_TEMPLATE_RELATIVE_PATH = 'default/domain/service/proxy.php';

    private $sourceRoot;
    private $customRoot;

    public function __construct(
        $sourceRoot = null,
        $customRoot = '/usr/local/psa/admin/conf/templates/custom'
    ) {
        $this->sourceRoot = rtrim(
            null === $sourceRoot
                ? dirname(__DIR__) . '/templates/custom'
                : $sourceRoot,
            '/'
        );
        $this->customRoot = rtrim($customRoot, '/');
    }

    public function getSourcePath()
    {
        return $this->sourceRoot . '/' . self::TEMPLATE_RELATIVE_PATH;
    }

    public function getTargetPath()
    {
        return $this->customRoot . '/' . self::TEMPLATE_RELATIVE_PATH;
    }

    public function getLegacyTargetPath()
    {
        return $this->customRoot . '/' . self::LEGACY_TEMPLATE_RELATIVE_PATH;
    }

    public function status()
    {
        $source = $this->getSourcePath();
        $target = $this->getTargetPath();
        $legacyTarget = $this->getLegacyTargetPath();
        $customRootExists = is_dir($this->customRoot) && !is_link($this->customRoot);
        $sourceExists = is_file($source) && !is_link($source);
        $sourceContent = $sourceExists ? file_get_contents($source) : false;
        $sourceContent = false === $sourceContent ? null : $sourceContent;
        $targetExists = is_file($target) && !is_link($target);
        $legacyTargetExists = is_file($legacyTarget) && !is_link($legacyTarget);
        $sourceHash = $sourceExists ? hash('sha256', $sourceContent) : null;
        $targetHash = $targetExists ? hash_file('sha256', $target) : null;
        $hashesMatch = $this->hashesMatch($sourceHash, $targetHash);

        return array(
            'available' => $sourceExists,
            'installed' => $targetExists && $hashesMatch,
            'customTemplateExists' => $targetExists,
            'backupRequired' => $targetExists && !$hashesMatch,
            'refreshRequired' => $targetExists && !$hashesMatch,
            'hashesMatch' => $hashesMatch,
            'customRootExists' => $customRootExists,
            'source' => $this->diagnosePath($source),
            'target' => $this->diagnosePath($target),
            'legacyTarget' => $this->diagnosePath($legacyTarget),
            'legacyTargetExists' => $legacyTargetExists,
            'customRoot' => $this->diagnosePath($this->customRoot),
            'sourceHash' => $sourceHash,
            'targetHash' => $targetHash,
            'relativePath' => self::TEMPLATE_RELATIVE_PATH,
            'message' => $this->buildStatusMessage(
                $sourceExists,
                $targetExists,
                $sourceHash,
                $targetHash
            ),
        );
    }

    public function install()
    {
        $source = $this->getSourcePath();
        $target = $this->getTargetPath();
        $legacyTarget = $this->getLegacyTargetPath();
        $sourceContent = is_file($source) && !is_link($source)
            ? file_get_contents($source)
            : false;

        if (false === $sourceContent) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => 'Bundled Plesk template source is unavailable.',
                'source' => $this->diagnosePath($source),
                'target' => $this->diagnosePath($target),
                'customRoot' => $this->diagnosePath($this->customRoot),
            );
        }

        $this->ensureDirectory($this->customRoot);
        $this->ensureDirectory(dirname($target));

        $sourceHash = hash('sha256', $sourceContent);
        $targetExists = is_file($target) && !is_link($target);
        $targetHash = $targetExists ? hash_file('sha256', $target) : null;
        $legacyRemoved = $this->removeManagedLegacyTemplate(
            $legacyTarget,
            $sourceHash
        );

        if ($targetExists && $this->hashesMatch($sourceHash, $targetHash)) {
            return array(
                'available' => true,
                'configured' => true,
                'installed' => true,
                'changed' => false,
                'verified' => true,
                'backupCreated' => false,
                'backupPath' => null,
                'sourceHash' => $sourceHash,
                'targetHash' => $targetHash,
                'source' => $this->diagnosePath($source),
                'target' => $this->diagnosePath($target),
                'legacyTarget' => $this->diagnosePath($legacyTarget),
                'legacyRemoved' => $legacyRemoved,
                'message' => 'Custom nginx template is already installed.',
            );
        }

        $backupPath = null;
        if ($targetExists) {
            $backupPath = $this->buildBackupPath($target);
            if (!copy($target, $backupPath)) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'error' => 'Unable to back up the existing custom template.',
                    'backupPath' => $backupPath,
                    'source' => $this->diagnosePath($source),
                    'target' => $this->diagnosePath($target),
                    'backup' => $this->diagnosePath($backupPath),
                );
            }
        }

        $writeResult = $this->writeAtomic($target, $sourceContent);
        if (empty($writeResult['written'])) {
            return array(
                'available' => false,
                'configured' => false,
                'error' => isset($writeResult['error'])
                    ? $writeResult['error']
                    : 'Unable to install the custom nginx template.',
                'backupPath' => $backupPath,
                'source' => $this->diagnosePath($source),
                'target' => $this->diagnosePath($target),
                'backup' => null !== $backupPath
                    ? $this->diagnosePath($backupPath)
                    : null,
            );
        }

        $installedHash = is_file($target) ? hash_file('sha256', $target) : null;
        if (!$this->hashesMatch($sourceHash, $installedHash)) {
            return array(
                'available' => false,
                'configured' => false,
                'installed' => false,
                'verified' => false,
                'error' => 'Installed nginx template failed SHA-256 verification.',
                'backupPath' => $backupPath,
                'sourceHash' => $sourceHash,
                'targetHash' => $installedHash,
                'source' => $this->diagnosePath($source),
                'target' => $this->diagnosePath($target),
            );
        }

        return array(
            'available' => true,
            'configured' => true,
            'installed' => true,
            'changed' => true,
            'verified' => true,
            'backupCreated' => null !== $backupPath,
            'backupPath' => $backupPath,
            'sourceHash' => $sourceHash,
            'targetHash' => $installedHash,
            'source' => $this->diagnosePath($source),
            'target' => $this->diagnosePath($target),
            'backup' => null !== $backupPath
                ? $this->diagnosePath($backupPath)
                : null,
            'legacyTarget' => $this->diagnosePath($legacyTarget),
            'legacyRemoved' => $legacyRemoved,
            'message' => null !== $backupPath
                ? 'Existing custom nginx template backed up and replaced.'
            : 'Custom nginx template installed.',
        );
    }

    public function restore()
    {
        $source = $this->getSourcePath();
        $target = $this->getTargetPath();
        $legacyTarget = $this->getLegacyTargetPath();
        $sourceContent = is_file($source) && !is_link($source)
            ? file_get_contents($source)
            : false;
        $sourceHash = false === $sourceContent ? null : hash('sha256', $sourceContent);
        $backupPath = $this->findLatestBackupPath($target);
        $restoredFromBackup = false;

        if (null !== $backupPath && is_file($backupPath) && !is_link($backupPath)) {
            if (!copy($backupPath, $target)) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'error' => 'Unable to restore the original custom nginx template.',
                    'backupPath' => $backupPath,
                    'target' => $this->diagnosePath($target),
                    'backup' => $this->diagnosePath($backupPath),
                );
            }
            $restoredFromBackup = true;
        } elseif (is_file($target) && !is_link($target)) {
            if (!unlink($target)) {
                return array(
                    'available' => false,
                    'configured' => false,
                    'error' => 'Unable to remove the managed custom nginx template.',
                    'target' => $this->diagnosePath($target),
                );
            }
        }

        $this->removeEmptyParentDirectories(dirname($target));
        $legacyRemoved = $this->removeManagedLegacyTemplate($legacyTarget, $sourceHash);
        if (null !== $backupPath && is_file($backupPath) && !is_link($backupPath)) {
            @unlink($backupPath);
        }

        return array(
            'available' => true,
            'configured' => true,
            'restored' => $restoredFromBackup || !is_file($target),
            'restoredFromBackup' => $restoredFromBackup,
            'backupPath' => $backupPath,
            'target' => $this->diagnosePath($target),
            'legacyTarget' => $this->diagnosePath($legacyTarget),
            'legacyRemoved' => $legacyRemoved,
            'message' => $restoredFromBackup
                ? 'Custom nginx template restored from backup.'
                : 'Managed custom nginx template removed.',
        );
    }

    private function buildStatusMessage(
        $sourceExists,
        $targetExists,
        $sourceHash,
        $targetHash
    ) {
        if (!$sourceExists) {
            return 'Bundled template source is missing.';
        }
        if (!$targetExists) {
            return 'Custom nginx template is not installed yet.';
        }
        if ($sourceHash === $targetHash) {
            return 'Managed custom nginx template is installed.';
        }
        return 'Managed custom nginx template differs from the bundled version and should be refreshed.';
    }

    private function buildBackupPath($target)
    {
        return $target . '.bak-' . gmdate('YmdHis');
    }

    private function findLatestBackupPath($target)
    {
        $candidates = glob($target . '.bak-*');
        if (false === $candidates || empty($candidates)) {
            return null;
        }

        rsort($candidates);
        return $candidates[0];
    }

    private function hashesMatch($sourceHash, $targetHash)
    {
        return is_string($sourceHash)
            && is_string($targetHash)
            && '' !== $sourceHash
            && '' !== $targetHash
            && hash_equals($sourceHash, $targetHash);
    }

    private function writeAtomic($target, $content)
    {
        $temporary = tempnam(dirname($target), '.skamasle-ols-template.');
        if (false === $temporary) {
            return array(
                'written' => false,
                'error' => 'Unable to create a temporary nginx template.',
            );
        }

        try {
            if (false === file_put_contents($temporary, $content, LOCK_EX)) {
                return array(
                    'written' => false,
                    'error' => 'Unable to write the temporary nginx template.',
                );
            }
            if (!chmod($temporary, 0644)) {
                return array(
                    'written' => false,
                    'error' => 'Unable to set nginx template permissions.',
                );
            }
            if (!rename($temporary, $target)) {
                return array(
                    'written' => false,
                    'error' => 'Unable to replace the installed nginx template.',
                );
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return array('written' => true);
    }

    private function removeManagedLegacyTemplate($legacyTarget, $sourceHash)
    {
        if (!is_file($legacyTarget) || is_link($legacyTarget)) {
            return false;
        }
        if ($sourceHash !== hash_file('sha256', $legacyTarget)) {
            return false;
        }

        return unlink($legacyTarget);
    }

    private function ensureDirectory($directory)
    {
        if (is_link($directory)) {
            throw new RuntimeException(
                'Template directory cannot be a symlink: ' . $directory
            );
        }
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    'Unable to create template directory: ' . $directory
                );
            }
        }
    }

    private function removeEmptyParentDirectories($directory)
    {
        $root = $this->customRoot;
        while (is_string($directory) && '' !== $directory && 0 === strpos($directory, $root)) {
            if (!is_dir($directory) || is_link($directory)) {
                break;
            }
            $entries = array_diff(scandir($directory), array('.', '..'));
            if (!empty($entries)) {
                break;
            }
            @rmdir($directory);
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
    }

    private function diagnosePath($path)
    {
        clearstatcache(true, $path);

        return array(
            'path' => $path,
            'exists' => file_exists($path) || is_link($path),
            'isFile' => is_file($path),
            'isDirectory' => is_dir($path),
            'isLink' => is_link($path),
            'readable' => is_readable($path),
            'writable' => is_writable($path),
            'permissions' => file_exists($path) || is_link($path)
                ? substr(sprintf('%o', fileperms($path)), -4)
                : null,
            'size' => is_file($path) ? filesize($path) : null,
        );
    }
}
