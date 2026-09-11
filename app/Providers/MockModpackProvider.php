<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;
use RuntimeException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;

final class MockModpackProvider implements ModpackProvider
{
    private const SOURCE = 'mock://example-pack';

    public function supports(string $source): bool
    {
        return $source === self::SOURCE;
    }

    public function getMetadata(string $source): ModpackMetadata
    {
        if (!$this->supports($source)) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        return new ModpackMetadata(
            id: 'example-pack',
            name: 'Example Modpack',
            version: '1.0.0',
            minecraftVersion: '1.21.1',
            loader: 'fabric',
            description: 'A mock modpack used for development and testing.',
            iconUrl: null,
            source: $source,
        );
    }

    public function getPackage(string $source): ModpackPackage
    {
        if (!$this->supports($source)) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $archivePath = dirname(__DIR__)
            . '/fixtures/example-pack.zip';

        if (!is_file($archivePath)) {
            throw new RuntimeException(
                'The mock modpack package is unavailable.',
            );
        }

        return new ModpackPackage(
            archivePath: $archivePath,
            source: $source,
        );
    }
}
