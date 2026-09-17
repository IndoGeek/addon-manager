<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;
use RuntimeException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;

final class MockModpackProvider implements ModpackProvider
{
    private const SOURCE = 'mock://example-pack';

    // @var array<string, true>
    private const VERSIONS = [
        '1.0.0' => true,
        '1.1.0' => true,
    ];

    public function supports(string $source): bool
    {
        return $this->parse($source) !== null;
    }

    // @return array{version: string|null}|null
    private function parse(string $source): ?array
    {
        $trimmed = trim($source);

        if ($trimmed === self::SOURCE) {
            return ['version' => null];
        }

        if (str_starts_with($trimmed, self::SOURCE . '@')) {
            $version = substr($trimmed, strlen(self::SOURCE) + 1);

            if (isset(self::VERSIONS[$version])) {
                return ['version' => $version];
            }
        }

        return null;
    }

    public function getMetadata(string $source): ModpackMetadata
    {
        $parsed = $this->parse($source);

        if ($parsed === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        return new ModpackMetadata(
            id: 'example-pack',
            name: 'Example Modpack',
            version: $parsed['version'] ?? '1.0.0',
            minecraftVersion: '1.21.1',
            loader: 'fabric',
            description: 'A mock modpack used for development and testing.',
            iconUrl: null,
            source: trim($source),
        );
    }

    public function getPackage(string $source): ModpackPackage
    {
        if ($this->parse($source) === null) {
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
            source: trim($source),
        );
    }

    public function cleanup(ModpackPackage $package): void
    {
        // The mock package is a repository fixture and must persist.
    }
}
