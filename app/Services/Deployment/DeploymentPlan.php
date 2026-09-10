<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

final class DeploymentPlan
{
    /**
     * @param DeploymentOperation[] $operations
     */
    public function __construct(
        public readonly array $operations,
    ) {
    }

    public function totalFiles(): int
    {
        return count($this->operations);
    }

    public function createCount(): int
    {
        return count(
            array_filter(
                $this->operations,
                static fn (DeploymentOperation $operation): bool =>
                    $operation->policy === DeploymentPolicy::CREATE_ONLY,
            ),
        );
    }

    public function overwriteCount(): int
    {
        return count(
            array_filter(
                $this->operations,
                static fn (DeploymentOperation $operation): bool =>
                    $operation->policy === DeploymentPolicy::OVERWRITE,
            ),
        );
    }
}
