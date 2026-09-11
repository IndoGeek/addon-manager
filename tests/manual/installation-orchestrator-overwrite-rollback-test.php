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
    . '/modpack-overwrite-rollback-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/pack.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

/*
 * This file already exists and will be overwritten.
 */
file_put_contents(
    $server . '/aaa-existing.txt',
    'ORIGINAL AAA',
);

/*
 * This directory will intentionally conflict with a file
 * in the archive.
 *
 * The planner sees it as a "create" target because it is
 * not a file. The executor will encounter it after the
 * successful overwrite of aaa-existing.txt and fail.
 */
mkdir($server . '/blocked', 0750, true);

/*
 * Build the test archive.
 *
 * aaa-existing.txt sorts before blocked, so the overwrite
 * happens first. blocked then causes deployment to fail.
 */
$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException(
        'Unable to create test archive.'
    );
}

$zip->addFromString(
    'aaa-existing.txt',
    'REPLACED AAA',
);

$zip->addFromString(
    'blocked',
    'THIS MUST NOT BE DEPLOYED',
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

$failed = false;

try {
    $orchestrator->install(
        archivePath: $archive,
        policy: DeploymentPolicy::OVERWRITE,
    );
} catch (Throwable $exception) {
    $failed = true;

    echo "PASS: overwrite deployment failure detected\n";
}

try {
    if (!$failed) {
        throw new RuntimeException(
            'The deliberately broken overwrite deployment did not fail.'
        );
    }

    /*
     * The overwrite happened before the failure.
     *
     * Rollback must restore the original contents.
     */
    if (
        file_get_contents(
            $server . '/aaa-existing.txt',
        ) !== 'ORIGINAL AAA'
    ) {
        throw new RuntimeException(
            'Rollback failed: original AAA file was not restored.'
        );
    }

    echo "PASS: overwritten file restored\n";

    /*
     * The conflicting directory must remain untouched.
     */
    if (!is_dir($server . '/blocked')) {
        throw new RuntimeException(
            'Rollback damaged the pre-existing blocked directory.'
        );
    }

    echo "PASS: conflicting directory preserved\n";

    echo "3/3 overwrite rollback tests passed.\n";
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
