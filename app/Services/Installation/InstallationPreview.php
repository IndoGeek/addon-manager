<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;

final class InstallationPreview
{
    public function __construct(
        public readonly DeploymentPlan $plan,
    ) {
    }

    public function totalFiles(): int
    {
        return $this->plan->totalFiles();
    }

    public function createdCount(): int
    {
        return count($this->plan->create);
    }

    public function overwrittenCount(): int
    {
        return count($this->plan->overwrite);
    }
}
