<?php

class Modules_SkamasleOls_WebServer extends pm_Hook_WebServer
{
    public function getDomainNginxConfig(pm_Domain $domain)
    {
        if ('ols' !== $domain->getSetting('skamasle-ols.routing', 'native')) {
            return '';
        }

        $port = (int) pm_Settings::get('listener.port');
        if ($port < 1024 || $port > 65535) {
            return '';
        }
        $domainName = strtolower((string) $domain->getName());
        if (!preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $domainName
        )) {
            return '';
        }
        $domainKey = method_exists($domain, 'getAsciiName')
            ? (string) $domain->getAsciiName()
            : (string) $domain->getName();
        $routingPath = '/usr/local/psa/var/modules/skamasle-ols/nginx-routing/'
            . $domainKey . '.conf';
        return implode(PHP_EOL, array(
            '# BEGIN SKAMASLE OLS',
            'error_page 418 = @skamasle_ols;',
            'return 418;',
            'location @skamasle_ols {',
            '    proxy_pass https://127.0.0.1:' . $port . ';',
            '    proxy_http_version 1.1;',
            '    proxy_ssl_server_name on;',
            '    proxy_ssl_name $host;',
            '    proxy_ssl_verify off;',
            '    proxy_set_header Host ' . $domainName . ';',
            '    proxy_set_header X-Real-IP $remote_addr;',
            '    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '    proxy_set_header X-Forwarded-Proto $scheme;',
            '    proxy_set_header X-Forwarded-Host $host;',
            '}',
            '# END SKAMASLE OLS',
            '',
        ));
    }
}
