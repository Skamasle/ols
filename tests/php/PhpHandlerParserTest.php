<?php

require_once __DIR__ . '/../../extension/plib/library/PhpHandlerParser.php';

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

$output = <<<'OUTPUT'
id: display name full version version type cgi-bin cli php.ini custom status
plesk-php83-fpm 8.3.20 8.3.20 8.3 fpm /opt/plesk/php/8.3/sbin/php-fpm /opt/plesk/php/8.3/bin/php /opt/plesk/php/8.3/etc/php.ini false enabled
plesk-php84-dedicated 8.4.8 8.4.8 8.4 fpm-dedicated /opt/plesk/php/8.4/sbin/php-fpm /opt/plesk/php/8.4/bin/php /opt/plesk/php/8.4/etc/php.ini false enabled
malformed handler line
OUTPUT;

$parser = new Modules_SkamasleOls_PhpHandlerParser();
$result = $parser->parse($output);

assertSameValue(2, count($result['items']), 'Both supported handlers must parse');
assertSameValue(1, $result['unparsedLineCount'], 'Malformed lines must be counted');
assertSameValue('fpm', $result['items'][0]['type'], 'FPM type must be preserved');
assertSameValue(
    'fpm-dedicated',
    $result['items'][1]['type'],
    'Dedicated FPM type must be preserved'
);
assertSameValue(
    '/opt/plesk/php/8.4/bin/lsphp',
    $result['items'][1]['lsapiBinary'],
    'LSPHP path must use the handler branch'
);
