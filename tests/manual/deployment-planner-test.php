<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlanner.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;

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

$planner = new DeploymentPlanner();

try {
    $plan = $planner->plan($workspace, $server);

    $expectedCreate = [
        'config/example.json',
        'mods/new-mod.jar',
        'server.properties',
    ];

    $expectedOverwrite = [
        'mods/existing-mod.jar',
    ];

    /*
     * existing-mod.jar is not in the workspace, so it must NOT appear
     * in the overwrite list.
     *
     * Instead, the planner should only classify files that actually
     * exist in the workspace.
     */
    $expectedOverwrite = [];

    if ($plan->create !== $expectedCreate) {
        throw new RuntimeException(
            'Create plan does not match expected files.'
        );
    }

    if ($plan->overwrite !== $expectedOverwrite) {
        throw new RuntimeException(
            'Overwrite plan does not match expected files.'
        );
    }

    echo "PASS: new files identified correctly\n";
    echo "PASS: existing server-only files left untouched\n";
    echo "2/2 tests passed.\n";
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
