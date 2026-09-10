<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationWorkspace.php';
require __DIR__ . '/../../app/Services/Installation/InstallationResult.php';
require __DIR__ . '/../../app/Services/Installation/PackageLayout.php';
require __DIR__ . '/../../app/Services/Installation/PackageRootResolver.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';
require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Installation/InstallationOrchestrator.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageLayout;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir()
    . '/modpack-package-layout-install-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/archive.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Unable to create test archive.');
}

$zip->addFromString(
    'overrides/mods/example.jar',
    'example jar contents',
);

$zip->close();

$workspaceManager = new InstallationWorkspace($temp);
$planner = new DeploymentPlanner();
$backupManager = new BackupManager(
    new LocalFilesystemServerFileTarget($server),
);
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

try {
    $result = $orchestrator->install(
        archivePath: $archive,
        serverDirectory: $server,
        layout: PackageLayout::OVERRIDES,
    );

    if ($result->totalFiles() !== 1) {
        throw new RuntimeException(
            'Unexpected installed file count.'
        );
    }

    $installed = $server . '/mods/example.jar';
    $stripped = $server . '/overrides/mods/example.jar';

    if (
        file_get_contents($installed) !== 'example jar contents'
    ) {
        throw new RuntimeException(
            'OVERRIDES layout did not place the file at server/mods/example.jar.'
        );
    }

    if (file_exists($stripped)) {
        throw new RuntimeException(
            'OVERRIDES layout leaked the overrides directory into the server.'
        );
    }

    echo "PASS: OVERRIDES layout stripped and installed\n";
    echo "PASS: server/mods/example.jar installed with contents\n";
    echo "PASS: server/overrides/mods/example.jar absent\n";
    echo "3/3 tests passed.\n";
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