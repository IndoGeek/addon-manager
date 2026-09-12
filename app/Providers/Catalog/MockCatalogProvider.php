<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;

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

    /**
     * Mock versions keyed by project slug. version_id equals version_number so
     * the pinned source format (mock://slug@versionId) resolves deterministically.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const MODPACK_VERSIONS = [
        'example-pack' => [
            [
                'version_id' => '1.0.0',
                'version_number' => '1.0.0',
                'version_name' => 'Initial release',
                'game_versions' => ['1.21.1'],
                'loaders' => ['fabric'],
                'date_published' => '2024-01-01T00:00:00Z',
                'downloads' => 0,
            ],
            [
                'version_id' => '1.1.0',
                'version_number' => '1.1.0',
                'version_name' => 'Bugfix release',
                'game_versions' => ['1.21.1'],
                'loaders' => ['fabric'],
                'date_published' => '2024-02-01T00:00:00Z',
                'downloads' => 0,
            ],
        ],
        'vanilla-tweaks' => [
            [
                'version_id' => '2.3.0',
                'version_number' => '2.3.0',
                'version_name' => 'Refresh',
                'game_versions' => ['1.20.1'],
                'loaders' => ['fabric'],
                'date_published' => '2024-01-15T00:00:00Z',
                'downloads' => 0,
            ],
            [
                'version_id' => '2.4.0',
                'version_number' => '2.4.0',
                'version_name' => 'Latest refresh',
                'game_versions' => ['1.21.1', '1.20.1'],
                'loaders' => ['fabric'],
                'date_published' => '2024-03-01T00:00:00Z',
                'downloads' => 0,
            ],
        ],
        'barebones-progression' => [
            [
                'version_id' => '2.0.0',
                'version_number' => '2.0.0',
                'version_name' => 'Legacy',
                'game_versions' => ['1.19.2'],
                'loaders' => ['forge'],
                'date_published' => '2023-11-01T00:00:00Z',
                'downloads' => 0,
            ],
            [
                'version_id' => '3.0.0',
                'version_number' => '3.0.0',
                'version_name' => 'Progression rewrite',
                'game_versions' => ['1.19.2'],
                'loaders' => ['forge'],
                'date_published' => '2024-04-01T00:00:00Z',
                'downloads' => 0,
            ],
            [
                'version_id' => '3.1.0',
                'version_number' => '3.1.0',
                'version_name' => 'Balance pass',
                'game_versions' => ['1.19.2'],
                'loaders' => ['forge'],
                'date_published' => '2024-05-01T00:00:00Z',
                'downloads' => 0,
            ],
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
     * @return array<int, array<string, mixed>>
     */
    public function versions(CatalogVersionQuery $query): array
    {
        $modpack = $this->findModpack($query->project);

        if ($modpack === null) {
            return [];
        }

        $slug = (string) $modpack['slug'];

        $versions = array_filter(
            self::MODPACK_VERSIONS[$slug] ?? [],
            static fn (array $version): bool => static::matchesVersion(
                $version,
                $query,
            ),
        );

        return array_map(
            fn (array $version): CatalogVersion => new CatalogVersion(
                provider: $this->name(),
                projectId: (string) $modpack['provider_project_id'],
                projectSlug: $slug,
                projectName: (string) $modpack['name'],
                versionId: (string) $version['version_id'],
                versionNumber: (string) $version['version_number'],
                versionName: self::optionalString($version['version_name'] ?? null),
                gameVersions: self::stringList($version['game_versions'] ?? []),
                loaders: self::stringList($version['loaders'] ?? []),
                datePublished: self::optionalString($version['date_published'] ?? null),
                dateModified: null,
                downloads: self::optionalInt($version['downloads'] ?? null),
                source: 'mock://' . $slug . '@' . $version['version_id'],
            ),
            array_values($versions),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findModpack(string $project): ?array
    {
        foreach (self::MODPACKS as $modpack) {
            if (
                $modpack['slug'] === $project
                || $modpack['provider_project_id'] === $project
            ) {
                return $modpack;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $version
     */
    private static function matchesVersion(
        array $version,
        CatalogVersionQuery $query,
    ): bool {
        if (
            $query->gameVersion !== null
            && !in_array(
                $query->gameVersion,
                self::stringList($version['game_versions'] ?? []),
                true,
            )
        ) {
            return false;
        }

        if (
            $query->loader !== null
            && !in_array(
                $query->loader,
                self::stringList($version['loaders'] ?? []),
                true,
            )
        ) {
            return false;
        }

        return true;
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

    private static function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
