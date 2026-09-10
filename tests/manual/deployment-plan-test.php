<?php

require __DIR__ . '/../../app/Services/Deployment/DeploymentPolicy.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;

$operations = [
    new DeploymentOperation(
        relativePath: 'mods/new.jar',
        source: '/tmp/workspace/mods/new.jar',
        destination: '/tmp/server/mods/new.jar',
        policy: DeploymentPolicy::CREATE_ONLY,
    ),
    new DeploymentOperation(
        relativePath: 'config/old.json',
        source: '/tmp/workspace/config/old.json',
        destination: '/tmp/server/config/old.json',
        policy: DeploymentPolicy::OVERWRITE,
    ),
];

$plan = new DeploymentPlan($operations);

if ($plan->totalFiles() !== 2) {
    throw new RuntimeException('Total file count is incorrect.');
}

echo "PASS: total file count correct\n";

if ($plan->createCount() !== 1) {
    throw new RuntimeException('Create count is incorrect.');
}

echo "PASS: create count correct\n";

if ($plan->overwriteCount() !== 1) {
    throw new RuntimeException('Overwrite count is incorrect.');
}

echo "PASS: overwrite count correct\n";

echo "3/3 deployment plan tests passed.\n";
