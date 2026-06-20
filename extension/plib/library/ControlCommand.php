<?php

require_once __DIR__ . '/EngineInstallPlanner.php';
require_once __DIR__ . '/EnginePackageInstaller.php';
require_once __DIR__ . '/EnginePackageRemover.php';
require_once __DIR__ . '/LiteSpeedEnterpriseDetector.php';
require_once __DIR__ . '/OlsConfigManager.php';
require_once __DIR__ . '/OlsServiceManager.php';
require_once __DIR__ . '/EnginePlanStore.php';
require_once __DIR__ . '/PleskTemplateManager.php';

class Modules_SkamasleOls_ControlCommand
{
    private $stateStore;
    private $planStore;
    private $planner;
    private $packageInstaller;
    private $packageRemover;
    private $configManager;
    private $serviceManager;
    private $phpHandlerResolutionAttempts = array();
    private $resolvedPhpVersion;
    private $resolvedSystemUser;
    private $resolvedDocumentRoot;
    private $resolvedVhostRoot;

    public function __construct(
        Modules_SkamasleOls_StateStore $stateStore,
        $planner = null,
        $packageInstaller = null,
        $planStore = null,
        $packageRemover = null,
        $configManager = null,
        $serviceManager = null
    )
    {
        $this->stateStore = $stateStore;
        $this->planStore = $planStore
            ? $planStore
            : new Modules_SkamasleOls_EnginePlanStore(
                $stateStore->getDirectory()
            );
        $this->planner = $planner
            ? $planner
            : new Modules_SkamasleOls_EngineInstallPlanner();
        $this->packageInstaller = $packageInstaller
            ? $packageInstaller
            : new Modules_SkamasleOls_EnginePackageInstaller();
        $this->packageRemover = $packageRemover
            ? $packageRemover
            : new Modules_SkamasleOls_EnginePackageRemover();
        $this->configManager = $configManager
            ? $configManager
            : new Modules_SkamasleOls_OlsConfigManager();
        $this->serviceManager = $serviceManager
            ? $serviceManager
            : new Modules_SkamasleOls_OlsServiceManager();
    }

    public function run(array $arguments)
    {
        if (empty($arguments)) {
            return $this->error(64, 'Exactly one command is required.');
        }

        $command = array_shift($arguments);
        $knownCommands = array(
            'capabilities',
            'install-engine',
            'install-template',
            'restore-template',
            'template-status',
            'set-listener-port',
            'set-domain-cache',
            'set-domain-lsapi',
            'prepare-domain-vhost',
            'reset-domain-vhost',
            'set-domain-routing',
            'reconcile',
            'validate',
            'status',
            'uninstall-engine',
        );
        if (!in_array($command, $knownCommands, true)) {
            return $this->error(64, 'Unknown command.');
        }
        if (!$this->argumentsAreValid($command, $arguments)) {
            if ('prepare-domain-vhost' === $command) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.invalid-arguments',
                        array(
                            'argumentCount' => count($arguments),
                            'requestedGuid' => isset($arguments[0])
                                ? (string) $arguments[0]
                                : null,
                            'payloadBytes' => isset($arguments[1])
                                ? strlen((string) $arguments[1])
                                : 0,
                            'jsonError' => function_exists('json_last_error_msg')
                                ? json_last_error_msg()
                                : json_last_error(),
                            'diagnostics' => $this->configManager->getDiagnostics(),
                        )
                    );
                } catch (Throwable $exception) {
                    return $this->error(64, 'Invalid command arguments.', array(
                        'logError' => $exception->getMessage(),
                        'logPath' => $this->configManager->getLogPath(),
                    ));
                }
            }
            return $this->error(64, 'Invalid command arguments.');
        }

        if ('reconcile' === $command) {
            return $this->error(
                3,
                'Command is disabled in this build.',
                array('command' => $command)
            );
        }

        if ('capabilities' === $command) {
            return $this->success(array(
                'schemaVersion' =>
                    Modules_SkamasleOls_DesiredStateValidator::SCHEMA_VERSION,
                'mode' => 'Install + uninstall + port update + cache toggle',
                'commands' => array(
                    'capabilities' => true,
                    'validate' => true,
                    'status' => true,
                    'install-engine' => true,
                    'set-listener-port' => true,
                    'set-domain-cache' => true,
                    'set-domain-lsapi' => true,
                    'prepare-domain-vhost' => true,
                    'reset-domain-vhost' => true,
                    'set-domain-routing' => true,
                    'reconcile' => false,
                    'uninstall-engine' => true,
                ),
                'stateFile' => $this->stateStore->getPath(),
                'planFile' => $this->planStore->getPath(),
            ));
        }

        try {
            $state = $this->stateStore->read();
        } catch (Throwable $exception) {
            return $this->error(2, $exception->getMessage());
        }

        if ('validate' === $command) {
            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'domainCount' => count($state['domains']),
            ));
        }

        if ('install-engine' === $command) {
            $enterpriseDetector = new Modules_SkamasleOls_LiteSpeedEnterpriseDetector();
            $enterpriseStatus = $enterpriseDetector->detect();
            if (!empty($enterpriseStatus['installed'])) {
                return $this->error(
                    2,
                    'LiteSpeed Enterprise is already installed. This module will not install OpenLiteSpeed on top of it.',
                    array(
                        'enterpriseStatus' => $enterpriseStatus,
                    )
                );
            }

            $installOptions = $this->installOptionsFromArguments($arguments);
            $receipt = $this->planner->build($state, $installOptions);
            $packageResult = $this->packageInstaller->install(
                'openlitespeed',
                $installOptions
            );
            if (empty($packageResult['installed'])) {
                $receipt['status'] = 'failed';
                $receipt['installed'] = false;
                $receipt['provisioning'] = $this->mergeProvisioningReceipt(
                    isset($receipt['provisioning']) && is_array($receipt['provisioning'])
                        ? $receipt['provisioning']
                        : array(),
                    $packageResult
                );
                $receipt['repository'] = $this->mergeRepositoryReceipt(
                    isset($receipt['repository']) && is_array($receipt['repository'])
                        ? $receipt['repository']
                        : array(),
                    $packageResult
                );
                $receipt['packageResult'] = $packageResult;
                $receipt['stateGeneration'] = $state['generation'];
                $receipt['stateFile'] = $this->stateStore->getPath();
                $receipt['planFile'] = $this->planStore->getPath();
                $this->planStore->write($receipt);

                return $this->error(
                    2,
                    $this->packageFailureMessage($packageResult),
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $receipt,
                    )
                );
            }

            $receipt['status'] = 'installed';
            $receipt['installed'] = true;
            $receipt['provisioning'] = $this->mergeProvisioningReceipt(
                isset($receipt['provisioning']) && is_array($receipt['provisioning'])
                    ? $receipt['provisioning']
                    : array(),
                $packageResult
            );
            $receipt['repository'] = $this->mergeRepositoryReceipt(
                isset($receipt['repository']) && is_array($receipt['repository'])
                    ? $receipt['repository']
                    : array(),
                $packageResult
            );
            $receipt['packageResult'] = $packageResult;
            $receipt['stateGeneration'] = $state['generation'];
            $receipt['stateFile'] = $this->stateStore->getPath();
            $receipt['planFile'] = $this->planStore->getPath();
            $layoutResult = $this->configManager->syncIncludeBlock();
            $identityResult = $this->configManager->syncServerIdentity('apache', 'apache');
            $cacheResult = $this->configManager->syncCacheModule();
            if (empty($cacheResult['configured'])) {
                $receipt['layout'] = array(
                    'configRoot' => $this->configManager->getConfigRoot(),
                    'runtimeRoot' => $this->configManager->getRuntimeRoot(),
                    'stateRoot' => $this->configManager->getStateRoot(),
                    'prepared' => !empty($layoutResult['configured']),
                    'include' => $layoutResult,
                    'cache' => $cacheResult,
                );
                $this->planStore->write($receipt);

                return $this->error(
                    2,
                    isset($cacheResult['error'])
                        ? $cacheResult['error']
                        : 'Unable to register the LSCache module.',
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $receipt,
                    )
                );
            }
            if (empty($identityResult['configured'])) {
                $receipt['identity'] = $identityResult;
                $receipt['layout'] = array(
                    'configRoot' => $this->configManager->getConfigRoot(),
                    'runtimeRoot' => $this->configManager->getRuntimeRoot(),
                    'stateRoot' => $this->configManager->getStateRoot(),
                    'prepared' => !empty($layoutResult['configured']),
                    'include' => $layoutResult,
                    'cache' => $cacheResult,
                );
                $this->planStore->write($receipt);

                return $this->error(
                    2,
                    isset($identityResult['error'])
                        ? $identityResult['error']
                        : 'Unable to update OLS server identity.',
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $receipt,
                    )
                );
            }
            $receipt['layout'] = array(
                'configRoot' => $this->configManager->getConfigRoot(),
                'runtimeRoot' => $this->configManager->getRuntimeRoot(),
                'stateRoot' => $this->configManager->getStateRoot(),
                'prepared' => !empty($layoutResult['configured']),
                'include' => $layoutResult,
                'cache' => $cacheResult,
            );
            $receipt['identity'] = $identityResult;
            $exampleIdentityResult = $this->configManager->syncExampleVhostIdentity('apache', 'apache');
            if (empty($exampleIdentityResult['configured'])) {
                $receipt['exampleIdentity'] = $exampleIdentityResult;
                $this->planStore->write($receipt);

                return $this->error(
                    2,
                    isset($exampleIdentityResult['error'])
                        ? $exampleIdentityResult['error']
                        : 'Unable to update the OLS Example/html ownership.',
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $receipt,
                    )
                );
            }
            $receipt['exampleIdentity'] = $exampleIdentityResult;
            $listenerSslResult = $this->configManager->syncListenerSslCertificate();
            if (empty($listenerSslResult['configured'])) {
                $receipt['listenerSsl'] = $listenerSslResult;
                $this->planStore->write($receipt);

                return $this->error(
                    2,
                    isset($listenerSslResult['error'])
                        ? $listenerSslResult['error']
                        : 'Unable to provision the OLS listener SSL certificate.',
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $receipt,
                    )
                );
            }
            $receipt['listenerSsl'] = $listenerSslResult;
            $this->planStore->write($receipt);

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'listener' => $receipt['listener'],
                'listenerSsl' => $listenerSslResult,
                'planFile' => $this->planStore->getPath(),
                'engine' => $receipt,
            ));
        }

        if ('install-template' === $command) {
            $templateManager = new Modules_SkamasleOls_PleskTemplateManager();
            $result = $templateManager->install();
            if (empty($result['configured'])) {
                return $this->error(
                    2,
                    isset($result['error']) ? $result['error'] : 'Unable to install the nginx custom template.',
                    array(
                        'template' => $result,
                    )
                );
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'template' => $result,
                'logPath' => isset($result['logPath']) ? $result['logPath'] : null,
            ));
        }

        if ('restore-template' === $command) {
            $templateManager = new Modules_SkamasleOls_PleskTemplateManager();
            $result = $templateManager->restore();
            if (empty($result['configured'])) {
                return $this->error(
                    2,
                    isset($result['error'])
                        ? $result['error']
                        : 'Unable to restore the nginx custom template.',
                    array(
                        'template' => $result,
                    )
                );
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'template' => $result,
                'logPath' => isset($result['logPath']) ? $result['logPath'] : null,
            ));
        }

        if ('template-status' === $command) {
            $templateManager = new Modules_SkamasleOls_PleskTemplateManager();
            $result = $templateManager->status();
            if (empty($result['available'])) {
                return $this->error(
                    2,
                    isset($result['message'])
                        ? $result['message']
                        : 'Unable to inspect the nginx custom template.',
                    array('template' => $result)
                );
            }

            return $this->success(array('template' => $result));
        }

        if ('set-listener-port' === $command) {
            $port = $this->parseListenerPort($arguments);
            if (null === $port) {
                return $this->error(64, 'Listener port must be a single integer argument.');
            }
            $portCheck = $this->ensureListenerPortAvailable($port);
            if (false === $portCheck['available']) {
                return $this->error(2, $portCheck['error'], array(
                    'port' => $port,
                    'stateFile' => $this->stateStore->getPath(),
                    'planFile' => $this->planStore->getPath(),
                ));
            }

            $previousGeneration = $state['generation'];
            $state['generation'] = $previousGeneration + 1;
            $state['server']['listener']['port'] = $port;
            try {
                $this->stateStore->write($state, $previousGeneration);
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage());
            }

            $plan = $this->planStore->read();
            $plan['listener']['port'] = $port;
            if (isset($plan['installed']) && $plan['installed']) {
                $plan['status'] = 'installed';
            }
            if (isset($plan['engine']) && is_array($plan['engine'])) {
                $plan['engine']['listener']['port'] = $port;
            }
            $this->planStore->write($plan);

            $configResult = $this->configManager->syncIncludeBlock();
            if (empty($configResult['configured'])) {
                return $this->error(2, isset($configResult['error'])
                    ? $configResult['error']
                    : 'Unable to update OLS include block.',
                    array(
                        'port' => $port,
                        'listenerConfig' => $this->configManager->getListenerPath($port),
                        'config' => $configResult,
                        'planFile' => $this->planStore->getPath(),
                    )
                );
            }

            $listenerResult = $this->configManager->writeListener(
                $state['server']['listener'],
                $state['domains']
            );
            if (empty($listenerResult['available'])) {
                return $this->error(2, isset($listenerResult['error'])
                    ? $listenerResult['error']
                    : 'Unable to write listener configuration.',
                    array(
                        'port' => $port,
                        'listenerConfig' => $this->configManager->getListenerPath($port),
                        'config' => $listenerResult,
                        'planFile' => $this->planStore->getPath(),
                    )
                );
            }

            $routingResults = array();
            foreach ($state['domains'] as $domain) {
                $routingResult = $this->configManager->writeRoutingConfig(
                    $domain,
                    $state['server']['listener'],
                    $domain['appliedRouting']
                );
                if (empty($routingResult['available'])) {
                    return $this->error(
                        2,
                        isset($routingResult['error'])
                            ? $routingResult['error']
                            : 'Unable to update domain routing configuration.',
                        array(
                            'port' => $port,
                            'domain' => $domain['name'],
                            'routing' => $domain['appliedRouting'],
                            'routingConfig' => $routingResult,
                        )
                    );
                }
                $routingResults[] = $routingResult;
            }

            $configCheck = $this->serviceManager->testConfig();
            if (empty($configCheck['valid'])) {
                return $this->error(2, 'OLS configuration test failed.', array(
                    'port' => $port,
                    'listenerConfig' => $this->configManager->getListenerPath($port),
                    'config' => $configCheck,
                    'planFile' => $this->planStore->getPath(),
                ));
            }

            $reload = $this->serviceManager->reload('lsws');
            if (empty($reload['reloaded']) && empty($reload['restarted'])) {
                return $this->error(2, isset($reload['error']) ? $reload['error'] : 'Unable to reload lsws.', array(
                    'port' => $port,
                    'listenerConfig' => $this->configManager->getListenerPath($port),
                    'reload' => $reload,
                    'planFile' => $this->planStore->getPath(),
                ));
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'defaultRouting' => $state['server']['defaultRouting'],
                'listener' => $state['server']['listener'],
                'domainCount' => count($state['domains']),
                'planFile' => $this->planStore->getPath(),
                'engine' => $this->planStore->read(),
                'ols' => array(
                    'include' => $configResult,
                    'listener' => $listenerResult,
                    'routing' => $routingResults,
                    'configCheck' => $configCheck,
                    'reload' => $reload,
                ),
                'routing' => array(
                    'requested' => array('native' => 0, 'ols' => 0),
                    'applied' => array('native' => 0, 'ols' => 0),
                ),
            ));
        }

        if ('set-domain-cache' === $command) {
            $guid = $this->normalizeGuidArgument($arguments[0]);
            $enabled = $this->parseBooleanArgument($arguments[1]);
            $privateEnabled = false;
            $argumentOffset = 2;
            if (isset($arguments[2])
                && null !== $this->parseBooleanArgument($arguments[2])
            ) {
                $privateEnabled = $enabled
                    && $this->parseBooleanArgument($arguments[2]);
                $argumentOffset = 3;
            }
            $domainName = isset($arguments[$argumentOffset])
                ? trim((string) $arguments[$argumentOffset])
                : '';
            $domainPayload = null;
            if (null === $guid || null === $enabled) {
                return $this->error(
                    64,
                    'Domain GUID and cache flag are invalid.'
                );
            }

            if (isset($arguments[$argumentOffset + 1])) {
                try {
                    $decodedPayload = json_decode(
                        (string) $arguments[$argumentOffset + 1],
                        true
                    );
                    if (!is_array($decodedPayload)) {
                        throw new InvalidArgumentException(
                            'Invalid LSCache domain payload.'
                        );
                    }
                    $domainPayload = $this->domainFromPayload($decodedPayload);
                    if (0 !== strcasecmp($domainPayload['guid'], $guid)) {
                        throw new InvalidArgumentException(
                            'LSCache domain payload GUID does not match the request.'
                        );
                    }
                    if ('' !== $domainName
                        && 0 !== strcasecmp($domainPayload['name'], $domainName)
                    ) {
                        throw new InvalidArgumentException(
                            'LSCache domain payload name does not match the request.'
                        );
                    }
                    $domainPayload = $this->mergePayloadWithState(
                        $domainPayload,
                        $state
                    );
                } catch (Throwable $exception) {
                    return $this->error(64, $exception->getMessage());
                }
            }

            $previousGeneration = $state['generation'];
            $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
            if (null !== $domainPayload) {
                if (null === $domainIndex) {
                    $state['domains'] = $this->upsertDomain(
                        $state['domains'],
                        $domainPayload
                    );
                    $state['generation'] = $previousGeneration + 1;
                    $this->stateStore->write($state, $previousGeneration);
                    $previousGeneration = $state['generation'];
                    $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
                }
                $previousDomain = $domainPayload;
            } else {
                $liveDomain = $this->findDomainByGuid(
                    $guid,
                    '' !== $domainName ? $domainName : null
                );
                if (null === $liveDomain) {
                    if (null === $domainIndex) {
                        return $this->error(
                            2,
                            'Domain no longer exists in Plesk.'
                        );
                    }
                    $previousDomain = $state['domains'][$domainIndex];
                } else {
                    if (null === $domainIndex) {
                        $recoveredDomain = $this->buildDomainData($liveDomain, $state);
                        $state['domains'] = $this->upsertDomain($state['domains'], $recoveredDomain);
                        $state['generation'] = $previousGeneration + 1;
                        $this->stateStore->write($state, $previousGeneration);
                        $previousGeneration = $state['generation'];
                        $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
                    }

                    $previousDomain = $this->buildDomainData(
                        $liveDomain,
                        $state,
                        $state['domains'][$domainIndex]['guid']
                    );
                }
            }
            $previousEnabled = !empty($previousDomain['cacheEnabled']);
            $previousPrivateEnabled = !empty($previousDomain['cachePrivateEnabled']);
            $updatedDomain = $previousDomain;
            $updatedDomain['cacheEnabled'] = $enabled;
            $updatedDomain['cachePrivateEnabled'] = $enabled && $privateEnabled;
            $logPath = $this->configManager->logEvent(
                'set-domain-cache.begin',
                array(
                    'guid' => $guid,
                    'domain' => $previousDomain['name'],
                    'previousEnabled' => $previousEnabled,
                    'previousPrivateEnabled' => $previousPrivateEnabled,
                    'requestedEnabled' => $enabled,
                    'requestedPrivateEnabled' => $enabled && $privateEnabled,
                )
            );
            $operationContext = array();

            try {
                $cacheModuleResult = $this->configManager->syncCacheModule();
                $operationContext['cacheModule'] = $cacheModuleResult;
                if (empty($cacheModuleResult['configured'])) {
                    throw new RuntimeException(
                        isset($cacheModuleResult['error'])
                            ? $cacheModuleResult['error']
                            : 'Unable to register the LSCache module.'
                    );
                }

                $vhostResult = $this->configManager->writeVhostConfig($updatedDomain);
                $operationContext['vhostConfig'] = $vhostResult;
                if (empty($vhostResult['available'])) {
                    throw new RuntimeException(
                        isset($vhostResult['error'])
                            ? $vhostResult['error']
                            : 'Unable to update the vhost cache configuration.'
                    );
                }

                $configCheck = $this->serviceManager->testConfig();
                $operationContext['configCheck'] = $configCheck;
                if (empty($configCheck['valid'])) {
                    throw new RuntimeException('OLS configuration test failed.');
                }

                $reload = $this->serviceManager->reload('lsws');
                $operationContext['reload'] = $reload;
                if (empty($reload['reloaded']) && empty($reload['restarted'])) {
                    throw new RuntimeException(
                        isset($reload['error'])
                            ? $reload['error']
                            : 'Unable to reload lsws.'
                    );
                }

                $state['generation'] = $previousGeneration + 1;
                $state['domains'][$domainIndex] = $updatedDomain;
                $this->stateStore->write($state, $previousGeneration);
            } catch (Throwable $exception) {
                try {
                    $this->configManager->writeVhostConfig($previousDomain);
                    $this->serviceManager->reload('lsws');
                } catch (Throwable $rollbackException) {
                    $this->configManager->logEvent(
                        'set-domain-cache.rollback-failed',
                        array(
                            'guid' => $guid,
                            'domain' => $previousDomain['name'],
                            'error' => $exception->getMessage(),
                            'rollbackError' => $rollbackException->getMessage(),
                        )
                    );
                    return $this->error(2, $exception->getMessage(), array(
                        'rollbackError' => $rollbackException->getMessage(),
                        'logPath' => $logPath,
                    ));
                }

                $this->configManager->logEvent(
                    'set-domain-cache.failed',
                    array(
                        'guid' => $guid,
                        'domain' => $previousDomain['name'],
                        'requestedEnabled' => $enabled,
                        'requestedPrivateEnabled' => $enabled && $privateEnabled,
                        'error' => $exception->getMessage(),
                        'operation' => $operationContext,
                    )
                );
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $previousDomain,
                    'cacheEnabled' => $enabled,
                    'cachePrivateEnabled' => $enabled && $privateEnabled,
                    'logPath' => $logPath,
                ));
            }

            $this->configManager->logEvent(
                'set-domain-cache.done',
                array(
                    'guid' => $guid,
                    'domain' => $previousDomain['name'],
                    'cacheEnabled' => $enabled,
                    'cachePrivateEnabled' => $enabled && $privateEnabled,
                    'generation' => $state['generation'],
                )
            );
            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'domain' => $state['domains'][$domainIndex],
                'cacheEnabled' => $enabled,
                'cachePrivateEnabled' => $enabled && $privateEnabled,
                'vhostConfig' => $vhostResult,
                'logPath' => $logPath,
            ));
        }

        if ('set-domain-lsapi' === $command) {
            $guid = $this->normalizeGuidArgument($arguments[0]);
            $domainName = isset($arguments[2]) ? trim((string) $arguments[2]) : '';
            $domainPayload = null;
            try {
                $lsapi = $this->normalizeLsapiSettings(
                    json_decode((string) $arguments[1], true)
                );
                if (isset($arguments[3])) {
                    $decodedPayload = json_decode((string) $arguments[3], true);
                    if (!is_array($decodedPayload)) {
                        throw new InvalidArgumentException(
                            'Invalid LSAPI domain payload.'
                        );
                    }
                    $domainPayload = $this->domainFromPayload($decodedPayload);
                    if (0 !== strcasecmp($domainPayload['guid'], $guid)) {
                        throw new InvalidArgumentException(
                            'LSAPI domain payload GUID does not match the request.'
                        );
                    }
                    if ('' !== $domainName
                        && 0 !== strcasecmp($domainPayload['name'], $domainName)
                    ) {
                        throw new InvalidArgumentException(
                            'LSAPI domain payload name does not match the request.'
                        );
                    }
                    $domainPayload = $this->mergePayloadWithState(
                        $domainPayload,
                        $state
                    );
                }
            } catch (Throwable $exception) {
                return $this->error(64, $exception->getMessage());
            }

            $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
            $previousGeneration = $state['generation'];
            if (null !== $domainPayload) {
                $previousDomain = $domainPayload;
            } else {
                $liveDomain = $this->findDomainByGuid(
                    $guid,
                    '' !== $domainName ? $domainName : null
                );
                if (null === $liveDomain) {
                    return $this->error(
                        2,
                        'Domain lookup is unavailable. Reopen the extension page and retry.'
                    );
                }
                $previousDomain = $this->buildDomainData(
                    $liveDomain,
                    $state,
                    null === $domainIndex
                        ? null
                        : $state['domains'][$domainIndex]['guid']
                );
            }
            $updatedDomain = $previousDomain;
            $updatedDomain['php']['lsapi'] = $lsapi;
            $logPath = $this->configManager->logEvent(
                'set-domain-lsapi.begin',
                array(
                    'guid' => $guid,
                    'domain' => $previousDomain['name'],
                    'requested' => $lsapi,
                )
            );
            $operationContext = array();

            try {
                $vhostResult = $this->configManager->writeVhostConfig($updatedDomain);
                $operationContext['vhostConfig'] = $vhostResult;
                if (empty($vhostResult['available'])) {
                    throw new RuntimeException(
                        isset($vhostResult['error'])
                            ? $vhostResult['error']
                            : 'Unable to update the vhost LSAPI configuration.'
                    );
                }

                $configCheck = $this->serviceManager->testConfig();
                $operationContext['configCheck'] = $configCheck;
                if (empty($configCheck['valid'])) {
                    throw new RuntimeException('OLS configuration test failed.');
                }

                $reload = $this->serviceManager->reload('lsws');
                $operationContext['reload'] = $reload;
                if (empty($reload['reloaded']) && empty($reload['restarted'])) {
                    throw new RuntimeException(
                        isset($reload['error'])
                            ? $reload['error']
                            : 'Unable to reload lsws.'
                    );
                }

                $state['generation'] = $previousGeneration + 1;
                $state['domains'] = $this->upsertDomain(
                    $state['domains'],
                    $updatedDomain
                );
                $domainIndex = $this->findStateDomainIndex(
                    $state['domains'],
                    $updatedDomain['guid']
                );
                try {
                    $this->stateStore->write($state, $previousGeneration);
                } catch (Throwable $stateException) {
                    if (false === strpos(
                        $stateException->getMessage(),
                        'Desired state generation conflict.'
                    )) {
                        throw $stateException;
                    }

                    $latestState = $this->stateStore->read();
                    $latestGeneration = $latestState['generation'];
                    $latestState['domains'] = $this->upsertDomain(
                        $latestState['domains'],
                        $updatedDomain
                    );
                    $latestState['generation'] = $latestGeneration + 1;
                    $this->stateStore->write($latestState, $latestGeneration);
                    $state = $latestState;
                    $previousGeneration = $latestGeneration;
                    $domainIndex = $this->findStateDomainIndex(
                        $state['domains'],
                        $updatedDomain['guid']
                    );
                }
            } catch (Throwable $exception) {
                try {
                    $this->configManager->writeVhostConfig($previousDomain);
                    $this->serviceManager->reload('lsws');
                } catch (Throwable $rollbackException) {
                    return $this->error(2, $exception->getMessage(), array(
                        'rollbackError' => $rollbackException->getMessage(),
                        'logPath' => $logPath,
                    ));
                }

                $this->configManager->logEvent(
                    'set-domain-lsapi.failed',
                    array(
                        'guid' => $guid,
                        'domain' => $previousDomain['name'],
                        'error' => $exception->getMessage(),
                        'operation' => $operationContext,
                    )
                );
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $previousDomain,
                    'lsapi' => $lsapi,
                    'logPath' => $logPath,
                ));
            }

            $this->configManager->logEvent(
                'set-domain-lsapi.done',
                array(
                    'guid' => $guid,
                    'domain' => $previousDomain['name'],
                    'lsapi' => $lsapi,
                    'generation' => $state['generation'],
                )
            );
            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'domain' => $state['domains'][$domainIndex],
                'lsapi' => $lsapi,
                'vhostConfig' => $vhostResult,
                'logPath' => $logPath,
            ));
        }

        if ('prepare-domain-vhost' === $command) {
            $this->resolvedPhpVersion = null;
            $this->resolvedSystemUser = null;
            $this->phpHandlerResolutionAttempts = array();
            $domainGuid = $arguments[0];
            $pleskDomain = $this->findDomainByGuid($domainGuid);
            $domainPayload = null;
            if (isset($arguments[1]) && '' !== trim((string) $arguments[1])) {
                $domainPayload = json_decode((string) $arguments[1], true);
                if (!is_array($domainPayload)) {
                    return $this->error(64, 'Invalid domain payload.');
                }
            }
            try {
                $logPath = $this->configManager->logEvent(
                    'prepare-domain-vhost.begin',
                    array(
                        'requestedGuid' => $domainGuid,
                        'argumentCount' => count($arguments),
                        'payloadProvided' => null !== $domainPayload,
                        'payloadBytes' => null === $domainPayload
                            ? 0
                            : strlen((string) $arguments[1]),
                        'diagnostics' => $this->configManager->getDiagnostics(),
                    )
                );
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainGuid,
                    'logPath' => $this->configManager->getLogPath(),
                ));
            }
            try {
                $availableDomains = $this->collectAvailableDomains();
                $this->configManager->logEvent(
                    'prepare-domain-vhost.inventory',
                    array(
                        'requestedGuid' => $domainGuid,
                        'availableDomains' => $availableDomains,
                        'payloadProvided' => null !== $domainPayload,
                    )
                );
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainGuid,
                    'logPath' => $logPath,
                ));
            }
            $domain = null;
            if (is_array($domainPayload)) {
                try {
                    $domain = $this->domainFromPayload($domainPayload);
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.payload-ok',
                        array(
                            'guid' => $domain['guid'],
                            'name' => $domain['name'],
                            'phpHandlerId' => $domain['php']['pleskHandlerId'],
                            'phpVersion' => $domain['php']['version'],
                        )
                    );
                } catch (Throwable $exception) {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.payload-invalid',
                        array(
                            'requestedGuid' => $domainGuid,
                            'error' => $exception->getMessage(),
                            'payloadKeys' => array_keys($domainPayload),
                            'phpHandlerResolutionAttempts' =>
                                $this->phpHandlerResolutionAttempts,
                        )
                    );
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainGuid,
                        'logPath' => $logPath,
                    ));
                }
                if (null !== $domain
                    && 0 !== strcasecmp(
                        $this->normalizeGuidArgument($domainGuid),
                        $domain['guid']
                    )
                ) {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.payload-guid-mismatch',
                        array(
                            'requestedGuid' => $domainGuid,
                            'payloadGuid' => $domain['guid'],
                        )
                    );
                    return $this->error(64, 'Domain payload GUID does not match request.', array(
                        'domain' => $domainGuid,
                        'logPath' => $logPath,
                    ));
                }
            }
            if (null === $domain) {
                $domain = $pleskDomain;
            }
            if (null === $domain) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.domain-not-found',
                        array(
                            'requestedGuid' => $domainGuid,
                            'availableDomains' => $availableDomains,
                            'payloadProvided' => null !== $domainPayload,
                        )
                    );
                } catch (Throwable $exception) {
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainGuid,
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(2, 'Domain not found.', array(
                    'domain' => $domainGuid,
                    'availableDomains' => $availableDomains,
                    'payloadProvided' => null !== $domainPayload,
                    'logPath' => $logPath,
                ));
            }

            try {
                if (is_array($domain)) {
                    $domainData = $this->mergePayloadWithState($domain, $state);
                } else {
                    $domainData = $this->buildDomainData($domain, $state);
                }
                $this->configManager->logEvent(
                    'prepare-domain-vhost.domain-data-ok',
                    array(
                        'guid' => $domainData['guid'],
                        'name' => $domainData['name'],
                        'pleskId' => $domainData['pleskId'],
                        'documentRoot' => $domainData['documentRoot'],
                        'systemUser' => $domainData['systemUser'],
                        'systemGroup' => $domainData['systemGroup'],
                        'phpHandlerId' => $domainData['php']['pleskHandlerId'],
                        'phpVersion' => $domainData['php']['version'],
                        'lsphpBinary' => $domainData['php']['lsphpBinary'],
                        'lsphpBinaryExists' => is_file($domainData['php']['lsphpBinary']),
                        'lsphpBinaryExecutable' => is_executable($domainData['php']['lsphpBinary']),
                    )
                );
            } catch (Throwable $exception) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.domain-data-failed',
                        array(
                            'requestedGuid' => $domainGuid,
                            'error' => $exception->getMessage(),
                        )
                    );
                } catch (Throwable $logException) {
                    return $this->error(2, $logException->getMessage(), array(
                        'domain' => $domainGuid,
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainGuid,
                ));
            }
            try {
                $stage = $this->configManager->stageDomain(
                    $domainData,
                    $state['server']['listener'],
                    $state['domains']
                );
            } catch (Throwable $exception) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.stage-exception',
                        array(
                            'requestedGuid' => $domainGuid,
                            'guid' => $domainData['guid'],
                            'exception' => get_class($exception),
                            'error' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                            'diagnostics' => $this->configManager->getDiagnostics(),
                        )
                    );
                } catch (Throwable $logException) {
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'logError' => $logException->getMessage(),
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainData['name'],
                    'guid' => $domainData['guid'],
                    'logPath' => $logPath,
                ));
            }
            if (empty($stage['configured'])) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.stage-failed',
                        array(
                            'requestedGuid' => $domainGuid,
                            'guid' => $domainData['guid'],
                            'stage' => $stage,
                        )
                    );
                } catch (Throwable $exception) {
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(
                    2,
                    isset($stage['error']) ? $stage['error'] : 'Unable to stage domain vhost.',
                    array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'vhostPath' => $this->configManager->getVhostPath($domainData['name']),
                        'ols' => $stage,
                    )
                );
            }

            $configCheck = $this->serviceManager->testConfig();
            $this->configManager->logEvent(
                'prepare-domain-vhost.config-test',
                array(
                    'result' => $configCheck,
                    'artifacts' => $this->configManager->getDomainArtifacts(
                        $domainData['guid'],
                        $state['server']['listener']['port'],
                        $domainData['name']
                    ),
                    'note' => 'Files ending in .conf0 are not matched by the managed *.conf includes.',
                )
            );
            if (empty($configCheck['valid'])) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.config-test-failed',
                        array(
                            'requestedGuid' => $domainGuid,
                            'guid' => $domainData['guid'],
                            'configCheck' => $configCheck,
                        )
                    );
                } catch (Throwable $exception) {
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(2, 'OLS configuration test failed.', array(
                    'domain' => $domainData['name'],
                    'guid' => $domainData['guid'],
                    'vhostPath' => $this->configManager->getVhostPath($domainData['name']),
                    'socketPath' => $domainData['php']['socket'],
                    'config' => $configCheck,
                ));
            }

            $reload = $this->serviceManager->reload('lsws');
            $this->configManager->logEvent(
                'prepare-domain-vhost.reload',
                array(
                    'result' => $reload,
                    'artifacts' => $this->configManager->getDomainArtifacts(
                        $domainData['guid'],
                        $state['server']['listener']['port'],
                        $domainData['name']
                    ),
                )
            );
            if (empty($reload['reloaded']) && empty($reload['restarted'])) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.reload-failed',
                        array(
                            'requestedGuid' => $domainGuid,
                            'guid' => $domainData['guid'],
                            'reload' => $reload,
                        )
                    );
                } catch (Throwable $exception) {
                    return $this->error(2, $exception->getMessage(), array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(
                    2,
                    isset($reload['error']) ? $reload['error'] : 'Unable to reload lsws.',
                    array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'vhostPath' => $this->configManager->getVhostPath($domainData['name']),
                        'reload' => $reload,
                    )
                );
            }

            $previousGeneration = $state['generation'];
            $state['generation'] = $previousGeneration + 1;
            $state['domains'] = $this->upsertDomain($state['domains'], $domainData);
            try {
                $this->stateStore->write($state, $previousGeneration);
            } catch (Throwable $exception) {
                try {
                    $this->configManager->logEvent(
                        'prepare-domain-vhost.state-write-failed',
                        array(
                            'requestedGuid' => $domainGuid,
                            'guid' => $domainData['guid'],
                            'error' => $exception->getMessage(),
                        )
                    );
                } catch (Throwable $logException) {
                    return $this->error(2, $logException->getMessage(), array(
                        'domain' => $domainData['name'],
                        'guid' => $domainData['guid'],
                        'logPath' => $logPath,
                    ));
                }
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainData['name'],
                    'guid' => $domainData['guid'],
                ));
            }

            try {
                $this->configManager->logEvent(
                    'prepare-domain-vhost.done',
                    array(
                        'requestedGuid' => $domainGuid,
                        'guid' => $domainData['guid'],
                        'vhostPath' => $this->configManager->getVhostPath($domainData['name']),
                        'configPath' => $this->configManager->getVhostConfigPath(
                            $domainData['name']
                        ),
                        'logPath' => $logPath,
                    )
                );
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainData['name'],
                    'guid' => $domainData['guid'],
                    'logPath' => $logPath,
                ));
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'defaultRouting' => $state['server']['defaultRouting'],
                'listener' => $state['server']['listener'],
                'domainCount' => count($state['domains']),
                'planFile' => $this->planStore->getPath(),
                'engine' => $this->planStore->read(),
                'vhost' => array(
                    'domain' => $domainData['name'],
                    'guid' => $domainData['guid'],
                    'path' => $this->configManager->getVhostPath($domainData['name']),
                    'configPath' => $this->configManager->getVhostConfigPath(
                        $domainData['name']
                    ),
                    'staged' => true,
                    'configCheck' => $configCheck,
                    'reload' => $reload,
                ),
                'logPath' => $stage['logPath'],
            ));
        }

        if ('reset-domain-vhost' === $command) {
            $guid = $this->normalizeGuidArgument($arguments[0]);
            if (null === $guid) {
                return $this->error(64, 'Domain GUID is invalid.');
            }

            $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
            if (null === $domainIndex) {
                return $this->error(2, 'Domain vhost is not staged.');
            }

            $domainState = $state['domains'][$domainIndex];
            $remainingDomains = array();
            foreach ($state['domains'] as $index => $item) {
                if ($index === $domainIndex) {
                    continue;
                }
                if ('ols' === $item['appliedRouting']) {
                    $remainingDomains[] = $item;
                }
            }

            $previousGeneration = $state['generation'];
            $previousRouting = $domainState['appliedRouting'];
            $state['generation'] = $previousGeneration + 1;
            $state['domains'][$domainIndex]['requestedRouting'] = 'native';
            $state['domains'][$domainIndex]['appliedRouting'] = 'native';
            $state['domains'][$domainIndex]['cacheEnabled'] = false;
            $state['domains'][$domainIndex]['cachePrivateEnabled'] = false;
            if (isset($state['domains'][$domainIndex]['php'])
                && is_array($state['domains'][$domainIndex]['php'])
            ) {
                unset($state['domains'][$domainIndex]['php']['lsapi']);
            }

            try {
                $this->stateStore->write($state, $previousGeneration);
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage());
            }

            try {
                $cleanupResult = $this->configManager->clearDomainArtifacts(
                    $domainState,
                    $state['server']['listener'],
                    $remainingDomains
                );
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $domainState,
                    'listener' => $state['server']['listener'],
                ));
            }

            if (empty($cleanupResult['available'])) {
                return $this->error(
                    2,
                    isset($cleanupResult['error'])
                        ? $cleanupResult['error']
                        : 'Unable to clear OLS domain artifacts.',
                    array(
                        'domain' => $domainState,
                        'cleanup' => $cleanupResult,
                    )
                );
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'defaultRouting' => $state['server']['defaultRouting'],
                'listener' => $state['server']['listener'],
                'domain' => $state['domains'][$domainIndex],
                'cleanup' => $cleanupResult,
                'routing' => array(
                    'requested' => array('native' => 0, 'ols' => 0),
                    'applied' => array('native' => 0, 'ols' => 0),
                ),
                'previousRouting' => $previousRouting,
            ));
        }

        if ('set-domain-routing' === $command) {
            $guid = $this->normalizeGuidArgument($arguments[0]);
            $routing = isset($arguments[1]) ? (string) $arguments[1] : '';
            if (null === $guid || !in_array($routing, array('native', 'ols'), true)) {
                return $this->error(64, 'Domain GUID and routing mode are invalid.');
            }

            $domainIndex = $this->findStateDomainIndex($state['domains'], $guid);
            if (null === $domainIndex) {
                return $this->error(2, 'Domain vhost must be prepared before activation.');
            }

            $previousGeneration = $state['generation'];
            $previousRouting = $state['domains'][$domainIndex]['requestedRouting'];
            try {
                $routingResult = $this->configManager->writeRoutingConfig(
                    $state['domains'][$domainIndex],
                    $state['server']['listener'],
                    $routing
                );
                if (empty($routingResult['available'])) {
                    return $this->error(
                        2,
                        isset($routingResult['error'])
                            ? $routingResult['error']
                            : 'Unable to update routing configuration.',
                        array(
                            'domain' => $state['domains'][$domainIndex],
                            'routing' => $routing,
                            'routingFile' => $this->configManager->getRoutingPath(
                                $state['domains'][$domainIndex]['name']
                            ),
                        )
                    );
                }
            } catch (Throwable $exception) {
                return $this->error(2, $exception->getMessage(), array(
                    'domain' => $state['domains'][$domainIndex],
                    'routing' => $routing,
                ));
            }

            $state['generation'] = $previousGeneration + 1;
            $state['domains'][$domainIndex]['requestedRouting'] = $routing;
            $state['domains'][$domainIndex]['appliedRouting'] = $routing;
            try {
                $this->stateStore->write($state, $previousGeneration);
            } catch (Throwable $exception) {
                try {
                    $rollbackResult = $this->configManager->writeRoutingConfig(
                        $state['domains'][$domainIndex],
                        $state['server']['listener'],
                        $previousRouting
                    );
                    if (empty($rollbackResult['available'])) {
                        return $this->error(
                            2,
                            $exception->getMessage(),
                            array(
                                'rollbackError' => isset($rollbackResult['error'])
                                    ? $rollbackResult['error']
                                    : 'Unable to restore routing configuration.',
                            )
                        );
                    }
                } catch (Throwable $rollbackException) {
                    return $this->error(2, $exception->getMessage(), array(
                        'rollbackError' => $rollbackException->getMessage(),
                    ));
                }

                return $this->error(2, $exception->getMessage());
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'domain' => $state['domains'][$domainIndex],
                'listener' => $state['server']['listener'],
                'routingConfig' => isset($routingResult) ? $routingResult : array(),
            ));
        }

        if ('uninstall-engine' === $command) {
            foreach ($state['domains'] as $domain) {
                if ('ols' === $domain['appliedRouting']) {
                    return $this->error(
                        2,
                        'Restore all domains to native routing before uninstalling OpenLiteSpeed.'
                    );
                }
            }
            $existingPlan = $this->planStore->read();
            $removal = $this->packageRemover->remove(
                'openlitespeed',
                $this->packageRemovalOptions($existingPlan)
            );
            if (empty($removal['removed'])) {
                return $this->error(
                    2,
                    isset($removal['error'])
                        ? $removal['error']
                        : 'OpenLiteSpeed removal failed.',
                    array(
                        'planFile' => $this->planStore->getPath(),
                        'engine' => $removal,
                    )
                );
            }

            $planFile = $this->planStore->getPath();
            if (is_file($planFile) && !is_link($planFile)) {
                @unlink($planFile);
            }

            return $this->success(array(
                'schemaVersion' => $state['schemaVersion'],
                'generation' => $state['generation'],
                'defaultRouting' => $state['server']['defaultRouting'],
                'listener' => $state['server']['listener'],
                'domainCount' => count($state['domains']),
                'planFile' => $planFile,
                'engine' => array(
                    'status' => 'removed',
                    'installed' => false,
                    'provisioning' => isset($existingPlan['provisioning'])
                        ? $existingPlan['provisioning']
                        : array('mode' => 'recommended-bootstrap', 'customRepoUrl' => null),
                    'listener' => $state['server']['listener'],
                    'repository' => array(
                        'name' => isset($existingPlan['repository']['name'])
                            ? $existingPlan['repository']['name']
                            : 'openlitespeed-official',
                        'configured' => false,
                        'mode' => isset($existingPlan['repository']['mode'])
                            ? $existingPlan['repository']['mode']
                            : 'recommended-bootstrap',
                    ),
                    'packages' => array('openlitespeed'),
                    'services' => array('lsws'),
                    'repositoryRemoved' => isset($removal['repositoryRemoved'])
                        ? $removal['repositoryRemoved']
                        : array(),
                    'serviceStop' => isset($removal['serviceStop'])
                        ? $removal['serviceStop']
                        : array(),
                ),
            ));
        }

        $routing = array(
            'requested' => array('native' => 0, 'ols' => 0),
            'applied' => array('native' => 0, 'ols' => 0),
        );
        foreach ($state['domains'] as $domain) {
            $routing['requested'][$domain['requestedRouting']]++;
            $routing['applied'][$domain['appliedRouting']]++;
        }

        return $this->success(array(
            'schemaVersion' => $state['schemaVersion'],
            'generation' => $state['generation'],
            'defaultRouting' => $state['server']['defaultRouting'],
            'listener' => $state['server']['listener'],
            'domainCount' => count($state['domains']),
            'planFile' => $this->planStore->getPath(),
            'engine' => $this->planStore->read(),
            'routing' => $routing,
        ));
    }

    private function parseListenerPort(array $arguments)
    {
        if (1 !== count($arguments) || !preg_match('/^\d+$/', (string) $arguments[0])) {
            return null;
        }
        $port = (int) $arguments[0];
        if ($port < 1024 || $port > 65535) {
            return null;
        }
        return $port;
    }

    private function findDomainByGuid($guid, $domainName = null)
    {
        if (!class_exists('pm_Domain')) {
            return null;
        }

        $guid = $this->normalizeGuidArgument($guid);
        $domainName = strtolower(trim((string) $domainName));
        if (null === $guid) {
            return null;
        }

        $domains = pm_Domain::getAllDomains();
        foreach ($domains as $domain) {
            if (!is_object($domain) || !method_exists($domain, 'getGuid')) {
                continue;
            }
            $domainGuid = $this->normalizeGuidArgument($domain->getGuid());
            if (null !== $domainGuid && 0 === strcasecmp($domainGuid, $guid)) {
                return $domain;
            }

            if ('' !== $domainName) {
                $candidateNames = array();
                if (method_exists($domain, 'getName')) {
                    $candidateNames[] = strtolower(trim((string) $domain->getName()));
                }
                if (method_exists($domain, 'getDisplayName')) {
                    $candidateNames[] = strtolower(trim((string) $domain->getDisplayName()));
                }
                if (in_array($domainName, $candidateNames, true)) {
                    return $domain;
                }
            }
        }

        return null;
    }

    private function collectAvailableDomains()
    {
        $availableDomains = array();
        if (!class_exists('pm_Domain')) {
            return $availableDomains;
        }

        $domains = pm_Domain::getAllDomains();
        foreach ($domains as $domain) {
            if (!is_object($domain) || !method_exists($domain, 'getGuid')) {
                continue;
            }
            $availableDomains[] = array(
                'guid' => trim((string) $domain->getGuid(), '{}'),
                'name' => method_exists($domain, 'getDisplayName')
                    ? (string) $domain->getDisplayName()
                    : (method_exists($domain, 'getName')
                        ? (string) $domain->getName()
                        : ''),
            );
        }

        return $availableDomains;
    }

    private function domainFromPayload(array $payload)
    {
        foreach (array('guid', 'name', 'documentRoot') as $requiredKey) {
            if (empty($payload[$requiredKey])) {
                throw new InvalidArgumentException(
                    'Domain payload is missing ' . $requiredKey . '.'
                );
            }
        }

        $guid = $this->normalizeGuidArgument($payload['guid']);
        if (null === $guid) {
            throw new InvalidArgumentException('Domain payload GUID is invalid.');
        }

        $cliHandlerId = $this->resolvePhpHandlerIdFromCli($payload['name']);
        $phpHandlerId = isset($payload['phpHandlerId'])
            ? trim((string) $payload['phpHandlerId'])
            : '';
        if ('' === $phpHandlerId) {
            $phpHandlerId = $cliHandlerId;
        }
        if (null === $phpHandlerId || '' === trim((string) $phpHandlerId)) {
            throw new RuntimeException(
                'Unable to determine the PHP handler selected in Plesk.'
            );
        }

        $phpVersion = isset($payload['phpVersion'])
            ? trim((string) $payload['phpVersion'])
            : '';
        if ('' === $phpVersion) {
            $phpVersion = $this->phpVersionFromHandlerId($phpHandlerId);
        }
        if (null === $phpVersion || '' === trim((string) $phpVersion)) {
            throw new RuntimeException(
                'Unable to determine the Plesk PHP version for this domain.'
            );
        }

        $documentRoot = null !== $this->resolvedDocumentRoot
            ? $this->resolvedDocumentRoot
            : (string) $payload['documentRoot'];
        $vhostRoot = null !== $this->resolvedVhostRoot
            ? $this->resolvedVhostRoot
            : dirname(rtrim($documentRoot, '/'));

        $domain = array(
            'guid' => $guid,
            'pleskId' => isset($payload['pleskId'])
                ? max(1, (int) $payload['pleskId'])
                : 1,
            'name' => strtolower(trim((string) $payload['name'])),
            'aliases' => isset($payload['aliases']) && is_array($payload['aliases'])
                ? array_values(array_filter(array_map('strval', $payload['aliases'])))
                : array(),
            'documentRoot' => $documentRoot,
            'vhostRoot' => $vhostRoot,
            'systemUser' => isset($payload['systemUser'])
                ? (null !== $this->resolvedSystemUser
                    ? $this->resolvedSystemUser
                    : (string) $payload['systemUser'])
                : 'psacln',
            'systemGroup' => isset($payload['systemGroup'])
                ? (string) $payload['systemGroup']
                : 'psacln',
            'nativeProfile' => array(
                'webMode' => 'proxy',
                'proxyMode' => true,
                'phpHandlerId' => $phpHandlerId,
            ),
            'php' => array(
                'pleskHandlerId' => $phpHandlerId,
                'version' => $phpVersion,
                'lsphpBinary' => '/opt/plesk/php/' . $phpVersion . '/bin/lsphp',
                'socket' => $this->configManager->getSocketPath($guid),
                'lsapi' => $this->normalizeLsapiSettings(
                    isset($payload['lsapi']) && is_array($payload['lsapi'])
                        ? $payload['lsapi']
                        : array()
                ),
            ),
            'requestedRouting' => isset($payload['requestedRouting'])
                && in_array($payload['requestedRouting'], array('native', 'ols'), true)
                    ? $payload['requestedRouting']
                    : 'native',
            'appliedRouting' => isset($payload['requestedRouting'])
                && in_array($payload['requestedRouting'], array('native', 'ols'), true)
                    ? $payload['requestedRouting']
                    : 'native',
            'cacheEnabled' => isset($payload['cacheEnabled'])
                ? (bool) $payload['cacheEnabled']
                : false,
            'cachePrivateEnabled' => !empty($payload['cacheEnabled'])
                && isset($payload['cachePrivateEnabled'])
                ? (bool) $payload['cachePrivateEnabled']
                : false,
        );

        return $domain;
    }

    private function mergePayloadWithState(array $domain, array $state)
    {
        $existing = $this->findStateDomain($state['domains'], $domain['guid']);
        if (null !== $existing) {
            $domain['aliases'] = isset($existing['aliases'])
                ? $existing['aliases']
                : $domain['aliases'];
            $domain['requestedRouting'] = $existing['requestedRouting'];
            $domain['appliedRouting'] = $existing['appliedRouting'];
            if (array_key_exists('cacheEnabled', $existing)) {
                $domain['cacheEnabled'] = (bool) $existing['cacheEnabled'];
            }
            if (array_key_exists('cachePrivateEnabled', $existing)) {
                $domain['cachePrivateEnabled'] = (bool) $existing['cachePrivateEnabled'];
            }
            if (isset($existing['php']['lsapi'])
                && (!isset($domain['php']['lsapi']) || empty($domain['php']['lsapi']))
            ) {
                $domain['php']['lsapi'] = $existing['php']['lsapi'];
            }
        }
        if (empty($domain['cacheEnabled'])) {
            $domain['cachePrivateEnabled'] = false;
        }
        return $domain;
    }

    private function buildDomainData($domain, array $state, $stateGuid = null)
    {
        $documentRoot = null;
        if (method_exists($domain, 'hasHosting') && $domain->hasHosting()) {
            $documentRoot = (string) $domain->getDocumentRoot();
        }
        if (null === $documentRoot || '' === $documentRoot) {
            throw new RuntimeException('Physical web hosting is required.');
        }

        $guid = $this->normalizeGuidArgument((string) $domain->getGuid());
        if (null === $guid) {
            throw new RuntimeException('Plesk returned an invalid domain GUID.');
        }
        $stateGuid = $this->normalizeGuidArgument($stateGuid);
        $existing = $this->findStateDomain(
            $state['domains'],
            null !== $stateGuid ? $stateGuid : $guid
        );
        if (null !== $stateGuid) {
            $guid = $stateGuid;
        }
        $name = method_exists($domain, 'getName')
            ? (string) $domain->getName()
            : (string) $domain->getDisplayName();
        $handlerId = $this->resolvePhpHandlerId($domain, $existing, $name);
        $phpVersion = $this->phpVersionFromHandlerId($handlerId);
        if (null === $phpVersion) {
            throw new RuntimeException(
                'Unable to determine the Plesk PHP version for this domain.'
            );
        }
        $cacheEnabled = $existing && array_key_exists('cacheEnabled', $existing)
            ? (bool) $existing['cacheEnabled']
            : (method_exists($domain, 'getSetting')
                ? '1' === (string) $domain->getSetting('skamasle-ols.lscache', '0')
                : false);

        $domain = array(
            'guid' => $guid,
            'pleskId' => method_exists($domain, 'getId')
                ? (int) $domain->getId()
                : 1,
            'name' => strtolower($name),
            'aliases' => $existing && isset($existing['aliases'])
                ? $existing['aliases']
                : array(),
            'documentRoot' => $documentRoot,
            'vhostRoot' => dirname(rtrim($documentRoot, '/')),
            'systemUser' => method_exists($domain, 'getSysUserLogin')
                ? (string) $domain->getSysUserLogin()
                : 'psacln',
            'systemGroup' => method_exists($domain, 'getSysGroupLogin')
                ? (string) $domain->getSysGroupLogin()
                : 'psacln',
            'nativeProfile' => array(
                'webMode' => 'proxy',
                'proxyMode' => true,
                'phpHandlerId' => $handlerId,
            ),
            'php' => array(
                'pleskHandlerId' => $handlerId,
                'version' => $phpVersion,
                'lsphpBinary' => '/opt/plesk/php/' . $phpVersion . '/bin/lsphp',
                'socket' => $this->configManager->getSocketPath($guid),
                'lsapi' => $existing && isset($existing['php']['lsapi'])
                    ? $existing['php']['lsapi']
                    : $this->normalizeLsapiSettings(array()),
            ),
            'cacheEnabled' => $cacheEnabled,
            'cachePrivateEnabled' => $existing
                && array_key_exists('cachePrivateEnabled', $existing)
                ? (bool) $existing['cachePrivateEnabled']
                : ($cacheEnabled && method_exists($domain, 'getSetting')
                    ? '1' === (string) $domain->getSetting(
                        'skamasle-ols.lscache_private',
                        '0'
                    )
                    : false),
            'requestedRouting' => $existing
                ? $existing['requestedRouting']
                : 'native',
            'appliedRouting' => $existing
                ? $existing['appliedRouting']
                : 'native',
        );
        if (!$cacheEnabled) {
            $domain['cachePrivateEnabled'] = false;
        }

        return $domain;
    }

    private function resolvePhpHandlerId($domain, $existing, $domainName)
    {
        if (method_exists($domain, 'getProperty')) {
            foreach (array('php_handler_id', 'phpHandlerId') as $property) {
                try {
                    $value = trim((string) $domain->getProperty($property));
                    if ('' !== $value) {
                        return $value;
                    }
                } catch (Throwable $exception) {
                    // Plesk versions expose different hosting properties.
                }
            }
        }

        $handlerId = $this->resolvePhpHandlerIdFromCli($domainName);
        if (null !== $handlerId) {
            return $handlerId;
        }

        if ($existing && isset($existing['php']['pleskHandlerId'])) {
            return (string) $existing['php']['pleskHandlerId'];
        }

        throw new RuntimeException(
            'Unable to determine the PHP handler selected in Plesk.'
        );
    }

    private function resolvePhpHandlerIdFromCli($domainName)
    {
        $this->phpHandlerResolutionAttempts = array();
        $this->resolvedPhpVersion = null;
        $this->resolvedSystemUser = null;
        $this->resolvedDocumentRoot = null;
        $this->resolvedVhostRoot = null;
        $pleskBinary = null;
        foreach (array('/usr/sbin/plesk', '/usr/local/psa/admin/bin/plesk') as $plesk) {
            if (is_executable($plesk)) {
                $pleskBinary = $plesk;
                break;
            }
        }
        if (null === $pleskBinary) {
            $this->phpHandlerResolutionAttempts[] = array(
                'command' => 'plesk bin site --info ' . (string) $domainName,
                'exitCode' => null,
                'output' => 'Plesk CLI executable was not found.',
            );
            return null;
        }

        $command = escapeshellarg($pleskBinary)
            . ' bin site --info ' . escapeshellarg((string) $domainName);
        $output = array();
        $exitCode = 1;
        exec('LC_ALL=C ' . $command . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);
        $this->phpHandlerResolutionAttempts[] = array(
            'command' => $command,
            'exitCode' => (int) $exitCode,
            'output' => substr($text, 0, 4000),
        );
        if (0 !== $exitCode) {
            return null;
        }

        if (!$this->siteInfoHasPhpSupport($text)) {
            $this->phpHandlerResolutionAttempts[] = array(
                'source' => 'site-info',
                'error' => 'PHP support is disabled for this site.',
            );
            return null;
        }

        $paths = $this->parseSiteInfoPaths($text);
        $this->resolvedDocumentRoot = $paths['documentRoot'];
        $this->resolvedVhostRoot = $paths['vhostRoot'];

        $handlerId = $this->parsePhpHandlerId($text);
        if (null !== $handlerId) {
            $this->resolvedPhpVersion = $this->phpVersionFromHandlerId($handlerId);
            return $handlerId;
        }

        $sql = 'SELECT h.php_handler_id, s.login '
            . 'FROM domains d '
            . 'JOIN hosting h ON h.dom_id = d.id '
            . 'JOIN sys_users s ON s.id = h.sys_user_id '
            . 'WHERE d.name = ' . $this->sqlLiteral($domainName)
            . ' LIMIT 1';
        $dbCommand = escapeshellarg($pleskBinary)
            . ' db -Ne ' . escapeshellarg($sql);
        $dbOutput = array();
        $dbExitCode = 1;
        exec('LC_ALL=C ' . $dbCommand . ' 2>&1', $dbOutput, $dbExitCode);
        $dbText = trim(implode("\n", $dbOutput));
        $this->phpHandlerResolutionAttempts[] = array(
            'command' => $dbCommand,
            'exitCode' => (int) $dbExitCode,
            'output' => substr($dbText, 0, 4000),
        );
        if (0 !== $dbExitCode || '' === $dbText) {
            return null;
        }

        $runtime = $this->parsePhpRuntimeRow($dbText);
        if (null === $runtime) {
            return null;
        }

        $this->resolvedSystemUser = $runtime['systemUser'];
        $this->resolvedPhpVersion = $this->phpVersionFromHandlerId(
            $runtime['handlerId']
        );
        return $runtime['handlerId'];
    }

    private function parsePhpHandlerId($output)
    {
        foreach (preg_split('/\R/', (string) $output) as $line) {
            if (preg_match(
                '/^\s*PHP\s+(?:handler(?:\s+id)?|Handler ID)\s*:\s*(\S+)\s*$/i',
                $line,
                $matches
            )) {
                return $matches[1];
            }
            if (preg_match(
                '/\b(plesk-php\d+(?:[-_.][a-z0-9]+)*)\b/i',
                $line,
                $matches
            )) {
                return $matches[1];
            }
        }
        return null;
    }

    private function siteInfoHasPhpSupport($output)
    {
        return (bool) preg_match(
            '/^\s*PHP support\s*:\s*(?:Yes|On|true)\s*$/im',
            (string) $output
        );
    }

    private function parseSiteInfoPaths($output)
    {
        $documentRoot = null;
        $vhostRoot = null;
        foreach (preg_split('/\R/', (string) $output) as $line) {
            if (!preg_match('/^\s*([^:]+)\s*:\s*(\/\S.*)\s*$/', $line, $matches)) {
                continue;
            }
            $label = strtolower(trim($matches[1]));
            $path = rtrim(trim($matches[2]), '/');
            if (in_array($label, array(
                'home',
                'webspace root',
                'vhost root',
                'virtual host root',
            ), true)) {
                $vhostRoot = $path;
            }
            if (in_array($label, array('www-root', 'document root'), true)) {
                $documentRoot = $path;
            }
        }
        if (null === $vhostRoot && null !== $documentRoot) {
            $vhostRoot = dirname($documentRoot);
        }

        return array(
            'documentRoot' => $documentRoot,
            'vhostRoot' => $vhostRoot,
        );
    }

    private function sqlLiteral($value)
    {
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private function parsePhpRuntimeRow($output)
    {
        $columns = preg_split('/\s+/', trim((string) $output), 2);
        if (2 !== count($columns)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $columns[0])
            || !preg_match('/^[a-z_][a-z0-9_.-]{0,63}$/', $columns[1])
        ) {
            return null;
        }
        return array(
            'handlerId' => $columns[0],
            'systemUser' => $columns[1],
        );
    }

    private function phpVersionFromHandlerId($handlerId)
    {
        if (null !== $this->resolvedPhpVersion) {
            return $this->resolvedPhpVersion;
        }
        if (preg_match('/(?:^|[-_])php(\d)(\d)(?:[-_]|$)/i', $handlerId, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        if (preg_match('/(?:^|[-_])php(\d+)\.(\d+)(?:[-_]|$)/i', $handlerId, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return null;
    }

    private function upsertDomain(array $domains, array $domain)
    {
        $index = $this->findStateDomainIndex($domains, $domain['guid']);
        if (null === $index) {
            $domains[] = $domain;
        } else {
            $domains[$index] = $domain;
        }
        return array_values($domains);
    }

    private function findStateDomain(array $domains, $guid)
    {
        $index = $this->findStateDomainIndex($domains, $guid);
        return null === $index ? null : $domains[$index];
    }

    private function findStateDomainIndex(array $domains, $guid)
    {
        foreach ($domains as $index => $domain) {
            if (isset($domain['guid'])
                && 0 === strcasecmp($this->normalizeGuidArgument($domain['guid']), $guid)
            ) {
                return $index;
            }
        }
        return null;
    }

    private function normalizeGuidArgument($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $guid = strtolower(trim($value));
        if ('{' === substr($guid, 0, 1) && '}' === substr($guid, -1)) {
            $guid = substr($guid, 1, -1);
        }

        if (!preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            $guid
        )) {
            return null;
        }

        return $guid;
    }

    private function parseBooleanArgument($value)
    {
        if (in_array($value, array('1', 1, true), true)) {
            return true;
        }
        if (in_array($value, array('0', 0, false), true)) {
            return false;
        }

        return null;
    }

    private function ensureListenerPortAvailable($port)
    {
        $address = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if (is_resource($address)) {
            fclose($address);
            return array(
                'available' => false,
                'error' => 'Port ' . $port . ' is already in use on 127.0.0.1.',
            );
        }

        $address = @fsockopen('::1', $port, $errno, $errstr, 0.25);
        if (is_resource($address)) {
            fclose($address);
            return array(
                'available' => false,
                'error' => 'Port ' . $port . ' is already in use on ::1.',
            );
        }

        return array('available' => true);
    }

    private function packageFailureMessage(array $packageResult)
    {
        if (isset($packageResult['repository']['error'])) {
            return 'Repository setup failed: ' . $packageResult['repository']['error'];
        }
        if (isset($packageResult['repository']['message'])
            && false === empty($packageResult['repository']['message'])
            && empty($packageResult['repository']['configured'])
        ) {
            return $packageResult['repository']['message'];
        }
        if (isset($packageResult['message']) && '' !== trim($packageResult['message'])) {
            return $packageResult['message'];
        }
        if (isset($packageResult['error']) && '' !== trim($packageResult['error'])) {
            return $packageResult['error'];
        }
        return 'OpenLiteSpeed installation failed.';
    }

    private function installOptionsFromArguments(array $arguments)
    {
        if (empty($arguments)) {
            return array(
                'mode' => Modules_SkamasleOls_EnginePackageInstaller::MODE_RECOMMENDED_BOOTSTRAP,
            );
        }

        $payload = json_decode((string) $arguments[0], true);
        if (!is_array($payload)) {
            return array(
                'mode' => Modules_SkamasleOls_EnginePackageInstaller::MODE_RECOMMENDED_BOOTSTRAP,
            );
        }

        $mode = isset($payload['mode']) ? (string) $payload['mode'] : '';
        $options = array('mode' => $mode);
        if (isset($payload['customRepoUrl'])) {
            $options['customRepoUrl'] = trim((string) $payload['customRepoUrl']);
        }

        return $options;
    }

    private function mergeProvisioningReceipt(array $provisioning, array $packageResult)
    {
        if (isset($packageResult['mode']) && '' !== trim((string) $packageResult['mode'])) {
            $provisioning['mode'] = (string) $packageResult['mode'];
        }
        if (isset($packageResult['repository']['customRepoUrl'])) {
            $provisioning['customRepoUrl'] = $packageResult['repository']['customRepoUrl'];
        } elseif (!array_key_exists('customRepoUrl', $provisioning)) {
            $provisioning['customRepoUrl'] = null;
        }

        return $provisioning;
    }

    private function mergeRepositoryReceipt(array $repository, array $packageResult)
    {
        if (isset($packageResult['repository']) && is_array($packageResult['repository'])) {
            foreach ($packageResult['repository'] as $key => $value) {
                $repository[$key] = $value;
            }
        }

        if (isset($packageResult['mode']) && !isset($repository['mode'])) {
            $repository['mode'] = (string) $packageResult['mode'];
        }

        return $repository;
    }

    private function packageRemovalOptions(array $plan)
    {
        $mode = isset($plan['provisioning']['mode'])
            ? (string) $plan['provisioning']['mode']
            : Modules_SkamasleOls_EnginePackageInstaller::MODE_RECOMMENDED_BOOTSTRAP;
        $repositoryPath = isset($plan['repository']['path'])
            ? (string) $plan['repository']['path']
            : '/etc/yum.repos.d/litespeed.repo';

        return array(
            'removePackage' => Modules_SkamasleOls_EnginePackageInstaller::MODE_ALREADY_INSTALLED !== $mode,
            'removeRepository' => !empty($plan['repository']['managedByModule']),
            'repositoryPath' => $repositoryPath,
        );
    }

    private function argumentsAreValid($command, array $arguments)
    {
        if ('install-engine' === $command) {
            if (empty($arguments)) {
                return true;
            }
            if (1 !== count($arguments)) {
                return false;
            }
            $payload = json_decode((string) $arguments[0], true);
            if (!is_array($payload) || empty($payload['mode'])) {
                return false;
            }

            $mode = (string) $payload['mode'];
            if (!in_array($mode, array(
                Modules_SkamasleOls_EnginePackageInstaller::MODE_RECOMMENDED_BOOTSTRAP,
                Modules_SkamasleOls_EnginePackageInstaller::MODE_CUSTOM_REPO_URL,
                Modules_SkamasleOls_EnginePackageInstaller::MODE_REPO_READY,
                Modules_SkamasleOls_EnginePackageInstaller::MODE_ALREADY_INSTALLED,
            ), true)) {
                return false;
            }

            if (Modules_SkamasleOls_EnginePackageInstaller::MODE_CUSTOM_REPO_URL === $mode) {
                return !empty($payload['customRepoUrl'])
                    && false !== filter_var($payload['customRepoUrl'], FILTER_VALIDATE_URL);
            }

            return true;
        }
        if ('reconcile' === $command || 'uninstall-engine' === $command) {
            return empty($arguments);
        }
        if ('set-listener-port' === $command) {
            return 1 === count($arguments)
                && preg_match('/^\d+$/', (string) $arguments[0]);
        }
        if ('set-domain-cache' === $command) {
            if (count($arguments) < 2 || count($arguments) > 5) {
                return false;
            }
            if (null === $this->normalizeGuidArgument($arguments[0])) {
                return false;
            }
            if (null === $this->parseBooleanArgument($arguments[1])) {
                return false;
            }
            if (2 === count($arguments)) {
                return true;
            }
            $offset = 2;
            if (null !== $this->parseBooleanArgument($arguments[2])) {
                if (3 === count($arguments)) {
                    return true;
                }
                $offset = 3;
            }
            if ('' === trim((string) $arguments[$offset])) {
                return false;
            }
            if (($offset + 1) === count($arguments)) {
                return true;
            }
            if (($offset + 2) === count($arguments)) {
                return is_array(json_decode((string) $arguments[$offset + 1], true));
            }
            return false;
        }
        if ('set-domain-lsapi' === $command) {
            if (count($arguments) < 2 || count($arguments) > 4) {
                return false;
            }
            if (null === $this->normalizeGuidArgument($arguments[0])) {
                return false;
            }
            $payload = json_decode((string) $arguments[1], true);
            if (!is_array($payload)) {
                return false;
            }
            if (2 === count($arguments)) {
                return true;
            }
            if ('' === trim((string) $arguments[2])) {
                return false;
            }
            if (4 === count($arguments)) {
                return is_array(json_decode((string) $arguments[3], true));
            }
            return true;
        }
        if ('prepare-domain-vhost' === $command) {
            if (count($arguments) < 1 || count($arguments) > 2) {
                return false;
            }
            if (null === $this->normalizeGuidArgument($arguments[0])) {
                return false;
            }
            if (1 === count($arguments)) {
                return true;
            }
            $payload = json_decode((string) $arguments[1], true);
            return is_array($payload);
        }
        if ('reset-domain-vhost' === $command) {
            return 1 === count($arguments)
                && null !== $this->normalizeGuidArgument($arguments[0]);
        }
        if ('set-domain-routing' === $command) {
            return 2 === count($arguments)
                && null !== $this->normalizeGuidArgument($arguments[0])
                && in_array($arguments[1], array('native', 'ols'), true);
        }
        if (empty($arguments)) {
            return true;
        }
        if (2 !== count($arguments) || '--domain' !== $arguments[0]) {
            return false;
        }

        $guid = $arguments[1];
        if ('{' === substr($guid, 0, 1) || '}' === substr($guid, -1)) {
            if ('{' !== substr($guid, 0, 1) || '}' !== substr($guid, -1)) {
                return false;
            }
            $guid = substr($guid, 1, -1);
        }

        return (bool) preg_match(
            '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-'
            . '[1-5][a-fA-F0-9]{3}-[89aAbB][a-fA-F0-9]{3}-'
            . '[a-fA-F0-9]{12}$/',
            $guid
        );
    }

    private function normalizeLsapiSettings($settings)
    {
        if (!is_array($settings)) {
            throw new InvalidArgumentException('LSAPI settings must be an object.');
        }
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
        $allowed = array_keys($defaults);
        if (!empty(array_diff(array_keys($settings), $allowed))) {
            throw new InvalidArgumentException('LSAPI settings contain unknown properties.');
        }
        $normalized = array_merge($defaults, $settings);
        $normalized['children'] = $normalized['maxConnections'];
        $limits = array(
            'maxConnections' => array(1, 1000),
            'children' => array(1, 1000),
            'instances' => array(1, 100),
            'backlog' => array(1, 10000),
            'initTimeout' => array(1, 3600),
            'retryTimeout' => array(0, 3600),
        );
        foreach ($limits as $key => $range) {
            if (!is_int($normalized[$key])
                || $normalized[$key] < $range[0]
                || $normalized[$key] > $range[1]
            ) {
                throw new InvalidArgumentException(
                    'LSAPI setting ' . $key . ' is outside the allowed range.'
                );
            }
        }
        foreach (array('persistentConnection', 'responseBuffering') as $key) {
            if (!is_bool($normalized[$key])) {
                throw new InvalidArgumentException(
                    'LSAPI setting ' . $key . ' must be boolean.'
                );
            }
        }

        return $normalized;
    }

    private function success(array $payload)
    {
        return array(
            'exitCode' => 0,
            'stderr' => false,
            'payload' => array_merge(array('ok' => true), $payload),
        );
    }

    private function error($exitCode, $message, array $payload = array())
    {
        return array(
            'exitCode' => $exitCode,
            'stderr' => true,
            'payload' => array_merge(
                array('ok' => false),
                $payload,
                array('error' => $message)
            ),
        );
    }
}
