<?php

require_once __DIR__
    . '/../../extension/plib/library/PleskTemplateManager.php';

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

$bundledManager = new Modules_SkamasleOls_PleskTemplateManager();
assertSameValue(
    true,
    is_file($bundledManager->getSourcePath()),
    'Default manager source must resolve inside the installed plib tree'
);
assertSameValue(
    'plib/templates/custom/domain/service/proxy.php',
    implode('/', array_slice(
        explode('/', $bundledManager->getSourcePath()),
        -6
    )),
    'Bundled template must use the plib custom template path'
);
$bundledTemplate = file_get_contents($bundledManager->getSourcePath());
assertSameValue(
    false,
    false !== strpos($bundledTemplate, '$skamasle_ols_proxy_pass'),
    'Bundled template must not contain the obsolete proxy target variable'
);
assertSameValue(
    true,
    false !== strpos($bundledTemplate, "'https://127.0.0.1:' . \$skamasleOlsPort"),
    'Bundled template must generate the OLS HTTPS target from the routing port'
);
assertSameValue(
    true,
    false !== strpos($bundledTemplate, 'proxy_pass <?= $skamasleProxyTarget ?>;'),
    'Bundled template must emit one resolved proxy_pass target'
);

$root = sys_get_temp_dir() . '/skamasle-ols-template-' . bin2hex(random_bytes(6));
$sourceRoot = $root . '/templates/custom';
$customRoot = $root . '/admin/conf/templates/custom';
$sourceTemplatePath = $sourceRoot . '/domain/service/proxy.php';
$templatePath = $customRoot . '/domain/service/proxy.php';
$legacyTemplatePath = $customRoot . '/default/domain/service/proxy.php';
mkdir($sourceRoot . '/domain/service', 0700, true);
mkdir($customRoot . '/domain/service', 0700, true);
mkdir($customRoot . '/default/domain/service', 0700, true);
file_put_contents(
    $sourceTemplatePath,
    "<?php\n// bundled template\n"
);
file_put_contents(
    $templatePath,
    "<?php\n// legacy template\n"
);
file_put_contents(
    $legacyTemplatePath,
    "<?php\n// bundled template\n"
);

$manager = new Modules_SkamasleOls_PleskTemplateManager(
    $sourceRoot,
    $customRoot
);
$status = $manager->status();

assertSameValue(true, $status['available'], 'Bundled template must exist');
assertSameValue(true, $status['customTemplateExists'], 'Custom template must be detected');
assertSameValue(true, $status['backupRequired'], 'A different template must require backup');

$install = $manager->install();
assertSameValue(true, $install['configured'], 'Template installation must succeed');
assertSameValue(true, $install['verified'], 'Installed template must pass SHA-256 verification');
assertSameValue(
    $install['sourceHash'],
    $install['targetHash'],
    'Bundled and installed template hashes must match'
);
assertSameValue(true, $install['backupCreated'], 'A backup must be created for foreign templates');
assertSameValue(true, is_file($install['backupPath']), 'Backup file must exist');
assertSameValue(
    "<?php\n// legacy template\n",
    file_get_contents($install['backupPath']),
    'Backup file must preserve the original template'
);
assertSameValue(
    "<?php\n// bundled template\n",
    file_get_contents($manager->getTargetPath()),
    'Custom template must be replaced with the bundled version'
);
assertSameValue(
    false,
    is_file($legacyTemplatePath),
    'Managed template from the incorrect custom/default path must be removed'
);

$restore = $manager->restore();
assertSameValue(true, $restore['configured'], 'Template restoration must succeed');
assertSameValue(true, $restore['restoredFromBackup'], 'Restoration must use the saved backup');
assertSameValue(
    "<?php\n// legacy template\n",
    file_get_contents($manager->getTargetPath()),
    'Custom template must be restored from the backup'
);
assertSameValue(
    false,
    is_file($install['backupPath']),
    'Backup file must be removed after restoration'
);

$statusAfter = $manager->status();
assertSameValue(false, $statusAfter['installed'], 'Restored legacy template must not be reported as managed');
assertSameValue(true, $statusAfter['backupRequired'], 'Restored legacy template must require backup');
assertSameValue(false, $statusAfter['hashesMatch'], 'Status must report mismatched SHA-256 hashes');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}
if (is_dir($root)) {
    rmdir($root);
}
