<?php

require_once __DIR__
    . '/../../extension/plib/library/DesiredStateValidator.php';

$path = __DIR__ . '/../../fixtures/state-contract-v1.json';
$cases = json_decode(file_get_contents($path), true);
if (!is_array($cases)) {
    throw new RuntimeException('Unable to load shared state contract fixtures.');
}

$validator = new Modules_SkamasleOls_DesiredStateValidator();
foreach ($cases as $case) {
    $accepted = true;
    try {
        $validator->validate($case['state']);
    } catch (InvalidArgumentException $exception) {
        $accepted = false;
    }
    if ($accepted !== $case['valid']) {
        throw new RuntimeException(
            'Contract case ' . $case['name'] . ' produced an inconsistent PHP result.'
        );
    }
}
