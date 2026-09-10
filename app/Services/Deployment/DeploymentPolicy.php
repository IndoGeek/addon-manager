<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

enum DeploymentPolicy: string
{
    case CREATE_ONLY = 'create_only';
    case OVERWRITE = 'overwrite';
    case SKIP_EXISTING = 'skip_existing';
}
