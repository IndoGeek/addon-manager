<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir()
    . '/modpack-planner-test-'
    . bin2hex(random_bytes(8));

$workspace = $root . '/workspace';
$server = $root . '/server';

mkdir($workspace . '/mods', 0750, true);
mkdir($workspace . '/config', 0750, true);
mkdir($server . '/mods', 0750, true);

file_put_contents(
    $workspace . '/mods/new.jar',
    'new mod',
);

file_put_contents(
    $workspace . '/mods/existing.jar',
    'replacement mod',
);

file_put_contents(
    $workspace . '/config/example.json',
    '{"enabled":true}',
);

file_put_contents(
    $server . '/mods/existing.jar',
    'old mod',
);

$target = new LocalFilesystemServerFileTarget($server);
$planner = new DeploymentPlanner($target);

try {
    $createOnly = $planner->plan(
        $workspace,
        DeploymentPolicy::CREATE_ONLY,
    );

    if ($createOnly->totalFiles() !== 2) {
        throw new RuntimeException(
            'CREATE_ONLY should plan only new files.'
        );
    }

    echo "PASS: CREATE_ONLY prevents overwrites\n";

    $skipExisting = $planner->plan(
        $workspace,
        DeploymentPolicy::SKIP_EXISTING,
    );

    if ($skipExisting->totalFiles() !== 2) {
        throw new RuntimeException(
            'SKIP_EXISTING should skip existing files.'
        );
    }

    echo "PASS: SKIP_EXISTING prevents overwrites\n";

    $overwrite = $planner->plan(
        $workspace,
        DeploymentPolicy::OVERWRITE,
    );

    if ($overwrite->totalFiles() !== 3) {
        throw new RuntimeException(
            'OVERWRITE should include all files.'
        );
    }

    if ($overwrite->overwriteCount() !== 1) {
        throw new RuntimeException(
            'OVERWRITE should identify the existing file.'
        );
    }

    echo "PASS: OVERWRITE identifies files for replacement\n";

    foreach ($overwrite->operations as $operation) {
        if ($operation->destination !== $operation->relativePath) {
            throw new RuntimeException(
                'Deployment destination must remain relative.'
            );
        }
    }

    echo "PASS: destinations remain relative\n";

    $directoryPath = $server . '/directory-target';

    mkdir($directoryPath, 0750, true);

    $directoryWorkspace = $root . '/directory-workspace';

    mkdir($directoryWorkspace, 0750, true);

    file_put_contents(
        $directoryWorkspace . '/directory-target',
        'this is a file',
    );

    try {
        $planner->plan(
            $directoryWorkspace,
            DeploymentPolicy::OVERWRITE,
        );

        throw new RuntimeException(
            'Planner should reject a target directory.'
        );
    } catch (InvalidArgumentException $exception) {
        echo "PASS: target directories are rejected\n";
    }

    echo "4/4 deployment planner tests passed.\n";
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
