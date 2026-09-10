<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

final class InstallationResult
{
    public function __construct(
        public readonly array $created,
        public readonly array $overwritten,
        public readonly array $backedUp,
    ) {
    }

    public function totalFiles(): int
    {
        return count($this->created) + count($this->overwritten);
    }

    public function createdCount(): int
    {
        return count($this->created);
    }

    public function overwrittenCount(): int
    {
        return count($this->overwritten);
    }

    public function backupCount(): int
    {
        return count($this->backedUp);
    }
}
