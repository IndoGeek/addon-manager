<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

final class DeploymentOperation
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $source,
        public readonly string $destination,
        public readonly bool $overwrite,
    ) {
    }
}
