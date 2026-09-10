<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveValidator;

$validator = new ArchiveValidator();

function createZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'modpack-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test ZIP.');
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return $path;
}

$tests = [
    'valid archive' => [
        'entries' => [
            'mods/example.jar' => 'fake jar',
            'config/example.json' => '{}',
        ],
        'shouldPass' => true,
    ],
    'parent traversal' => [
        'entries' => [
            '../../etc/passwd' => 'blocked',
        ],
        'shouldPass' => false,
    ],
    'nested traversal' => [
        'entries' => [
            'mods/../../evil.jar' => 'blocked',
        ],
        'shouldPass' => false,
    ],
    'absolute path' => [
        'entries' => [
            '/etc/passwd' => 'blocked',
        ],
        'shouldPass' => false,
    ],
    'windows path' => [
        'entries' => [
            '..\\..\\evil.jar' => 'blocked',
        ],
        'shouldPass' => false,
    ],
];

$passed = 0;

foreach ($tests as $name => $test) {
    $path = createZip($test['entries']);

    try {
        $validator->validate($path);
        $actualPass = true;
    } catch (Throwable) {
        $actualPass = false;
    } finally {
        @unlink($path);
    }

    if ($actualPass === $test['shouldPass']) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
    }
}

echo "\n{$passed}/" . count($tests) . " tests passed.\n";

if ($passed !== count($tests)) {
    exit(1);
}
