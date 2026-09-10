<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;

final class MockModpackProvider implements ModpackProvider
{
    public function supports(string $source): bool
    {
        return str_starts_with($source, 'mock://');
    }

    public function getMetadata(string $source): ModpackMetadata
    {
        if (!$this->supports($source)) {
            throw new \InvalidArgumentException(
                'Unsupported modpack source.'
            );
        }

        return new ModpackMetadata(
            id: 'example-pack',
            name: 'Example Modpack',
            version: '1.0.0',
            minecraftVersion: '1.21.1',
            loader: 'fabric',
            description: 'A test modpack used during development.',
            iconUrl: null,
            source: $source,
        );
    }
}
