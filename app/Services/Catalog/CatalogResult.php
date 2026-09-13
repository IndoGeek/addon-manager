<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * Normalized catalog search response: the items plus pagination metadata, the
 * provider that served the request, the filters as applied, and the sort used.
 */
final readonly class CatalogResult
{
    /**
     * @param array<int, CatalogItem>          $items
     * @param array<string>                    $appliedGameVersions
     * @param array<string>                    $appliedLoaders
     * @param array<string>                    $appliedCategories
     * @param array<string>                    $appliedEnvironments
     */
    public function __construct(
        public array $items,
        public CatalogPagination $pagination,
        public string $provider,
        public string $sort,
        public ?string $appliedQuery,
        public array $appliedGameVersions = [],
        public array $appliedLoaders = [],
        public array $appliedCategories = [],
        public array $appliedEnvironments = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (CatalogItem $item): array => $item->toArray(),
                $this->items,
            ),
            'pagination' => $this->pagination->toArray(),
            'provider' => $this->provider,
            'filters' => [
                'query' => $this->appliedQuery,
                'game_versions' => $this->appliedGameVersions,
                'loaders' => $this->appliedLoaders,
                'categories' => $this->appliedCategories,
                'environments' => $this->appliedEnvironments,
            ],
            'sort' => $this->sort,
        ];
    }
}