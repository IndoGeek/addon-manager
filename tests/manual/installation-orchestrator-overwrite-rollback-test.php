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
    . '/modpack-overwrite-rollback-test-'
    . bin2hex(random_bytes(8));

$archive = $root . '/pack.zip';
$server = $root . '/server';
$temp = $root . '/temp';

mkdir($server, 0750, true);
mkdir($temp, 0750, true);

/*
 * Existing files that will be overwritten.
 */
file_put_contents(
    $server . '/aaa-existing.txt',
    'ORIGINAL AAA',
);

file_put_contents(
    $server . '/blocked',
    'ORIGINAL BLOCKED',
);

/*
 * Archive contains replacements.
 *
 * aaa-existing.txt sorts first and will be overwritten successfully.
 *
 * blocked is turned into a directory on the server after planning,
 * forcing the executor to fail when it reaches that path.
 */
$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Unable to create test archive.');
}

$zip->addFromString(
    'aaa-existing.txt',
    'REPLACED AAA',
);

$zip->addFromString(
    'blocked',
    'REPLACED BLOCKED',
);

$zip->close();

/*
 * We need the planner to see both paths as existing files,
 * but we need "blocked" to become a directory before execution.
 *
 * To do that we first create a normal installation workspace
 * and construct the deployment plan manually.
 */

$workspaceManager = new InstallationWorkspace($temp);

$workspace = $workspaceManager->prepare($archive);

$planner = new DeploymentPlanner();
$backupManager = new BackupManager();
$executor = new DeploymentExecutor();

$plan = $planner->plan(
    $workspace,
    $server,
);

if ($plan->overwrite !== [
    'aaa-existing.txt',
    'blocked',
]) {
    throw new RuntimeException(
        'Unexpected overwrite plan.'
    );
}

/*
 * Replace the "blocked" server file with a directory AFTER planning.
 */
unlink($server . '/blocked');
mkdir($server . '/blocked', 0750, true);

$orchestrator = new InstallationOrchestrator(
    workspaceManager: $workspaceManager,
    planner: $planner,
    backupManager: $backupManager,
    executor: $executor,
    temporaryRoot: $temp,
);

/*
 * The orchestrator will create its own workspace, so recreate
 * the original server state needed by the orchestrator.
 */
rmdir($server . '/blocked');

file_put_contents(
    $server . '/blocked',
    'ORIGINAL BLOCKED',
);

$failed = false;

try {
    /*
     * Force the failure using a custom executor subclass is not
     * possible because the orchestrator requires the concrete
     * DeploymentExecutor class.
     *
     * Instead, the test uses a server filesystem conflict that
     * occurs after planning inside the orchestrator by creating
     * a directory through the target path.
     */
    unlink($server . '/blocked');
    mkdir($server . '/blocked', 0750, true);

    $orchestrator->install(
        archivePath: $archive,
        serverDirectory: $server,
    );
} catch (Throwable $exception) {
    $failed = true;

    echo "PASS: overwrite deployment failure detected\n";
} finally {
    $workspaceManager->cleanup($workspace);
}

try {
    if (!$failed) {
        throw new RuntimeException(
            'The deliberately broken overwrite deployment did not fail.'
        );
    }

    /*
     * The first overwrite must have been rolled back.
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

    echo "PASS: first overwritten file restored\n";

    /*
     * The blocked directory must still exist.
     */
    if (!is_dir($server . '/blocked')) {
        throw new RuntimeException(
            'Rollback damaged the blocked directory.'
        );
    }

    echo "PASS: conflicting target preserved\n";

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
