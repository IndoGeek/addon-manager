<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models;

final readonly class ModpackMetadata
{
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $minecraftVersion,
        public ?string $loader,
        public ?string $description,
        public ?string $iconUrl,
        public string $source,
    ) {}
}
