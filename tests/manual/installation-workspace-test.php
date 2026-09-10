<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationWorkspace.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;

function createWorkspaceTestZip(): string
{
    $path = tempnam(sys_get_temp_dir(), 'modpack-workspace-');

    if ($path === false) {
        throw new RuntimeException(
            'Unable to create temporary ZIP.'
        );
    }

    $zip = new ZipArchive();

    if (
        $zip->open(
            $path,
            ZipArchive::CREATE | ZipArchive::OVERWRITE,
        ) !== true
    ) {
        throw new RuntimeException(
            'Unable to create test ZIP.'
        );
    }

    $zip->addFromString(
        'mods/example.jar',
        'fake mod contents',
    );

    $zip->addFromString(
        'config/example.json',
        '{"enabled":true}',
    );

    $zip->addFromString(
        'server.properties',
        'motd=Test Modpack',
    );

    $zip->close();

    return $path;
}

$workspaceRoot = sys_get_temp_dir() . '/modpack-installer-workspace-test';

$workspace = new InstallationWorkspace($workspaceRoot);

$archive = createWorkspaceTestZip();

try {
    $destination = $workspace->prepare($archive);

    if (!is_dir($destination)) {
        throw new RuntimeException(
            'Installation workspace was not created.'
        );
    }

    $expectedFiles = [
        'mods/example.jar' => 'fake mod contents',
        'config/example.json' => '{"enabled":true}',
        'server.properties' => 'motd=Test Modpack',
    ];

    foreach ($expectedFiles as $relativePath => $expectedContents) {
        $path = $destination . '/' . $relativePath;

        if (!is_file($path)) {
            throw new RuntimeException(
                "Expected file was not extracted: {$relativePath}"
            );
        }

        $contents = file_get_contents($path);

        if ($contents !== $expectedContents) {
            throw new RuntimeException(
                "Incorrect contents for: {$relativePath}"
            );
        }
    }

    echo "PASS: installation workspace created\n";
    echo "PASS: modpack files extracted into workspace\n";
    echo "Workspace: {$destination}\n";
    echo "2/2 tests passed.\n";
} finally {
    @unlink($archive);
}
