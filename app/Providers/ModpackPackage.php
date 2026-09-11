<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

final class ModpackPackage
{
    public function __construct(
        public readonly string $archivePath,
        public readonly string $source,
    ) {
    }
}
