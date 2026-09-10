<?php

require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationWorkspace.php';
require __DIR__ . '/../../app/Services/Installation/InstallationResult.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';
require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Installation/InstallationOrchestrator.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;

$root = sys_get_temp_dir()
    . '/modpack-rollback-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/pack.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

/*
 * The server already contains a directory named "blocked".
 *
 * The archive contains a file named "blocked".
 *
 * The planner will classify "blocked" as a new file because
 * is_file(server/blocked) is false.
 *
 * The executor will then fail because "blocked" is a directory.
 *
 * "aaa-created.txt" sorts before "blocked", so it gets deployed
 * first and gives us something concrete to verify was rolled back.
 */

mkdir($server . '/blocked', 0750, true);

$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Unable to create test archive.');
}

$zip->addFromString(
    'aaa-created.txt',
    'this file must be rolled back',
);

$zip->addFromString(
    'blocked',
    'this deployment must fail',
);

$zip->close();

$workspaceManager = new InstallationWorkspace($temp);
$planner = new DeploymentPlanner();
$backupManager = new BackupManager();
$executor = new DeploymentExecutor();

$orchestrator = new InstallationOrchestrator(
    workspaceManager: $workspaceManager,
    planner: $planner,
    backupManager: $backupManager,
    executor: $executor,
    temporaryRoot: $temp,
);

$failed = false;

try {
    $orchestrator->install(
        archivePath: $archive,
        serverDirectory: $server,
    );
} catch (Throwable $exception) {
    $failed = true;

    echo "PASS: deployment failure detected\n";
}

try {
    if (!$failed) {
        throw new RuntimeException(
            'The deliberately broken deployment did not fail.'
        );
    }

    if (file_exists($server . '/aaa-created.txt')) {
        throw new RuntimeException(
            'Rollback failed: created file still exists.'
        );
    }

    echo "PASS: newly created file rolled back\n";

    if (!is_dir($server . '/blocked')) {
        throw new RuntimeException(
            'Rollback damaged the pre-existing blocked directory.'
        );
    }

    echo "PASS: pre-existing directory preserved\n";

    echo "3/3 rollback tests passed.\n";
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
