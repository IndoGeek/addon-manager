<?php

require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentException.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

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
    $workspace . '/mods/existing-mod.jar',
    'replacement mod contents',
);

file_put_contents(
    $server . '/mods/existing-mod.jar',
    'old mod contents',
);

$plan = new DeploymentPlan([
    new DeploymentOperation(
        relativePath: 'config/example.json',
        source: $workspace . '/config/example.json',
        destination: '',
        policy: DeploymentPolicy::CREATE_ONLY,
    ),

    new DeploymentOperation(
        relativePath: 'mods/new-mod.jar',
        source: $workspace . '/mods/new-mod.jar',
        destination: '',
        policy: DeploymentPolicy::CREATE_ONLY,
    ),

    new DeploymentOperation(
        relativePath: 'mods/existing-mod.jar',
        source: $workspace . '/mods/existing-mod.jar',
        destination: '',
        policy: DeploymentPolicy::OVERWRITE,
    ),
]);

$serverTarget = new LocalFilesystemServerFileTarget($server);

$executor = new DeploymentExecutor(
    $serverTarget,
);

try {
    $deployed = $executor->execute($plan);

    if ($deployed !== [
        'config/example.json',
        'mods/new-mod.jar',
        'mods/existing-mod.jar',
    ]) {
        throw new RuntimeException(
            'Executor did not report the successfully deployed files correctly.'
        );
    }

    echo "PASS: executor reports deployed files\n";

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

    echo "PASS: new files deployed\n";
    echo "PASS: planned overwrite deployed\n";

    $unsafePaths = [
        '../escape.txt',
        'nested/../../escape.txt',
        '/etc/passwd',
        'C:/Windows/System32/test.txt',
    ];

    foreach ($unsafePaths as $unsafePath) {
        $rejected = false;

        try {
            $unsafeSource = $workspace . '/safe.txt';

            file_put_contents($unsafeSource, 'unsafe test');

            $executor->execute(
                new DeploymentPlan([
                    new DeploymentOperation(
                        relativePath: $unsafePath,
                        source: $unsafeSource,
                        destination: '',
                        policy: DeploymentPolicy::CREATE_ONLY,
                    ),
                ]),
            );
        } catch (DeploymentException) {
            $rejected = true;
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

    echo "7/7 tests passed.\n";
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
