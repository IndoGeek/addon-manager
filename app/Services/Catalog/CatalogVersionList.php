<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * Normalized catalog version response: the exact versions/releases of a
 * project plus the filters as applied and the active provider. Each entry
 * carries its own installable source (exact-version pin).
 */
final readonly class CatalogVersionList
{
    /**
     * @param array<int, CatalogVersion> $versions
     */
    public function __construct(
        public string $provider,
        public ?string $appliedGameVersion,
        public ?string $appliedLoader,
        public array $versions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'filters' => [
                'game_version' => $this->appliedGameVersion,
                'loader' => $this->appliedLoader,
            ],
            'versions' => array_map(
                static fn (CatalogVersion $version): array => $version->toArray(),
                $this->versions,
            ),
        ];
    }
}
