<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

/**
 * Optional capability for providers that can materialize a package holding
 * only a subset of the modpack's files. Restore flows use it to fetch just
 * the handful of missing files instead of re-acquiring the entire pack.
 */
interface PartialPackageProvider
{
    /**
     * Builds a package restricted to the given server-relative paths. The
     * returned package is otherwise identical to getPackage()'s output (same
     * workspace layout, same ownership semantics) except that only the
     * requested paths exist inside it — callers must not deploy anything
     * from it except those paths.
     *
     * A path the provider cannot source (no manifest entry, no usable
     * download) makes the whole call fail loudly: a restore must never
     * report success while silently skipping a requested file.
     *
     * @param list<string> $paths normalized server-relative paths
     */
    public function getPackageForPaths(
        string $source,
        array $paths,
    ): ModpackPackage;
}
