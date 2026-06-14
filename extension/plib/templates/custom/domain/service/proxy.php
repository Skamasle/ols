<?php
/**
 * @var Template_VariableAccessor $VAR
 * @var array $OPT
 */
?>
<?php
$routingFile = '/usr/local/psa/var/modules/skamasle-ols/nginx-routing/'
    . $VAR->domain->asciiName . '.conf';
$skamasleOlsPort = null;
if (is_file($routingFile) && is_readable($routingFile)) {
    $routingConfig = file_get_contents($routingFile);
    if (is_string($routingConfig)
        && preg_match(
            '/^\s*set\s+\$skamasle_ols_proxy_port\s+(\d+)\s*;\s*$/m',
            $routingConfig,
            $matches
        )
    ) {
        $candidatePort = (int) $matches[1];
        if ($candidatePort >= 1024 && $candidatePort <= 65535) {
            $skamasleOlsPort = $candidatePort;
        }
    }
}

$skamasleOlsEnabled = null !== $skamasleOlsPort;
if ($skamasleOlsEnabled) {
    $skamasleProxyTarget = $VAR->quote(
        'https://127.0.0.1:' . $skamasleOlsPort
    );
} elseif ($OPT['ssl']) {
    $skamasleProxyTarget = $VAR->quote(
        'https://' . $OPT['ipAddress']->proxyEscapedAddress
        . ':' . $OPT['backendPort']
    );
} else {
    $skamasleProxyTarget = $VAR->quote(
        'http://' . $OPT['ipAddress']->proxyEscapedAddress
        . ':' . $OPT['backendPort']
    );
}
?>
        proxy_pass <?= $skamasleProxyTarget ?>;
<?php if ($skamasleOlsEnabled || $OPT['ssl']): ?>
        proxy_hide_header upgrade;
        proxy_ssl_server_name on;
        <?php if ($VAR->server->webserver->listenLocalhost && ($OPT['default'] ?? false)): ?>
        proxy_ssl_name $ip_default_host;
        <?php else: ?>
        proxy_ssl_name $host;
        <?php endif ?>
        proxy_ssl_session_reuse off;
        proxy_ssl_verify off;
<?php endif ?>
        proxy_set_header X-Forwarded-Proto <?php echo $skamasleOlsEnabled ? 'https' : '$scheme'; ?>;
<?php if ($skamasleOlsEnabled || !empty($OPT['ssl'])): ?>
        proxy_set_header HTTPS on;
<?php endif ?>
        <?php if ($VAR->server->webserver->listenLocalhost && ($OPT['default'] ?? false)): ?>
        proxy_set_header Host             $ip_default_host;
        <?php else: ?>
        proxy_set_header Host             $host;
        <?php endif ?>
        proxy_set_header X-Real-IP        $remote_addr;
        proxy_set_header X-Forwarded-For  $proxy_add_x_forwarded_for;
<?php if (!$VAR->domain->physicalHosting->proxySettings['nginxTransparentMode'] && !$VAR->domain->physicalHosting->proxySettings['nginxServeStatic']): ?>
        proxy_set_header X-Accel-Internal /internal-nginx-static-location;
<?php endif ?>
        access_log off;

<?php if ($OPT['nginxCacheEnabled'] ?? true): ?>
    <?=$VAR->includeTemplate('domain/service/nginxCacheProxy.php', $OPT)?>
<?php endif ?>
