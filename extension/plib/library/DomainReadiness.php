<?php

class Modules_SkamasleOls_DomainReadiness
{
    public function evaluate(array $domain, array $htaccess, array $server = array())
    {
        if ('not-scanned' === $htaccess['status']) {
            return array(
                'status' => 'review',
                'label' => 'Scan required',
                'reasons' => array(
                    'Run a .htaccess compatibility scan before enabling OLS.',
                ),
                'routingControlEnabled' => false,
                'acknowledgementRequired' => false,
            );
        }

        $reasons = array();
        if (empty($domain['hosting'])) {
            $reasons[] = 'Physical web hosting is unavailable.';
        }
        if (!empty($domain['suspended'])) {
            $reasons[] = 'Domain is suspended.';
        } elseif (empty($domain['active'])) {
            $reasons[] = 'Domain is inactive.';
        }
        if ('blocked' === $htaccess['status']) {
            $reasons[] = '.htaccess analysis could not be completed safely.';
        }
        if (empty($server['nginx']['active'])) {
            $reasons[] = 'nginx must be active before OLS can be enabled.';
        }
        if (isset($domain['nativeWebMode']) && 'nginx-only' === $domain['nativeWebMode']) {
            $reasons[] = 'This domain is using nginx + PHP-FPM. Switch it to nginx + Apache + PHP before enabling OLS.';
        }

        if (!empty($reasons)) {
            return array(
                'status' => 'blocked',
                'label' => 'Blocked',
                'reasons' => $reasons,
                'routingControlEnabled' => false,
                'acknowledgementRequired' => false,
            );
        }

        if ('review' === $htaccess['status']) {
            return array(
                'status' => 'review',
                'label' => 'Review required',
                'reasons' => array(
                    'Apache directives may be ignored or behave differently in OLS.',
                    'Activation will require explicit administrator acknowledgement.',
                ),
                'routingControlEnabled' => false,
                'acknowledgementRequired' => true,
            );
        }

        return array(
            'status' => 'pending',
            'label' => 'Pending',
            'reasons' => array(
                'PHP profile and certified routing adapter are not available yet.',
            ),
            'routingControlEnabled' => false,
            'acknowledgementRequired' => false,
        );
    }
}
