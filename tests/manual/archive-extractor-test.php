<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveExtractor;

function createTestZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'modpack-extractor-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary ZIP.');
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP.');
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return $path;
}

$temporaryRoot = sys_get_temp_dir() . '/modpack-installer-test';

@mkdir($temporaryRoot, 0750, true);

$extractor = new ArchiveExtractor($temporaryRoot);

$archive = createTestZip([
    'mods/example.jar' => 'fake mod',
    'config/test.json' => '{"test":true}',
]);

try {
    $destination = $extractor->extract($archive);

    $modFile = $destination . '/mods/example.jar';
    $configFile = $destination . '/config/test.json';

    if (!is_file($modFile)) {
        throw new RuntimeException('Expected mod file was not extracted.');
    }

    if (!is_file($configFile)) {
        throw new RuntimeException('Expected config file was not extracted.');
    }

    if (file_get_contents($modFile) !== 'fake mod') {
        throw new RuntimeException('Extracted mod contents are incorrect.');
    }

    if (file_get_contents($configFile) !== '{"test":true}') {
        throw new RuntimeException('Extracted config contents are incorrect.');
    }

    echo "PASS: valid archive extracted correctly\n";

    $maliciousArchive = createTestZip([
        '../../outside.txt' => 'must not escape',
    ]);

    try {
        $extractor->extract($maliciousArchive);

        echo "FAIL: malicious archive was extracted\n";
        exit(1);
    } catch (InvalidArgumentException) {
        echo "PASS: malicious archive rejected\n";
    } finally {
        @unlink($maliciousArchive);
    }
} finally {
    @unlink($archive);
}

echo "2/2 tests passed.\n";
