<?php

if (stripos(PHP_OS, 'Linux') !== 0) {
    fwrite(STDERR, "This extension currently supports Linux only.\n");
    exit(1);
}

require_once __DIR__ . '/../library/LiteSpeedEnterpriseDetector.php';

$enterpriseDetector = new Modules_SkamasleOls_LiteSpeedEnterpriseDetector();
$enterpriseStatus = $enterpriseDetector->detect();
if (!empty($enterpriseStatus['installed'])) {
    fwrite(
        STDERR,
        "LiteSpeed Enterprise is already installed.\n"
        . "Do not install this extension on top of it.\n"
    );
    if (!empty($enterpriseStatus['evidence'])) {
        fwrite(
            STDERR,
            'Detection evidence: ' . implode(', ', $enterpriseStatus['evidence']) . PHP_EOL
        );
    }
    exit(1);
}
