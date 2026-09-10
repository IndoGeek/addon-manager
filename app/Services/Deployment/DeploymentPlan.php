<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

final class DeploymentPlan
{
    /**
     * @param list<string> $create
     * @param list<string> $overwrite
     */
    public function __construct(
        public readonly array $create,
        public readonly array $overwrite,
    ) {
    }

    public function totalFiles(): int
    {
        return count($this->create) + count($this->overwrite);
    }
}
