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
        public ?int $upstreamTotal = null,
        public ?int $filteredTotal = null,
        public array $diagnostics = [],
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
            'upstream_total' => $this->upstreamTotal,
            'filtered_total' => $this->filteredTotal,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * Rebuilds a result from toArray() output (cache hydration).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $filters = is_array($data['filters'] ?? null)
            ? $data['filters']
            : [];

        $items = [];

        if (is_array($data['items'] ?? null)) {
            foreach ($data['items'] as $item) {
                if (is_array($item)) {
                    $items[] = CatalogItem::fromArray($item);
                }
            }
        }

        $intOrNull = static function ($value): ?int {
            return is_int($value) ? $value : null;
        };

        $listOrEmpty = static function ($value): array {
            return is_array($value) ? array_values($value) : [];
        };

        return new self(
            $items,
            isset($data['pagination']) && is_array($data['pagination'])
                ? CatalogPagination::fromArray($data['pagination'])
                : new CatalogPagination(1, 1, 0),
            (string) ($data['provider'] ?? ''),
            (string) ($data['sort'] ?? ''),
            isset($filters['query']) && is_string($filters['query'])
                ? $filters['query']
                : null,
            $listOrEmpty($filters['game_versions'] ?? null),
            $listOrEmpty($filters['loaders'] ?? null),
            $listOrEmpty($filters['categories'] ?? null),
            $listOrEmpty($filters['environments'] ?? null),
            $intOrNull($data['upstream_total'] ?? null),
            $intOrNull($data['filtered_total'] ?? null),
            is_array($data['diagnostics'] ?? null)
                ? $data['diagnostics']
                : [],
        );
    }
}