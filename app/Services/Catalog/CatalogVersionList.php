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
     * @param array<string>              $appliedGameVersions
     * @param array<string>              $appliedLoaders
     */
    public function __construct(
        public string $provider,
        public array $appliedGameVersions = [],
        public array $appliedLoaders = [],
        public array $versions = [],
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
                'game_versions' => $this->appliedGameVersions,
                'loaders' => $this->appliedLoaders,
            ],
            'versions' => array_map(
                static fn (CatalogVersion $version): array => $version->toArray(),
                $this->versions,
            ),
        ];
    }

    /**
     * Rebuilds a version list from toArray() output (cache hydration).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $filters = is_array($data['filters'] ?? null)
            ? $data['filters']
            : [];

        $versions = [];

        if (is_array($data['versions'] ?? null)) {
            foreach ($data['versions'] as $version) {
                if (is_array($version)) {
                    $versions[] = CatalogVersion::fromArray($version);
                }
            }
        }

        return new self(
            (string) ($data['provider'] ?? ''),
            is_array($filters['game_versions'] ?? null)
                ? array_values($filters['game_versions'])
                : [],
            is_array($filters['loaders'] ?? null)
                ? array_values($filters['loaders'])
                : [],
            $versions,
        );
    }
}