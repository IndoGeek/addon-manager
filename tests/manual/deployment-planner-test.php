<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;

$root = sys_get_temp_dir() . '/modpack-deployment-test-' . bin2hex(random_bytes(8));
$workspace = $root . '/workspace';
$server = $root . '/server';

mkdir($workspace . '/mods', 0750, true);
mkdir($workspace . '/config', 0750, true);
mkdir($server . '/mods', 0750, true);

file_put_contents(
    $workspace . '/mods/new-mod.jar',
    'new mod',
);

file_put_contents(
    $workspace . '/config/example.json',
    '{}',
);

file_put_contents(
    $workspace . '/server.properties',
    'motd=New Pack',
);

file_put_contents(
    $server . '/mods/existing-mod.jar',
    'old mod',
);

file_put_contents(
    $server . '/server.properties',
    'motd=Old Pack',
);

$planner = new DeploymentPlanner();

try {
    $expectedCreate = [
        'config/example.json',
        'mods/new-mod.jar',
    ];

    $expectedOverwrite = [
        'server.properties',
    ];

    $createOnlyPlan = $planner->plan(
        $workspace,
        $server,
        DeploymentPolicy::CREATE_ONLY,
    );

    if ($createOnlyPlan->createCount() !== 2) {
        throw new RuntimeException(
            'CREATE_ONLY create count is incorrect.'
        );
    }

    if ($createOnlyPlan->overwriteCount() !== 0) {
        throw new RuntimeException(
            'CREATE_ONLY must never schedule overwrites.'
        );
    }

    $createOnlyPaths = array_map(
        static fn ($operation): string => $operation->relativePath,
        $createOnlyPlan->operations,
    );

    if ($createOnlyPaths !== $expectedCreate) {
        throw new RuntimeException(
            'CREATE_ONLY operation paths do not match expected files.'
        );
    }

    echo "PASS: CREATE_ONLY prevents overwrites\n";

    $skipExistingPlan = $planner->plan(
        $workspace,
        $server,
        DeploymentPolicy::SKIP_EXISTING,
    );

    if ($skipExistingPlan->createCount() !== 2) {
        throw new RuntimeException(
            'SKIP_EXISTING create count is incorrect.'
        );
    }

    if ($skipExistingPlan->overwriteCount() !== 0) {
        throw new RuntimeException(
            'SKIP_EXISTING must never schedule overwrites.'
        );
    }

    echo "PASS: SKIP_EXISTING prevents overwrites\n";

    $overwritePlan = $planner->plan(
        $workspace,
        $server,
        DeploymentPolicy::OVERWRITE,
    );

    if ($overwritePlan->createCount() !== 2) {
        throw new RuntimeException(
            'OVERWRITE create count is incorrect.'
        );
    }

    if ($overwritePlan->overwriteCount() !== 1) {
        throw new RuntimeException(
            'OVERWRITE overwrite count is incorrect.'
        );
    }

    $overwriteOperations = array_filter(
        $overwritePlan->operations,
        static fn ($operation): bool =>
            $operation->policy === DeploymentPolicy::OVERWRITE,
    );

    $overwritePaths = array_map(
        static fn ($operation): string => $operation->relativePath,
        $overwriteOperations,
    );

    $overwritePaths = array_values($overwritePaths);

    if ($overwritePaths !== $expectedOverwrite) {
        throw new RuntimeException(
            'OVERWRITE operation paths do not match expected files.'
        );
    }

    echo "PASS: OVERWRITE identifies files for replacement\n";

    echo "3/3 policy tests passed.\n";
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
