<?php

// Functional tests for the restore + integrity feature: - install -> out-of-band file deletion -> verifier flags missing...

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationWorkspace.php';
require __DIR__ . '/../../app/Services/Installation/InstallationResult.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';
require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Installation/InstallationOrchestrator.php';
require __DIR__ . '/../../app/Services/Management/InstallRecord.php';
require __DIR__ . '/../../app/Services/Management/InstallIntegrityVerifier.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallIntegrityVerifier;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir()
    . '/modpack-restore-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/pack.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Unable to create test archive.');
}

$zip->addFromString('mods/alpha.jar', 'alpha contents');
$zip->addFromString('mods/beta.jar', 'beta original');
$zip->addFromString('config/settings.json', '{"keep":true}');
$zip->addFromString('scripts/run.sh', '#!/bin/sh');

$zip->close();

$workspaceManager = new InstallationWorkspace($temp);
$serverFileTarget = new LocalFilesystemServerFileTarget($server);
$planner = new DeploymentPlanner($serverFileTarget);
$backupManager = new BackupManager($serverFileTarget);
$executor = new DeploymentExecutor($serverFileTarget);

$orchestrator = new InstallationOrchestrator(
    workspaceManager: $workspaceManager,
    planner: $planner,
    backupManager: $backupManager,
    executor: $executor,
    temporaryRoot: $temp,
    serverFileTarget: $serverFileTarget,
);

$verifier = new InstallIntegrityVerifier();

$record = new InstallRecord(
    id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    serverUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    provider: 'mock',
    projectId: 'example-pack',
    versionId: 'v1',
    source: 'mock://example-pack@v1',
    displayName: 'Example Pack',
    version: 'v1',
    minecraftVersion: '1.20.1',
    loader: 'fabric',
    iconUrl: null,
    installedAt: gmdate('c'),
    updatedAt: gmdate('c'),
    status: InstallRecord::STATUS_INSTALLED,
    createdFiles: [
        'mods/alpha.jar',
        'mods/beta.jar',
        'config/settings.json',
        'scripts/run.sh',
    ],
    overwrittenFiles: [],
);

$passed = 0;
$failed = 0;

$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}\n";
    }
};

try {
    $result = $orchestrator->install(archivePath: $archive);

    $check($result->totalFiles() === 4, 'install deploys all 4 files');
    $check(file_get_contents($server . '/mods/alpha.jar') === 'alpha contents', 'install wrote mods/alpha.jar');
    $check(file_get_contents($server . '/config/settings.json') === '{"keep":true}', 'install wrote config/settings.json');

    $owned = $record->ownedFiles();

    $check(count($owned) === 4, 'ownedFiles() exposes 4 tracked files');

    // simulate out-of-band deletion in the Files tab (file + whole parent dir)
    unlink($server . '/mods/alpha.jar');
    unlink($server . '/config/settings.json');
    rmdir($server . '/config');
    // user edits an intact file; restore must not touch it
    file_put_contents($server . '/mods/beta.jar', 'beta user edit');

    $missing = $verifier->missingFiles($record, $serverFileTarget);

    $check(
        count($missing) === 2
            && in_array('mods/alpha.jar', $missing, true)
            && in_array('config/settings.json', $missing, true),
        'verifier detects the two deleted files only',
    );
    $check($verifier->isCompletelyGone($record, $serverFileTarget) === false, 'partially-missing record is not completely gone');

    $restoreResult = $orchestrator->restore(
        archivePath: $archive,
        paths: $missing,
    );

    $check($restoreResult->createdCount() === 2, 'restore redeployed exactly the 2 missing files');
    $check($restoreResult->overwrittenCount() === 0, 'restore overwrote nothing');

    $check(file_get_contents($server . '/mods/alpha.jar') === 'alpha contents', 'restore recreated mods/alpha.jar');
    $check(file_get_contents($server . '/config/settings.json') === '{"keep":true}', 'restore recreated config/settings.json (incl. parent dir)');
    $check(file_get_contents($server . '/mods/beta.jar') === 'beta user edit', 'restore left the user-edited intact file untouched');

    $check($verifier->missingFiles($record, $serverFileTarget) === [], 'verifier reports no missing files after restore');

    // none of the owned files anymore -> completely gone
    foreach ($owned as $path) {
        $target = $server . '/' . $path;
        if (is_dir($target)) {
            rmdir($target);
        } elseif (is_file($target)) {
            unlink($target);
        }
    }

    $check($verifier->isCompletelyGone($record, $serverFileTarget) === true, 'all-owned-files-missing record is completely gone');

    // restore with a path that is not part of the archive -> safe no-op
    $ghostResult = $orchestrator->restore(
        archivePath: $archive,
        paths: ['ghost/not-in-pack.txt'],
    );

    $check($ghostResult->createdCount() === 0, 'restoring a path absent from the archive is a safe no-op');

    // restore with an empty path list -> no-op
    $emptyResult = $orchestrator->restore(
        archivePath: $archive,
        paths: [],
    );

    $check($emptyResult->totalFiles() === 0, 'restoring an empty path list is a no-op');

    echo "\n{$passed} passed, {$failed} failed.\n";

    if ($failed > 0) {
        exit(1);
    }
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }
}