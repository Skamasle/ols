<?php

require_once __DIR__
    . '/../../extension/plib/library/EnginePackageInstaller.php';

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

class TestEnginePackageInstaller extends Modules_SkamasleOls_EnginePackageInstaller
{
    private $commands;
    private $counts;
    private $repoDirectory;

    public function __construct(array $commands, $repoDirectory = '/etc/yum.repos.d')
    {
        $this->commands = $commands;
        $this->repoDirectory = $repoDirectory;
    }

    protected function isRoot()
    {
        return true;
    }

    protected function detectPackageManager()
    {
        return 'dnf';
    }

    protected function runCommand($command)
    {
        if (!isset($this->counts[$command])) {
            $this->counts[$command] = 0;
        }
        $this->counts[$command]++;

        if (!isset($this->commands[$command])) {
            return array('exitCode' => 1, 'output' => '');
        }

        if (is_array($this->commands[$command])
            && isset($this->commands[$command][0])
            && is_array($this->commands[$command][0])
        ) {
            $index = min(
                $this->counts[$command] - 1,
                count($this->commands[$command]) - 1
            );
            return $this->commands[$command][$index];
        }

        return $this->commands[$command];
    }

    protected function repositoryDirectory()
    {
        return $this->repoDirectory;
    }
}

$repoDirectory = sys_get_temp_dir() . '/skamasle-ols-repo-' . bin2hex(random_bytes(6));
mkdir($repoDirectory, 0700, true);

try {
$installer = new TestEnginePackageInstaller(array(
    'test -f ' . escapeshellarg($repoDirectory . '/skamasle-ols-custom.repo') => array(
        array(
            'exitCode' => 1,
            'output' => '',
        ),
        array(
            'exitCode' => 1,
            'output' => '',
        ),
    ),
    'test -f ' . escapeshellarg($repoDirectory . '/litespeed.repo') => array(
        array(
            'exitCode' => 1,
            'output' => '',
        ),
        array(
            'exitCode' => 0,
            'output' => '',
        ),
    ),
    'command -v wget' => array(
        'exitCode' => 0,
        'output' => '/usr/bin/wget',
    ),
    'wget -O - https://repo.litespeed.sh | bash' => array(
        'exitCode' => 0,
        'output' => 'Repository configured',
    ),
    "rpm -q 'openlitespeed'" => array(
        array(
            'exitCode' => 1,
            'output' => 'package openlitespeed is not installed',
        ),
        array(
            'exitCode' => 0,
            'output' => 'openlitespeed-1.0.0',
        ),
    ),
    "dnf install -y 'openlitespeed'" => array(
        'exitCode' => 0,
        'output' => 'Installed',
    ),
), $repoDirectory);

$result = $installer->install('openlitespeed');

assertSameValue(true, $result['available'], 'Package install must be available');
assertSameValue(true, $result['installed'], 'Package install must succeed');
assertSameValue('dnf', $result['packageManager'], 'Package manager must be dnf');
assertSameValue(
    true,
    $result['repository']['configured'],
    'Repository must be configured before package install'
);
assertSameValue(
    'wget -O - https://repo.litespeed.sh | bash',
    $result['repository']['command'],
    'Repository bootstrap command must be recorded'
);

$customInstaller = new TestEnginePackageInstaller(array(), $repoDirectory);
$customResult = $customInstaller->install('openlitespeed', array(
    'mode' => 'custom-repo-url',
    'customRepoUrl' => 'https://repo.example.test/openlitespeed/$basearch',
));
assertSameValue(
    true,
    $customResult['repository']['configured'],
    'Custom repository mode must write a repository file'
);
assertSameValue(
    'custom-repo-url',
    $customResult['mode'],
    'Custom repository mode must be preserved in the result'
);

$existingInstaller = new TestEnginePackageInstaller(array(
    'test -x /usr/local/lsws/bin/openlitespeed' => array(
        'exitCode' => 0,
        'output' => '',
    ),
), $repoDirectory);
$existingResult = $existingInstaller->install('openlitespeed', array(
    'mode' => 'already-installed',
));
assertSameValue(
    true,
    $existingResult['installed'],
    'Existing installation mode must validate the current OLS binary'
);
assertSameValue(
    'already-installed',
    $existingResult['mode'],
    'Existing installation mode must be preserved in the result'
);
} finally {
    foreach (glob($repoDirectory . '/*') as $file) {
        unlink($file);
    }
    @rmdir($repoDirectory);
}
