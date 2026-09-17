<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

// Optional capability for providers that can materialize a package holding only a subset of the modpack's files.
interface PartialPackageProvider
{
    // Builds a package restricted to the given server-relative paths.
    public function getPackageForPaths(
        string $source,
        array $paths,
    ): ModpackPackage;
}
