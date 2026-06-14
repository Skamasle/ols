<?php

require_once __DIR__ . '/../library/EngineInstaller.php';
require_once __DIR__ . '/../library/TemplateInstaller.php';

$olsDomains = array();
foreach (pm_Domain::getAllDomains() as $domain) {
    if ('ols' === $domain->getSetting('skamasle-ols.routing', 'native')) {
        $olsDomains[] = $domain;
    }
}

$client = new Modules_SkamasleOls_EngineInstaller();
foreach ($olsDomains as $domain) {
    $result = $client->run(array(
        'set-domain-routing',
        (string) $domain->getGuid(),
        'native',
    ));
    if (empty($result['available'])) {
        fwrite(
            STDERR,
            'Unable to restore native routing for ' . $domain->getName() . PHP_EOL
        );
        exit(1);
    }

    $domain->setSetting('skamasle-ols.routing', 'native');
}

$templateInstaller = new Modules_SkamasleOls_TemplateInstaller();
$templateRestore = $templateInstaller->restore();
if (empty($templateRestore['available'])) {
    fwrite(
        STDERR,
        'Unable to restore the nginx custom template before uninstalling the extension.' . PHP_EOL
    );
    exit(1);
}

$webServer = new pm_WebServer();
foreach ($olsDomains as $domain) {
    $webServer->updateDomainConfiguration($domain);
}

$uninstall = $client->run(array('uninstall-engine'));
if (empty($uninstall['available'])) {
    fwrite(
        STDERR,
        'Unable to uninstall OpenLiteSpeed during extension cleanup.' . PHP_EOL
    );
    exit(1);
}
