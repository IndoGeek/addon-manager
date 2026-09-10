<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationWorkspace.php';
require __DIR__ . '/../../app/Services/Installation/InstallationResult.php';
require __DIR__ . '/../../app/Services/Installation/InstallationPreview.php';
require __DIR__ . '/../../app/Services/Installation/PackageLayout.php';
require __DIR__ . '/../../app/Services/Installation/PackageRootResolver.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';
require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentException.php';
require __DIR__ . '/../../app/Services/Installation/InstallationOrchestrator.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationPreview;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;

$root = sys_get_temp_dir()
    . '/modpack-preview-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/pack.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

file_put_contents(
    $server . '/existing.txt',
    'original',
);

$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException(
        'Unable to create test archive.'
    );
}

$zip->addFromString(
    'existing.txt',
    'replacement',
);

$zip->addFromString(
    'new.txt',
    'new file',
);

$zip->close();

$workspaceManager = new InstallationWorkspace($temp);
$planner = new DeploymentPlanner();
$backupManager = new BackupManager();
$executor = new DeploymentExecutor();
$packageRootResolver = new PackageRootResolver();

$orchestrator = new InstallationOrchestrator(
    workspaceManager: $workspaceManager,
    packageRootResolver: $packageRootResolver,
    planner: $planner,
    backupManager: $backupManager,
    executor: $executor,
    temporaryRoot: $temp,
);

$preview = $orchestrator->preview(
    archivePath: $archive,
    serverDirectory: $server,
);

if (!$preview instanceof InstallationPreview) {
    throw new RuntimeException(
        'Preview did not return an InstallationPreview.'
    );
}

echo "PASS: preview created\n";

if ($preview->createdCount() !== 1) {
    throw new RuntimeException(
        'Expected exactly one new file.'
    );
}

echo "PASS: new file count correct\n";

if ($preview->overwrittenCount() !== 1) {
    throw new RuntimeException(
        'Expected exactly one overwrite.'
    );
}

echo "PASS: overwrite count correct\n";

if ($preview->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected exactly two planned files.'
    );
}

echo "PASS: total file count correct\n";

/*
 * Preview must not modify the server.
 */
if (
    file_get_contents(
        $server . '/existing.txt',
    ) !== 'original'
) {
    throw new RuntimeException(
        'Preview modified an existing server file.'
    );
}

if (file_exists($server . '/new.txt')) {
    throw new RuntimeException(
        'Preview deployed a new file.'
    );
}

echo "PASS: preview does not modify server\n";

echo "5/5 preview tests passed.\n";

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
