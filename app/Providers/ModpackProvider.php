<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;

interface ModpackProvider
{
    public function supports(string $source): bool;

    public function getMetadata(string $source): ModpackMetadata;

    public function getPackage(string $source): ModpackPackage;

    /**
     * Releases any temporary resources created for the given package,
     * such as downloaded archive files. Providers that return local
     * fixtures may treat this as a no-op.
     */
    public function cleanup(ModpackPackage $package): void;
}
