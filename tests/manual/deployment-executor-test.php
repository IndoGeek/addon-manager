<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;

$root = sys_get_temp_dir() . '/modpack-executor-test-' . bin2hex(random_bytes(8));
$workspace = $root . '/workspace';
$server = $root . '/server';

mkdir($workspace . '/mods', 0750, true);
mkdir($workspace . '/config', 0750, true);
mkdir($server . '/mods', 0750, true);

file_put_contents(
    $workspace . '/mods/new-mod.jar',
    'new mod contents',
);

file_put_contents(
    $workspace . '/config/example.json',
    '{"enabled":true}',
);

file_put_contents(
    $server . '/mods/existing-mod.jar',
    'old mod contents',
);

$plan = new DeploymentPlan(
    create: [
        'config/example.json',
        'mods/new-mod.jar',
    ],
    overwrite: [
        'mods/existing-mod.jar',
    ],
);

$executor = new DeploymentExecutor();

try {
    /*
     * Create a workspace version of the existing file so the
     * overwrite operation has a source.
     */
    file_put_contents(
        $workspace . '/mods/existing-mod.jar',
        'replacement mod contents',
    );

    $executor->execute(
        $workspace,
        $server,
        $plan,
    );

    if (
        file_get_contents(
            $server . '/config/example.json',
        ) !== '{"enabled":true}'
    ) {
        throw new RuntimeException(
            'New config file was not deployed correctly.'
        );
    }

    if (
        file_get_contents(
            $server . '/mods/new-mod.jar',
        ) !== 'new mod contents'
    ) {
        throw new RuntimeException(
            'New mod file was not deployed correctly.'
        );
    }

    if (
        file_get_contents(
            $server . '/mods/existing-mod.jar',
        ) !== 'replacement mod contents'
    ) {
        throw new RuntimeException(
            'Existing file was not overwritten correctly.'
        );
    }

    $unsafePaths = [
        '../escape.txt',
        'nested/../../escape.txt',
        '/etc/passwd',
        'C:/Windows/System32/test.txt',
    ];

    foreach ($unsafePaths as $unsafePath) {
        $rejected = false;

        try {
            $executor->execute(
                $workspace,
                $server,
                new DeploymentPlan(
                    [$unsafePath],
                    [],
                ),
            );
        } catch (RuntimeException) {
            $rejected = true;
        }

        if (!$rejected) {
            throw new RuntimeException(
                "Unsafe deployment path was not rejected: {$unsafePath}"
            );
        }

        echo "PASS: unsafe path rejected: {$unsafePath}\n";
    }

    echo "PASS: new files deployed\n";
    echo "PASS: planned overwrite deployed\n";
    echo "6/6 tests passed.\n";
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
