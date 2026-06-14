<?php

require_once __DIR__
    . '/../../extension/plib/library/HtaccessScanner.php';

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

$scanner = new Modules_SkamasleOls_HtaccessScanner();
$wordpress = <<<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
HTACCESS;

assertSameValue(
    array(),
    $scanner->analyze($wordpress),
    'WordPress rewrite rules must be compatible'
);

$blocked = <<<'HTACCESS'
AuthType Basic
Require valid-user
Header set X-Frame-Options DENY
UnknownDirective value
HTACCESS;
$findings = $scanner->analyze($blocked, 'private/.htaccess');
assertSameValue(4, count($findings), 'Blocking directives must be reported');
assertSameValue(
    'unsupported-security',
    $findings[0]['classification'],
    'Authentication must block as security-sensitive'
);
assertSameValue(
    'unsupported-behavior',
    $findings[2]['classification'],
    'Header must block as behavior-sensitive'
);
assertSameValue(
    'unknown',
    $findings[3]['classification'],
    'Unknown must require review'
);

$knownOwnCloud = $scanner->analyze(<<<'HTACCESS'
SetEnvIfNoCase Authorization "(.+)" HTTP_AUTHORIZATION=$1
RequestHeader set X-Authorization %{HTTP_AUTHORIZATION}e
HTACCESS
);
assertSameValue(
    'unsupported-behavior',
    $knownOwnCloud[0]['classification'],
    'SetEnvIfNoCase must be a known behavior warning'
);
assertSameValue(
    'unsupported-behavior',
    $knownOwnCloud[1]['classification'],
    'RequestHeader must be a known behavior warning'
);

$continued = "RewriteCond %{HTTP_HOST} \\\n^example\\.test$\n";
assertSameValue(
    array(),
    $scanner->analyze($continued),
    'Continued rewrite directives must parse'
);

$directory = sys_get_temp_dir() . '/skamasle-ols-htaccess-'
    . bin2hex(random_bytes(6));
mkdir($directory . '/public', 0700, true);
file_put_contents($directory . '/.htaccess', "RewriteEngine On\n");
file_put_contents($directory . '/public/.htaccess', "Options -Indexes\n");

try {
    $result = $scanner->scan($directory);
    assertSameValue('review', $result['status'], 'Known directive must warn');
    assertSameValue(2, $result['filesScanned'], 'Both files must be scanned');
    assertSameValue(
        'public/.htaccess',
        $result['findings'][0]['file'],
        'Finding path must be relative'
    );
    assertSameValue(1, $result['summary'][0]['count'], 'Summary must count');
} finally {
    unlink($directory . '/public/.htaccess');
    unlink($directory . '/.htaccess');
    rmdir($directory . '/public');
    rmdir($directory);
}
