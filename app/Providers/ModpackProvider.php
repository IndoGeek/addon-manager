<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;

interface ModpackProvider
{
    public function supports(string $source): bool;

    public function getMetadata(string $source): ModpackMetadata;

    public function getPackage(string $source): ModpackPackage;
}
