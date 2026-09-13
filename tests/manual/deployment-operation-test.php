<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;

$operation = new DeploymentOperation(
    relativePath: 'mods/example.jar',
    source: '/tmp/workspace/mods/example.jar',
    destination: '/tmp/server/mods/example.jar',
    overwrite: true,
);

if ($operation->relativePath !== 'mods/example.jar') {
    throw new RuntimeException('Relative path is incorrect.');
}

echo "PASS: relative path stored\n";

if ($operation->source !== '/tmp/workspace/mods/example.jar') {
    throw new RuntimeException('Source path is incorrect.');
}

echo "PASS: source path stored\n";

if ($operation->destination !== '/tmp/server/mods/example.jar') {
    throw new RuntimeException('Destination path is incorrect.');
}

echo "PASS: destination path stored\n";

if ($operation->overwrite !== true) {
    throw new RuntimeException('Overwrite flag is incorrect.');
}

echo "PASS: overwrite flag stored\n";

echo "4/4 deployment operation tests passed.\n";