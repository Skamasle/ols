<?php

require_once __DIR__ . '/../library/PleskTemplateManager.php';
require_once __DIR__ . '/../library/LiteSpeedEnterpriseDetector.php';
require_once __DIR__ . '/../library/TemplateInstaller.php';

class IndexController extends pm_Controller_Action
{
    const MODULE_VERSION = '0.1.0';
    const PROJECT_URL = 'https://skamasle.com';
    const GIT_URL = 'https://github.com/Skamasle/ols';

    public function init()
    {
        parent::init();

        $session = new pm_Session();
        if (!$session->getClient()->isAdmin()) {
            throw new pm_Exception('Administrator access is required.');
        }
    }

    public function indexAction()
    {
        $this->populateIndexView();
    }

    public function diagnosticsAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        $bundle = new Modules_SkamasleOls_DiagnosticBundle();
        $json = $bundle->toJson();
        $filename = 'skamasle-ols-diagnostics-' . gmdate('Ymd-His') . '.json';

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $filename . '"',
                true
            )
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setBody($json);
    }

    public function installEngineAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Install OpenLiteSpeed requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $installOptions = $this->readInstallEngineOptions();
        if (false === $installOptions) {
            $this->populateIndexView(
                'Invalid OpenLiteSpeed provisioning options.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $enterpriseDetector = new Modules_SkamasleOls_LiteSpeedEnterpriseDetector();
        $enterpriseStatus = $enterpriseDetector->detect();
        if (!empty($enterpriseStatus['installed'])) {
            $message = 'LiteSpeed Enterprise is already installed. This module will not install OpenLiteSpeed on top of it.';
            if (!empty($enterpriseStatus['evidence'])) {
                $message .= ' Evidence: ' . implode(', ', $enterpriseStatus['evidence']) . '.';
            }
            $this->populateIndexView($message, 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $installer = new Modules_SkamasleOls_EngineInstaller();
        $result = $installer->install($installOptions);
        if (empty($result['available'])) {
            $this->populateIndexView(
                isset($result['error']) ? $result['error'] : 'Unknown error',
                'error'
            );
        } else {
            if (!empty($result['listener']['port'])) {
                pm_Settings::set(
                    'listener.port',
                    (string) $result['listener']['port']
                );
            }
            $modeLabel = $this->installModeLabel(
                isset($installOptions['mode']) ? $installOptions['mode'] : ''
            );
            $this->populateIndexView(
                'OpenLiteSpeed installation completed using '
                . $modeLabel
                . '. Receipt at '
                . $result['planFile'],
                'success'
            );
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function uninstallEngineAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Uninstall OpenLiteSpeed requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $client = new Modules_SkamasleOls_EngineInstaller();
        try {
            $restoredDomains = $this->restoreNativeRouting($client, false);
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Unable to restore native routing: '
                . $exception->getMessage()
            );
            $this->populateIndexView(
                'OpenLiteSpeed was not removed because nginx native routing could not be restored.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $templateInstaller = new Modules_SkamasleOls_TemplateInstaller();
        $templateRestore = $templateInstaller->restore();
        if (empty($templateRestore['available'])) {
            $this->populateIndexView(
                isset($templateRestore['error'])
                    ? $templateRestore['error']
                    : 'Unable to restore the nginx custom template before uninstalling OpenLiteSpeed.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        try {
            $this->refreshDomains($restoredDomains);
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Unable to regenerate native nginx configuration: '
                . $exception->getMessage()
            );
            $this->populateIndexView(
                'OpenLiteSpeed was not removed because native nginx configuration could not be regenerated.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $result = $client->run(array('uninstall-engine'));
        if (empty($result['available'])) {
            $this->populateIndexView(
                isset($result['error']) ? $result['error'] : 'Unknown error',
                'error'
            );
        } else {
            $this->populateIndexView(
                'OpenLiteSpeed removed. Repository and receipt cleaned up.',
                'success'
            );
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function installTemplateAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Installing the nginx custom template requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $installer = new Modules_SkamasleOls_TemplateInstaller();
        $statusResult = $installer->status();
        $status = !empty($statusResult['available'])
            && isset($statusResult['template'])
            && is_array($statusResult['template'])
            ? $statusResult['template']
            : array();
        if (empty($statusResult['available'])) {
            $this->populateIndexView(
                isset($statusResult['error'])
                    ? $statusResult['error']
                    : 'Unable to verify the nginx custom template.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        $overwriteRequested = '1' === (string) $this->getRequest()->getPost('overwrite_existing', '0');
        if (!empty($status['customTemplateExists'])
            && !$overwriteRequested
            && !empty($status['refreshRequired'])
        ) {
            $this->populateIndexView(
                'A different custom nginx template already exists. Check the confirmation box to back it up and replace it.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        $result = $installer->install();
        if (empty($result['available'])) {
            $this->populateIndexView(
                isset($result['error']) ? $result['error'] : 'Unable to install the nginx custom template.',
                'error'
            );
        } else {
            $template = isset($result['template']) && is_array($result['template'])
                ? $result['template']
                : array();
            if (empty($template['verified'])
                || empty($template['sourceHash'])
                || empty($template['targetHash'])
                || !hash_equals($template['sourceHash'], $template['targetHash'])
            ) {
                $this->populateIndexView(
                    'The nginx template copy could not be verified by SHA-256.',
                    'error'
                );
                $this->_helper->viewRenderer->setScriptAction('index');
                return;
            }
            try {
                $this->refreshAllDomains();
            } catch (Throwable $exception) {
                error_log(
                    '[skamasle-ols] nginx regeneration after template install failed: '
                    . $exception->getMessage()
                );
                $this->populateIndexView(
                    'The nginx template was updated, but at least one domain could not be regenerated.',
                    'error'
                );
                $this->_helper->viewRenderer->setScriptAction('index');
                return;
            }
            $message = !empty($template['backupCreated'])
                ? 'Custom nginx template installed. Existing template backed up at ' . $template['backupPath'] . '.'
                : 'Custom nginx template installed.';
            $message .= ' Plesk nginx configuration was regenerated for all domains.';
            $this->populateIndexView($message, 'success');
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function setDomainRoutingAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Changing domain routing requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        $routing = (string) $this->getRequest()->getPost('routing', '');
        $domain = $this->findDomainByGuid($guid);
        if (null === $domain || !in_array($routing, array('native', 'ols'), true)) {
            $this->populateIndexView('Invalid domain routing request.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        if ('ols' === $routing
            && '1' !== $domain->getSetting('skamasle-ols.prepared', '0')
        ) {
            $this->populateIndexView(
                'Stage and validate the OLS vhost before activation.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        $templateStatusResult = (new Modules_SkamasleOls_TemplateInstaller())->status();
        $templateStatus = !empty($templateStatusResult['available'])
            && isset($templateStatusResult['template'])
            && is_array($templateStatusResult['template'])
            ? $templateStatusResult['template']
            : array();
        if ('ols' === $routing && empty($templateStatus['installed'])) {
            $this->populateIndexView(
                'Install or refresh the nginx custom template before enabling OLS routing.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $previousRouting = $domain->getSetting(
            'skamasle-ols.routing',
            'native'
        );
        $client = new Modules_SkamasleOls_EngineInstaller();
        $result = $client->run(array('set-domain-routing', $guid, $routing));
        if (empty($result['available'])) {
            $this->populateIndexView(
                isset($result['error']) ? $result['error'] : 'Unknown error',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $message = 'Domain routing updated to ' . $routing . '.';

        try {
            $domain->setSetting('skamasle-ols.routing', $routing);
            $webServer = new pm_WebServer();
            $webServer->updateDomainConfiguration($domain);
            if ('native' === $routing && 'ols' === $previousRouting) {
                $cleanupResult = $client->run(array('reset-domain-vhost', $guid));
                if (empty($cleanupResult['available'])) {
                    throw new RuntimeException(
                        isset($cleanupResult['error'])
                            ? $cleanupResult['error']
                            : 'Unable to clear OLS domain artifacts.'
                    );
                }
                $domain->setSetting('skamasle-ols.prepared', '0');
                $domain->setSetting('skamasle-ols.lscache', '0');
                $domain->setSetting('skamasle-ols.lscache_private', '0');
                $domain->setSetting('skamasle-ols.lsapi', '');
                $domain->setSetting('skamasle-ols.routing', 'native');
                $message = 'Domain routing updated to native and OLS vhost removed.';
            }
        } catch (Throwable $exception) {
            $domain->setSetting('skamasle-ols.routing', $previousRouting);
            $client->run(array(
                'set-domain-routing',
                $guid,
                $previousRouting,
            ));
            try {
                $webServer->updateDomainConfiguration($domain);
            } catch (Throwable $rollbackException) {
                error_log(
                    '[skamasle-ols] Domain nginx rollback failed: '
                    . $rollbackException->getMessage()
                );
            }
            error_log(
                '[skamasle-ols] Domain routing update failed: '
                . $exception->getMessage()
            );
            $this->populateIndexView(
                'Plesk could not regenerate the domain nginx configuration.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $this->populateIndexView($message, 'success');
        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function setListenerPortAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Changing the listener port requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $port = (int) $this->getRequest()->getPost('listener_port', 0);
        $client = new Modules_SkamasleOls_EngineInstaller();
        $result = $client->run(array('set-listener-port', (string) $port));
        if (empty($result['available'])) {
            $this->populateIndexView(
                isset($result['error']) ? $result['error'] : 'Unknown error',
                'error'
            );
        } else {
            pm_Settings::set('listener.port', (string) $port);
            try {
                $this->refreshActiveOlsDomains();
                $this->populateIndexView(
                    'Listener port updated to ' . $port . '.',
                    'success'
                );
            } catch (Throwable $exception) {
                error_log(
                    '[skamasle-ols] nginx regeneration after port update failed: '
                    . $exception->getMessage()
                );
                $this->populateIndexView(
                    'The OLS port changed, but at least one nginx domain configuration could not be regenerated.',
                    'error'
                );
            }
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function setDomainCacheAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Changing LSCache requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        $domainName = (string) $this->getRequest()->getPost('domain_name', '');
        $enabled = '1' === (string) $this->getRequest()->getPost('cache_enabled', '0');
        $privateEnabled = $enabled
            && '1' === (string) $this->getRequest()->getPost(
                'cache_private_enabled',
                '0'
            );
        $domain = $this->findDomainByGuid($guid, $domainName);
        if (null === $domain) {
            error_log(
                '[skamasle-ols] LSCache toggle could not resolve domain: guid='
                . $guid . ' name=' . $domainName
            );
            $this->populateIndexView('Domain not found in Plesk.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        if ('1' !== $domain->getSetting('skamasle-ols.prepared', '0')) {
            $this->populateIndexView(
                'Stage the OLS vhost before changing LSCache.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $previousEnabled = '1' === $domain->getSetting(
            'skamasle-ols.lscache',
            '0'
        );
        $previousPrivateEnabled = '1' === $domain->getSetting(
            'skamasle-ols.lscache_private',
            '0'
        );
        try {
            $domain->setSetting(
                'skamasle-ols.lscache',
                $enabled ? '1' : '0'
            );
            $domain->setSetting(
                'skamasle-ols.lscache_private',
                $privateEnabled ? '1' : '0'
            );
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Unable to persist LSCache domain setting: '
                . $exception->getMessage()
            );
            $this->populateIndexView(
                'Plesk could not persist the LSCache domain setting.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $client = new Modules_SkamasleOls_EngineInstaller();
        $domainPayload = $this->buildDomainPayload($domain);
        $domainPayloadJson = json_encode($domainPayload, JSON_UNESCAPED_SLASHES);
        if (false === $domainPayloadJson) {
            $this->populateIndexView('Unable to encode domain data.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        $result = $client->run(array(
            'set-domain-cache',
            trim((string) $domain->getGuid(), '{}'),
            $enabled ? '1' : '0',
            $privateEnabled ? '1' : '0',
            $domain->getName(),
            $domainPayloadJson,
        ));
        if (empty($result['available'])) {
            try {
                $domain->setSetting(
                    'skamasle-ols.lscache',
                    $previousEnabled ? '1' : '0'
                );
                $domain->setSetting(
                    'skamasle-ols.lscache_private',
                    $previousPrivateEnabled ? '1' : '0'
                );
            } catch (Throwable $exception) {
                error_log(
                    '[skamasle-ols] Unable to roll back LSCache domain setting: '
                    . $exception->getMessage()
                );
            }
            $this->populateIndexView(
                (isset($result['error']) ? $result['error'] : 'Unknown error')
                . (!empty($result['logPath'])
                    ? ' Debug log: ' . $result['logPath'] . '.'
                    : ''),
                'error'
            );
        } else {
            $this->populateIndexView(
                'LSCache ' . ($enabled ? 'enabled' : 'disabled')
                . ($enabled
                    ? ($privateEnabled ? ' with private cache' : ' with public cache only')
                    : '')
                . ' for ' . $domain->getName() . '.',
                'success'
            );
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function setDomainLsapiAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Changing LSAPI settings requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        $domainName = (string) $this->getRequest()->getPost('domain_name', '');
        $domain = $this->findDomainByGuid($guid, $domainName);
        if (null === $domain) {
            $this->populateIndexView('Domain not found in Plesk.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        if ('1' !== $domain->getSetting('skamasle-ols.prepared', '0')) {
            $this->populateIndexView(
                'Stage the OLS vhost before changing LSAPI settings.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        try {
            $settings = $this->readDomainLsapiSettings();
        } catch (Throwable $exception) {
            $this->populateIndexView($exception->getMessage(), 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
        if (false === $settingsJson) {
            $this->populateIndexView('Unable to encode LSAPI settings.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $previousSettings = (string) $domain->getSetting('skamasle-ols.lsapi', '');
        $domainPayload = $this->buildDomainPayload($domain);
        $domainPayloadJson = json_encode($domainPayload, JSON_UNESCAPED_SLASHES);
        if (false === $domainPayloadJson) {
            $this->populateIndexView('Unable to encode domain data.', 'error');
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        try {
            $domain->setSetting('skamasle-ols.lsapi', $settingsJson);
        } catch (Throwable $exception) {
            $this->populateIndexView(
                'Plesk could not persist the LSAPI domain settings.',
                'error'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $client = new Modules_SkamasleOls_EngineInstaller();
        $result = $client->run(array(
            'set-domain-lsapi',
            trim((string) $domain->getGuid(), '{}'),
            $settingsJson,
            $domain->getName(),
            $domainPayloadJson,
        ));
        if (empty($result['available'])) {
            try {
                $domain->setSetting('skamasle-ols.lsapi', $previousSettings);
            } catch (Throwable $exception) {
                error_log(
                    '[skamasle-ols] Unable to roll back LSAPI domain setting: '
                    . $exception->getMessage()
                );
            }
            $this->populateIndexView(
                (isset($result['error']) ? $result['error'] : 'Unknown error')
                . (!empty($result['logPath'])
                    ? ' Debug log: ' . $result['logPath'] . '.'
                    : ''),
                'error'
            );
        } else {
            $this->populateIndexView(
                'LSAPI settings updated for ' . $domain->getName() . '.',
                'success'
            );
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function scanDomainHtaccessAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Scanning .htaccess requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        $depthInput = trim((string) $this->getRequest()->getPost('scan_depth', '2'));

        try {
            $domain = $this->findDomainByGuid($guid);
            if (null === $domain) {
                throw new RuntimeException('Domain not found in Plesk.');
            }
            if (!method_exists($domain, 'hasHosting') || !$domain->hasHosting()) {
                throw new RuntimeException('Physical web hosting is required.');
            }

            $documentRoot = (string) $domain->getDocumentRoot();
            $scanner = new Modules_SkamasleOls_HtaccessScanner();
            $depth = $this->normalizeHtaccessScanDepth($depthInput);
            $result = $scanner->scan($documentRoot, $depth);
            $storedResult = $this->compactHtaccessScanResult($result, $depthInput);

            $domain->setSetting(
                Modules_SkamasleOls_DomainInventory::HTACCESS_SCAN_SETTING,
                json_encode($storedResult, JSON_UNESCAPED_SLASHES)
            );

            $message = '.htaccess scan completed for ' . $domain->getName() . '.';
            if ('all' === $depthInput) {
                $message .= ' Full vhost depth was requested.';
            } else {
                $message .= ' Depth limit: ' . $depth . '.';
            }
            $this->populateIndexView($message, 'success');
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] .htaccess scan failed: '
                . $exception->getMessage()
            );
            $this->populateIndexView($exception->getMessage(), 'error');
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function prepareDomainVhostAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Staging a domain vhost requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        try {
            $client = new Modules_SkamasleOls_EngineInstaller();
            $domain = $this->findDomainByGuid($guid);
            if (null === $domain) {
                throw new RuntimeException('Domain not found in Plesk.');
            }

            $payload = $this->buildDomainPayload($domain);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (false === $payloadJson) {
                throw new RuntimeException('Unable to encode domain data.');
            }

            $result = $client->run(array(
                'prepare-domain-vhost',
                $guid,
                $payloadJson,
            ));
            if (empty($result['available'])) {
                $message = isset($result['error']) ? $result['error'] : 'Unknown error';
                if (!empty($result['details']['config']['output'])) {
                    $message .= ' ' . trim((string) $result['details']['config']['output']);
                }
                if (!empty($result['details']['socketPath'])) {
                    $message .= ' Socket path: ' . $result['details']['socketPath'] . '.';
                }
                if (!empty($result['logPath'])) {
                    $message .= ' Debug log: ' . $result['logPath'] . '.';
                }
                throw new RuntimeException($message);
            }

            $domain->setSetting('skamasle-ols.prepared', '1');
            $webServer = new pm_WebServer();
            $webServer->updateDomainConfiguration($domain);

            $message = 'Domain vhost staged at ' . $result['vhost']['path']
                . '. Plesk nginx configuration regenerated.';
            if (!empty($result['logPath'])) {
                $message .= ' Debug log: ' . $result['logPath'] . '.';
            }
            $this->populateIndexView($message, 'success');
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Stage domain vhost failed: '
                . $exception->getMessage()
            );
            $this->populateIndexView($exception->getMessage(), 'error');
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function stageAndEnableDomainVhostAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Staging and enabling a domain vhost requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        try {
            $client = new Modules_SkamasleOls_EngineInstaller();
            $domain = $this->findDomainByGuid($guid);
            if (null === $domain) {
                throw new RuntimeException('Domain not found in Plesk.');
            }

            $payload = $this->buildDomainPayload($domain);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (false === $payloadJson) {
                throw new RuntimeException('Unable to encode domain data.');
            }

            $stageResult = $client->run(array(
                'prepare-domain-vhost',
                $guid,
                $payloadJson,
            ));
            if (empty($stageResult['available'])) {
                $message = isset($stageResult['error']) ? $stageResult['error'] : 'Unknown error';
                if (!empty($stageResult['details']['config']['output'])) {
                    $message .= ' ' . trim((string) $stageResult['details']['config']['output']);
                }
                if (!empty($stageResult['details']['socketPath'])) {
                    $message .= ' Socket path: ' . $stageResult['details']['socketPath'] . '.';
                }
                if (!empty($stageResult['logPath'])) {
                    $message .= ' Debug log: ' . $stageResult['logPath'] . '.';
                }
                throw new RuntimeException($message);
            }

            $domain->setSetting('skamasle-ols.prepared', '1');
            $webServer = new pm_WebServer();
            $webServer->updateDomainConfiguration($domain);

            $templateStatusResult = (new Modules_SkamasleOls_TemplateInstaller())->status();
            $templateStatus = !empty($templateStatusResult['available'])
                && isset($templateStatusResult['template'])
                && is_array($templateStatusResult['template'])
                ? $templateStatusResult['template']
                : array();
            if (empty($templateStatus['installed'])) {
                $this->populateIndexView(
                    'Domain vhost staged, but OLS could not be enabled because the nginx custom template is not installed.',
                    'warning'
                );
                $this->_helper->viewRenderer->setScriptAction('index');
                return;
            }

            $previousRouting = $domain->getSetting('skamasle-ols.routing', 'native');
            $routingResult = $client->run(array(
                'set-domain-routing',
                $guid,
                'ols',
            ));
            if (empty($routingResult['available'])) {
                $message = 'Domain vhost staged, but OLS activation failed.';
                if (isset($routingResult['error'])) {
                    $message .= ' ' . $routingResult['error'];
                }
                if (!empty($routingResult['logPath'])) {
                    $message .= ' Debug log: ' . $routingResult['logPath'] . '.';
                }
                $this->populateIndexView($message, 'error');
                $this->_helper->viewRenderer->setScriptAction('index');
                return;
            }

            try {
                $domain->setSetting('skamasle-ols.routing', 'ols');
                $webServer->updateDomainConfiguration($domain);
            } catch (Throwable $exception) {
                $domain->setSetting('skamasle-ols.routing', $previousRouting);
                $client->run(array(
                    'set-domain-routing',
                    $guid,
                    $previousRouting,
                ));
                try {
                    $webServer->updateDomainConfiguration($domain);
                } catch (Throwable $rollbackException) {
                    error_log(
                        '[skamasle-ols] Domain nginx rollback failed: '
                        . $rollbackException->getMessage()
                    );
                }
                error_log(
                    '[skamasle-ols] Stage-and-enable routing update failed: '
                    . $exception->getMessage()
                );
                $this->populateIndexView(
                    'Domain vhost was staged, but OLS routing could not be enabled.',
                    'error'
                );
                $this->_helper->viewRenderer->setScriptAction('index');
                return;
            }

            $message = 'Domain vhost staged and OLS routing enabled.';
            if (!empty($stageResult['logPath'])) {
                $message .= ' Debug log: ' . $stageResult['logPath'] . '.';
            }
            $this->populateIndexView($message, 'success');
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Stage and enable domain vhost failed: '
                . $exception->getMessage()
            );
            $this->populateIndexView($exception->getMessage(), 'error');
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    public function resetDomainVhostAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->populateIndexView(
                'Resetting the OLS vhost requires a POST request.',
                'warning'
            );
            $this->_helper->viewRenderer->setScriptAction('index');
            return;
        }

        $guid = (string) $this->getRequest()->getPost('domain_guid', '');
        try {
            $client = new Modules_SkamasleOls_EngineInstaller();
            $domain = $this->findDomainByGuid($guid);
            if (null === $domain) {
                throw new RuntimeException('Domain not found in Plesk.');
            }

            $result = $client->run(array('reset-domain-vhost', $guid));
            if (empty($result['available'])) {
                throw new RuntimeException(
                    isset($result['error']) ? $result['error'] : 'Unknown error'
                );
            }

            $domain->setSetting('skamasle-ols.prepared', '0');
            $domain->setSetting('skamasle-ols.routing', 'native');
            $webServer = new pm_WebServer();
            $webServer->updateDomainConfiguration($domain);

            $message = 'Domain OLS staging was cleared and Plesk nginx was regenerated.';
            if (!empty($result['cleanup']['removed']) && is_array($result['cleanup']['removed'])) {
                $message .= ' Removed ' . count($result['cleanup']['removed']) . ' OLS artifacts.';
            }
            $this->populateIndexView($message, 'success');
        } catch (Throwable $exception) {
            error_log(
                '[skamasle-ols] Reset domain vhost failed: '
                . $exception->getMessage()
            );
            $this->populateIndexView($exception->getMessage(), 'error');
        }

        $this->_helper->viewRenderer->setScriptAction('index');
    }

    private function populateIndexView($message = null, $messageType = null)
    {
        $detector = new Modules_SkamasleOls_CapabilityDetector();
        $inventory = new Modules_SkamasleOls_DomainInventory();
        $controlStatus = new Modules_SkamasleOls_ControlPlaneStatus();
        $request = $this->getRequest();
        $domainSearch = trim((string) $request->getParam('domain_search', ''));
        $domainPage = max(1, (int) $request->getParam('domain_page', 1));
        $domainPageSize = max(1, (int) $request->getParam('domain_page_size', Modules_SkamasleOls_DomainInventory::DEFAULT_PAGE_SIZE));

        $this->view->capabilities = $detector->detect();
        $this->view->domains = $inventory->getSummary($domainSearch, $domainPage, $domainPageSize);
        $this->view->domainError = $inventory->getLastError();
        $this->view->controlStatus = $controlStatus->get();
        $this->view->domainSearch = $domainSearch;
        $this->view->domainPage = $domainPage;
        $this->view->domainPageSize = $domainPageSize;
        $this->view->diagnosticsUrl = pm_Context::getBaseUrl()
            . 'index.php/index/diagnostics';
        $this->view->installEngineUrl = pm_Context::getBaseUrl()
            . 'index.php/index/install-engine';
        $this->view->uninstallEngineUrl = pm_Context::getBaseUrl()
            . 'index.php/index/uninstall-engine';
        $this->view->setListenerPortUrl = pm_Context::getBaseUrl()
            . 'index.php/index/set-listener-port';
        $this->view->setDomainCacheUrl = pm_Context::getBaseUrl()
            . 'index.php/index/set-domain-cache';
        $this->view->setDomainLsapiUrl = pm_Context::getBaseUrl()
            . 'index.php/index/set-domain-lsapi';
        $this->view->installTemplateUrl = pm_Context::getBaseUrl()
            . 'index.php/index/install-template';
        $this->view->scanDomainHtaccessUrl = pm_Context::getBaseUrl()
            . 'index.php/index/scan-domain-htaccess';
        $this->view->prepareDomainVhostUrl = pm_Context::getBaseUrl()
            . 'index.php/index/prepare-domain-vhost';
        $this->view->stageAndEnableDomainVhostUrl = pm_Context::getBaseUrl()
            . 'index.php/index/stage-and-enable-domain-vhost';
        $this->view->resetDomainVhostUrl = pm_Context::getBaseUrl()
            . 'index.php/index/reset-domain-vhost';
        $this->view->setDomainRoutingUrl = pm_Context::getBaseUrl()
            . 'index.php/index/set-domain-routing';
        $this->view->stylesheetUrl = pm_Context::getBaseUrl()
            . 'assets/skamasle-ols-dashboard.css';
        $this->view->moduleVersion = self::MODULE_VERSION;
        $this->view->projectUrl = self::PROJECT_URL;
        $this->view->gitUrl = self::GIT_URL;
        $this->view->engineMessage = $message;
        $this->view->engineMessageType = $messageType;
        $this->view->installModeDefault = $this->defaultInstallMode();
        $this->view->installModeOptions = $this->installModeOptions();
        $this->view->customRepoUrlDefault = (string) pm_Settings::get(
            'install.customRepoUrl',
            ''
        );
    }

    private function readInstallEngineOptions()
    {
        $mode = (string) $this->getRequest()->getPost(
            'install_mode',
            $this->defaultInstallMode()
        );
        $validModes = array_keys($this->installModeOptions());
        if (!in_array($mode, $validModes, true)) {
            return false;
        }

        $options = array('mode' => $mode);
        if ('custom-repo-url' === $mode) {
            $customRepoUrl = trim((string) $this->getRequest()->getPost('custom_repo_url', ''));
            if ('' === $customRepoUrl || false === filter_var($customRepoUrl, FILTER_VALIDATE_URL)) {
                return false;
            }
            $options['customRepoUrl'] = $customRepoUrl;
            pm_Settings::set('install.customRepoUrl', $customRepoUrl);
        }

        pm_Settings::set('install.mode', $mode);

        return $options;
    }

    private function defaultInstallMode()
    {
        $mode = (string) pm_Settings::get(
            'install.mode',
            'recommended-bootstrap'
        );
        if (!array_key_exists($mode, $this->installModeOptions())) {
            return 'recommended-bootstrap';
        }

        return $mode;
    }

    private function installModeOptions()
    {
        return array(
            'recommended-bootstrap' => 'Recommended bootstrap',
            'custom-repo-url' => 'Custom repository URL',
            'repo-ready' => 'Repository already configured',
            'already-installed' => 'OpenLiteSpeed already installed',
        );
    }

    private function installModeLabel($mode)
    {
        $options = $this->installModeOptions();
        return isset($options[$mode]) ? $options[$mode] : 'the selected provisioning mode';
    }

    private function findDomainByGuid($guid, $domainName = null)
    {
        $normalizedGuid = trim((string) $guid, '{}');
        $normalizedName = strtolower(trim((string) $domainName));
        foreach (pm_Domain::getAllDomains() as $domain) {
            if (!is_object($domain)) {
                continue;
            }

            if (method_exists($domain, 'getGuid')
                && '' !== $normalizedGuid
                && 0 === strcasecmp(
                    trim((string) $domain->getGuid(), '{}'),
                    $normalizedGuid
                )
            ) {
                return $domain;
            }

            if ('' !== $normalizedName) {
                $candidateNames = array();
                if (method_exists($domain, 'getName')) {
                    $candidateNames[] = strtolower(trim((string) $domain->getName()));
                }
                if (method_exists($domain, 'getDisplayName')) {
                    $candidateNames[] = strtolower(trim((string) $domain->getDisplayName()));
                }
                if (in_array($normalizedName, $candidateNames, true)) {
                    return $domain;
                }
            }
        }
        return null;
    }

    private function refreshActiveOlsDomains()
    {
        $webServer = new pm_WebServer();
        foreach (pm_Domain::getAllDomains() as $domain) {
            if ('ols' === $domain->getSetting('skamasle-ols.routing', 'native')) {
                $webServer->updateDomainConfiguration($domain);
            }
        }
    }

    private function refreshAllDomains()
    {
        $webServer = new pm_WebServer();
        foreach (pm_Domain::getAllDomains() as $domain) {
            $webServer->updateDomainConfiguration($domain);
        }
    }

    private function buildDomainPayload($domain)
    {
        $name = method_exists($domain, 'getName')
            ? (string) $domain->getName()
            : (string) $domain->getDisplayName();
        $documentRoot = method_exists($domain, 'hasHosting') && $domain->hasHosting()
            ? (string) $domain->getDocumentRoot()
            : '';

        $handlerId = '';
        foreach (array('php_handler_id', 'phpHandlerId') as $property) {
            try {
                $handlerId = trim((string) $domain->getProperty($property));
                if ('' !== $handlerId) {
                    break;
                }
            } catch (Throwable $exception) {
                $handlerId = '';
            }
        }
        $phpVersion = null;
        if (preg_match('/(?:^|[-_])php(\d)(\d)(?:[-_]|$)/i', $handlerId, $matches)) {
            $phpVersion = $matches[1] . '.' . $matches[2];
        } elseif (preg_match('/(?:^|[-_])php(\d+)\.(\d+)(?:[-_]|$)/i', $handlerId, $matches)) {
            $phpVersion = $matches[1] . '.' . $matches[2];
        }
        $cacheEnabled = '1' === $domain->getSetting('skamasle-ols.lscache', '0');
        $payload = array(
            'guid' => trim((string) $domain->getGuid(), '{}'),
            'pleskId' => method_exists($domain, 'getId') ? (int) $domain->getId() : 0,
            'name' => strtolower($name),
            'documentRoot' => $documentRoot,
            'vhostRoot' => dirname(rtrim($documentRoot, '/')),
            'phpIniDir' => '/var/www/vhosts/system/' . strtolower($name) . '/etc',
            'systemUser' => method_exists($domain, 'getSysUserLogin')
                ? (string) $domain->getSysUserLogin()
                : 'psacln',
            'systemGroup' => method_exists($domain, 'getSysGroupLogin')
                ? (string) $domain->getSysGroupLogin()
                : 'psacln',
            'phpHandlerId' => $handlerId,
            'cacheEnabled' => $cacheEnabled,
            'cachePrivateEnabled' => $cacheEnabled
                && '1' === $domain->getSetting('skamasle-ols.lscache_private', '0'),
            'lsapi' => $this->domainLsapiSettings($domain),
            'requestedRouting' => $domain->getSetting(
                'skamasle-ols.routing',
                'native'
            ),
        );
        if (null !== $phpVersion) {
            $payload['phpVersion'] = $phpVersion;
        }

        return $payload;
    }

    private function readDomainLsapiSettings()
    {
        $fields = array(
            'maxConnections' => array('lsapi_max_connections', 1, 1000),
            'instances' => array('lsapi_instances', 1, 100),
            'backlog' => array('lsapi_backlog', 1, 10000),
            'initTimeout' => array('lsapi_init_timeout', 1, 3600),
            'retryTimeout' => array('lsapi_retry_timeout', 0, 3600),
        );
        $settings = array();
        foreach ($fields as $key => $definition) {
            $raw = trim((string) $this->getRequest()->getPost($definition[0], ''));
            if (!preg_match('/^\d+$/', $raw)) {
                throw new InvalidArgumentException(
                    'LSAPI setting ' . $key . ' must be an integer.'
                );
            }
            $value = (int) $raw;
            if ($value < $definition[1] || $value > $definition[2]) {
                throw new InvalidArgumentException(
                    'LSAPI setting ' . $key . ' is outside the allowed range.'
                );
            }
            $settings[$key] = $value;
        }
        $settings['children'] = $settings['maxConnections'];
        $settings['persistentConnection'] = '1' === (string) $this->getRequest()
            ->getPost('lsapi_persistent_connection', '0');
        $settings['responseBuffering'] = '1' === (string) $this->getRequest()
            ->getPost('lsapi_response_buffering', '0');

        return $settings;
    }

    private function domainLsapiSettings($domain)
    {
        $defaults = array(
            'maxConnections' => 8,
            'children' => 8,
            'instances' => 1,
            'backlog' => 100,
            'initTimeout' => 60,
            'retryTimeout' => 0,
            'persistentConnection' => true,
            'responseBuffering' => false,
        );
        $decoded = json_decode(
            (string) $domain->getSetting('skamasle-ols.lsapi', ''),
            true
        );
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($decoded, $defaults));
    }

    private function restoreNativeRouting(
        Modules_SkamasleOls_EngineInstaller $client,
        $regenerate = true
    ) {
        $restoredDomains = array();
        foreach (pm_Domain::getAllDomains() as $domain) {
            if ('ols' !== $domain->getSetting('skamasle-ols.routing', 'native')) {
                continue;
            }
            $guid = (string) $domain->getGuid();
            $result = $client->run(array(
                'set-domain-routing',
                $guid,
                'native',
            ));
            if (empty($result['available'])) {
                throw new RuntimeException(
                    isset($result['error'])
                        ? $result['error']
                        : 'Unable to persist native routing.'
                );
            }
            $domain->setSetting('skamasle-ols.routing', 'native');
            $restoredDomains[] = $domain;
        }

        if ($regenerate) {
            $this->refreshDomains($restoredDomains);
        }

        return $restoredDomains;
    }

    private function refreshDomains(array $domains)
    {
        $webServer = new pm_WebServer();
        foreach ($domains as $domain) {
            $webServer->updateDomainConfiguration($domain);
        }
    }

    private function normalizeHtaccessScanDepth($depthInput)
    {
        if ('all' === $depthInput) {
            return Modules_SkamasleOls_HtaccessScanner::MAX_DEPTH;
        }

        $depth = (int) $depthInput;
        if ($depth < 0 || $depth > Modules_SkamasleOls_HtaccessScanner::MAX_DEPTH) {
            throw new RuntimeException('The selected .htaccess scan depth is invalid.');
        }

        return $depth;
    }

    private function compactHtaccessScanResult(array $result, $depthInput)
    {
        return array(
            'status' => isset($result['status']) ? (string) $result['status'] : 'blocked',
            'filesScanned' => isset($result['filesScanned']) ? (int) $result['filesScanned'] : 0,
            'findingCount' => isset($result['findingCount']) ? (int) $result['findingCount'] : 0,
            'summary' => isset($result['summary']) && is_array($result['summary'])
                ? array_slice($result['summary'], 0, 20)
                : array(),
            'scannedAt' => gmdate('c'),
            'scanDepth' => 'all' === $depthInput ? 'all' : (string) ((int) $depthInput),
        );
    }
}
