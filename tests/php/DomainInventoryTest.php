<?php

require_once __DIR__
    . '/../../extension/plib/library/DomainInventory.php';

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

class pm_Domain
{
    public static function getAllDomains()
    {
        $domains = array();
        for ($index = 1; $index <= 6; $index++) {
            $domains[] = new TestDomain(
                'site' . $index . '.test',
                '/var/www/vhosts/site' . $index . '.test/httpdocs',
                'user' . $index,
                $index % 2 === 0
            );
        }

        return $domains;
    }
}

class TestHtaccessScanner
{
    public $calls = 0;

    public function scan($documentRoot)
    {
        $this->calls++;
        return array(
            'status' => false !== strpos($documentRoot, 'site5')
                ? 'review'
                : 'compatible',
            'filesScanned' => 1,
            'findingCount' => 0,
            'summary' => array(),
            'findings' => array(),
        );
    }
}

class TestReadiness
{
    public function evaluate(array $domain, array $htaccess, array $server = array())
    {
        return array(
            'status' => 'compatible',
            'label' => 'Ready',
            'reasons' => array(),
            'acknowledgementRequired' => false,
        );
    }
}

class TestServiceStatus
{
    public function getNginxStatus()
    {
        return array('active' => true, 'state' => 'running');
    }
}

class TestDomain
{
    private $name;
    private $documentRoot;
    private $systemUser;
    private $prepared;
    private $htaccessScan;

    public function __construct($name, $documentRoot, $systemUser, $prepared)
    {
        $this->name = $name;
        $this->documentRoot = $documentRoot;
        $this->systemUser = $systemUser;
        $this->prepared = $prepared;
        $this->htaccessScan = '';
        if ('site5.test' === $name) {
            $this->htaccessScan = json_encode(array(
                'status' => 'review',
                'filesScanned' => 3,
                'findingCount' => 2,
                'summary' => array(array(
                    'directive' => 'options',
                    'classification' => 'unsupported-behavior',
                    'count' => 2,
                    'exampleFile' => '.htaccess',
                    'exampleLine' => 4,
                )),
                'scannedAt' => '2026-06-14T12:00:00Z',
                'scanDepth' => '4',
            ));
        }
    }

    public function hasHosting()
    {
        return true;
    }

    public function getDisplayName()
    {
        return $this->name;
    }

    public function getGuid()
    {
        return '123e4567-e89b-42d3-a456-42661417400' . substr($this->systemUser, -1);
    }

    public function isActive()
    {
        return true;
    }

    public function isSuspended()
    {
        return false;
    }

    public function getSysUserLogin()
    {
        return $this->systemUser;
    }

    public function getDocumentRoot()
    {
        return $this->documentRoot;
    }

    public function getSetting($key, $default = null)
    {
        if ('skamasle-ols.prepared' === $key) {
            return $this->prepared ? '1' : '0';
        }
        if ('skamasle-ols.lscache' === $key) {
            return $this->prepared ? '1' : '0';
        }
        if ('skamasle-ols.routing' === $key) {
            return $this->prepared ? 'ols' : 'native';
        }
        if (Modules_SkamasleOls_DomainInventory::HTACCESS_SCAN_SETTING === $key) {
            return $this->htaccessScan;
        }

        return $default;
    }
}

$scanner = new TestHtaccessScanner();
$inventory = new Modules_SkamasleOls_DomainInventory(
    $scanner,
    new TestReadiness(),
    new TestServiceStatus()
);
$all = $inventory->getSummary('', 1, 3);
assertSameValue(6, $all['total'], 'Inventory must report the total number of domains');
assertSameValue(6, $all['filtered'], 'Inventory must report the filtered total');
assertSameValue(3, $all['displayed'], 'Inventory must page results');
assertSameValue(1, $all['page'], 'Inventory must start on page one');
assertSameValue(2, $all['pages'], 'Inventory must calculate the page count');
assertSameValue('site1.test', $all['items'][0]['name'], 'Inventory must preserve domain order');
assertSameValue(
    'not-scanned',
    $all['items'][0]['htaccess']['status'],
    'Inventory must not scan .htaccess automatically'
);
assertSameValue(
    0,
    $scanner->calls,
    'Inventory must not invoke the scanner during normal dashboard loading'
);

$filtered = $inventory->getSummary('site5', 1, 10);
assertSameValue(1, $filtered['filtered'], 'Inventory search must filter domains');
assertSameValue(1, $filtered['displayed'], 'Inventory search must return one match');
assertSameValue('site5.test', $filtered['items'][0]['name'], 'Inventory search must match by domain name');
assertSameValue(
    'review',
    $filtered['items'][0]['htaccess']['status'],
    'Inventory must reuse the cached .htaccess scan result when present'
);
