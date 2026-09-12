<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;

/**
 * Deterministic in-memory catalog used for development and the manual test
 * suite. Results are synthetic and clearly labeled; the UI never presents
 * them as live upstream data.
 */
final class MockCatalogProvider implements CatalogProvider
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const MODPACKS = [
        [
            'provider_project_id' => 'example-pack',
            'slug' => 'example-pack',
            'name' => 'Example Modpack',
            'summary' => 'A mock modpack used for development and testing.',
            'latest_version' => '1.0.0',
            'categories' => ['adventure'],
            'loaders' => ['fabric'],
            'game_versions' => ['1.21.1'],
        ],
        [
            'provider_project_id' => 'vanilla-tweaks',
            'slug' => 'vanilla-tweaks',
            'name' => 'Vanilla Tweaks Pack',
            'summary' => 'Small quality-of-life tweaks without changing the game feel.',
            'latest_version' => '2.4.0',
            'categories' => ['utility'],
            'loaders' => ['fabric'],
            'game_versions' => ['1.21.1', '1.20.1'],
        ],
        [
            'provider_project_id' => 'barebones-progression',
            'slug' => 'barebones-progression',
            'name' => 'Barebones Progression',
            'summary' => 'A minimal progression-focused modpack for older worlds.',
            'latest_version' => '3.1.0',
            'categories' => ['adventure', 'technology'],
            'loaders' => ['forge'],
            'game_versions' => ['1.19.2'],
        ],
    ];

    public function name(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Mock (development)';
    }

    public function available(): bool
    {
        return true;
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $items = array_filter(
            self::MODPACKS,
            static fn (array $modpack): bool => self::matches($modpack, $query),
        );

        $total = count($items);

        $pageItems = array_slice(
            array_values($items),
            $query->offset(),
            $query->limit,
        );

        $catalogItems = array_map(
            fn (array $modpack): CatalogItem => new CatalogItem(
                provider: $this->name(),
                providerProjectId: (string) $modpack['provider_project_id'],
                slug: self::optionalString($modpack['slug'] ?? null),
                name: (string) $modpack['name'],
                summary: self::optionalString($modpack['summary'] ?? null),
                iconUrl: null,
                projectUrl: null,
                downloads: null,
                follows: null,
                categories: self::stringList($modpack['categories'] ?? []),
                gameVersions: self::stringList($modpack['game_versions'] ?? []),
                loaders: self::stringList($modpack['loaders'] ?? []),
                latestVersion: self::optionalString($modpack['latest_version'] ?? null),
                source: 'mock://' . $modpack['slug'],
            ),
            $pageItems,
        );

        return new CatalogResult(
            items: $catalogItems,
            pagination: new CatalogPagination(
                $query->page,
                $query->limit,
                $total,
            ),
            provider: $this->name(),
            sort: $query->sort->value,
            appliedQuery: $query->query,
            appliedGameVersion: $query->gameVersion,
            appliedLoader: $query->loader,
            appliedCategory: $query->category,
        );
    }

    /**
     * @param array<string, mixed> $modpack
     */
    private static function matches(
        array $modpack,
        CatalogSearchQuery $query,
    ): bool {
        if (
            $query->query !== null
            && stripos(
                (string) $modpack['name'] . ' '
                . (string) $modpack['slug'] . ' '
                . (string) ($modpack['summary'] ?? ''),
                $query->query,
            ) === false
        ) {
            return false;
        }

        if (
            $query->gameVersion !== null
            && !in_array(
                $query->gameVersion,
                self::stringList($modpack['game_versions'] ?? []),
                true,
            )
        ) {
            return false;
        }

        if (
            $query->loader !== null
            && !in_array(
                $query->loader,
                self::stringList($modpack['loaders'] ?? []),
                true,
            )
        ) {
            return false;
        }

        if (
            $query->category !== null
            && !in_array(
                $query->category,
                self::stringList($modpack['categories'] ?? []),
                true,
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (mixed $entry): string => is_string($entry)
                        ? $entry
                        : '',
                    $value,
                ),
                static fn (string $entry): bool => $entry !== '',
            ),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}