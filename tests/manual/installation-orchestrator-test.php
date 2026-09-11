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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir()
    . '/modpack-orchestrator-test-'
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

$zip->addFromString(
    'mods/new-mod.jar',
    'new mod contents',
);

$zip->addFromString(
    'config/example.json',
    '{"enabled":true}',
);

$zip->close();

$workspaceManager = new InstallationWorkspace($temp);
$serverFileTarget = new LocalFilesystemServerFileTarget($server);
$planner = new DeploymentPlanner(
    $serverFileTarget,
);
$backupManager = new BackupManager(
    $serverFileTarget,
);
$executor = new DeploymentExecutor(
    $serverFileTarget,
);
$packageRootResolver = new PackageRootResolver();

$orchestrator = new InstallationOrchestrator(
    workspaceManager: $workspaceManager,
    packageRootResolver: $packageRootResolver,
    planner: $planner,
    backupManager: $backupManager,
    executor: $executor,
    temporaryRoot: $temp,
    serverFileTarget: $serverFileTarget,
);

try {
    $result = $orchestrator->install(
        archivePath: $archive,
    );

    if (!$result instanceof \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationResult) {
        throw new RuntimeException(
            'Installation did not return an InstallationResult.'
        );
    }

    if ($result->totalFiles() !== 2) {
        throw new RuntimeException(
            'Unexpected installed file count.'
        );
    }

    if (
        file_get_contents(
            $server . '/mods/new-mod.jar',
        ) !== 'new mod contents'
    ) {
        throw new RuntimeException(
            'New file was not installed correctly.'
        );
    }

    if (
        file_get_contents(
            $server . '/config/example.json',
        ) !== '{"enabled":true}'
    ) {
        throw new RuntimeException(
            'Config file was not installed correctly.'
        );
    }

    echo "PASS: installation completed\n";
    echo "PASS: result contains installed files\n";
    echo "PASS: installed file contents verified\n";
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
