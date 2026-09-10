<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;

$operation = new DeploymentOperation(
    relativePath: 'mods/example.jar',
    source: '/tmp/workspace/mods/example.jar',
    destination: '/tmp/server/mods/example.jar',
    policy: DeploymentPolicy::OVERWRITE,
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

if ($operation->policy !== DeploymentPolicy::OVERWRITE) {
    throw new RuntimeException('Deployment policy is incorrect.');
}

echo "PASS: deployment policy stored\n";

echo "4/4 deployment operation tests passed.\n";
