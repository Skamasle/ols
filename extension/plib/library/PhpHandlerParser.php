<?php

class Modules_SkamasleOls_PhpHandlerParser
{
    public function parse($output)
    {
        $items = array();
        $unparsed = 0;
        $lines = preg_split('/\R/', (string) $output);
        $pattern = '/^\s*(\S+)\s+(\S+)\s+(\S+)\s+(\d+\.\d+)\s+'
            . '(cgi|fastcgi|fpm|fpm-dedicated)\s+(\S+)\s+(\S+)\s+(\S+)\s+'
            . '(true|false)\s+(enabled|disabled)\s*$/';

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || 0 === strpos($line, 'id:')) {
                continue;
            }

            if (!preg_match($pattern, $line, $matches)) {
                $unparsed++;
                continue;
            }

            $lsphp = '/opt/plesk/php/' . $matches[4] . '/bin/lsphp';
            $items[] = array(
                'id' => $matches[1],
                'displayVersion' => $matches[2],
                'fullVersion' => $matches[3],
                'branch' => $matches[4],
                'type' => $matches[5],
                'binary' => $matches[6],
                'cli' => $matches[7],
                'ini' => $matches[8],
                'custom' => 'true' === $matches[9],
                'status' => $matches[10],
                'lsapiBinary' => $lsphp,
                'lsapiBinaryPresent' => is_file($lsphp),
                'lsapiBinaryExecutable' => is_executable($lsphp),
            );
        }

        return array(
            'items' => $items,
            'unparsedLineCount' => $unparsed,
        );
    }
}
