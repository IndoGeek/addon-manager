<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

/**
 * Real Modrinth catalog search. Only https://api.modrinth.com/v2 is used; all
 * client-supplied values are pre-validated slugs/versions embedded in query
 * facets, never in the request path, and every payload is type-checked before
 * it leaves this provider.
 *
 * Facet groups map 1:1 onto Modrinth semantics: every filter group becomes one
 * facet group (OR within a group, AND across groups). Multiple game versions,
 * loaders, categories and environments are therefore all genuine upstream
 * filters.
 */
final class ModrinthCatalogProvider implements CatalogProvider
{
    private const API_BASE = 'https://api.modrinth.com/v2';

    private const SEARCH_PATH = '/search';

    private const MAX_UPSTREAM_LIMIT = 100;

    private const URL_SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Curated common game versions offered as facet options. Modrinth accepts
     * any release version in its "versions" facet; this list is static
     * capability metadata, never a search result.
     *
     * @var array<string>
     */
    private const COMMON_GAME_VERSIONS = [
        '1.21.9',
        '1.21.8',
        '1.21.7',
        '1.21.6',
        '1.21.5',
        '1.21.4',
        '1.21.3',
        '1.21.2',
        '1.21.1',
        '1.21',
        '1.20.6',
        '1.20.4',
        '1.20.2',
        '1.20.1',
        '1.20',
        '1.19.4',
        '1.19.2',
        '1.18.2',
        '1.17.1',
        '1.16.5',
    ];

    /**
     * Modrinth categories that apply to modpacks. Loader and environment tags
     * are handled by their own facet groups, so categories are the content
     * tags only.
     *
     * @var array<string>
     */
    private const MODPACK_CATEGORIES = [
        'adventure',
        'combat',
        'creation',
        'decoration',
        'food',
        'magic',
        'optimization',
        'storage',
        'technology',
        'transportation',
        'utility',
        'misc',
    ];

    /**
     * @var array<string, true>
     */
    private const KNOWN_LOADERS = [
        'fabric' => true,
        'forge' => true,
        'quilt' => true,
        'neoforge' => true,
        'liteloader' => true,
        'rift' => true,
        'datapack' => true,
    ];

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {
    }

    public function name(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    public function available(): bool
    {
        return true;
    }

    public function state(): string
    {
        return 'available';
    }

    public function developmentOnly(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * @return array{query: bool, game_versions: bool, loaders: bool, categories: bool, environment: bool, sort: bool}
     */
    public function capabilities(): array
    {
        return [
            'query' => true,
            'game_versions' => true,
            'loaders' => true,
            'categories' => true,
            'environment' => true,
            'sort' => true,
        ];
    }

    /**
     * @return array{game_versions: array<int, string>, loaders: array<int, string>, categories: array<int, string>, environments: array<int, string>}
     */
    public function facets(): array
    {
        return [
            'game_versions' => self::COMMON_GAME_VERSIONS,
            'loaders' => array_keys(self::KNOWN_LOADERS),
            'categories' => self::MODPACK_CATEGORIES,
            'environments' => CatalogSearchQuery::ENVIRONMENT_VALUES,
        ];
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $payload = $this->fetch($query);

        return $this->mapPayload($query, $payload);
    }

    /**
     * @return array<int, CatalogVersion>
     */
    public function versions(CatalogVersionQuery $query): array
    {
        $payload = $this->fetchVersions($query);

        $versions = [];

        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if (!$this->isPublicVersion($entry)) {
                continue;
            }

            $versions[] = $this->mapVersion($query, $entry);
        }

        return $versions;
    }

    public function project(CatalogProjectQuery $query): CatalogItem
    {
        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/project/'
                    . rawurlencode($query->project),
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $this->mapProject($response->body);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchVersions(CatalogVersionQuery $query): array
    {
        $parameters = [];

        if ($query->gameVersions !== []) {
            $parameters['game_versions'] = json_encode($query->gameVersions);
        }

        if ($query->loaders !== []) {
            $parameters['loaders'] = json_encode($query->loaders);
        }

        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/project/'
                    . rawurlencode($query->project)
                    . '/version',
                query: $parameters,
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $response->body;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * A version is public unless the upstream explicitly marks it as a
     * non-public status (drafts, scheduled, or unlisted releases).
     *
     * @param array<string, mixed> $version
     */
    private function isPublicVersion(array $version): bool
    {
        $status = $this->stringOrNull($version['status'] ?? null);

        if ($status === null) {
            return true;
        }

        return !in_array(
            strtolower($status),
            ['draft', 'scheduled', 'unlisted', 'withheld'],
            true,
        );
    }

    /**
     * @param array<string, mixed> $version
     */
    private function mapVersion(
        CatalogVersionQuery $query,
        array $version,
    ): CatalogVersion {
        $versionId = $this->stringOrNull($version['id'] ?? null) ?? '';

        return new CatalogVersion(
            provider: $this->name(),
            projectId: $query->project,
            projectSlug: null,
            projectName: null,
            versionId: $versionId,
            versionNumber: $this->stringOrNull($version['version_number'] ?? null)
                ?? '',
            versionName: $this->stringOrNull($version['name'] ?? null),
            gameVersions: $this->stringList($version['game_versions'] ?? []),
            loaders: $this->stringList($version['loaders'] ?? []),
            datePublished: $this->stringOrNull($version['date_published'] ?? null),
            dateModified: $this->stringOrNull($version['date_modified'] ?? null),
            downloads: $this->intOrNull($version['downloads'] ?? null),
            source: $versionId === ''
                ? ''
                : 'modrinth://' . $query->project . '@' . $versionId,
            fileSize: $this->primaryFileSize($version),
        );
    }

    /**
     * Modrinth may advertise several files per version; the primary one is
     * what the installer downloads. Prefer it, then fall back to the first
     * file that actually reports a size.
     *
     * @param array<string, mixed> $version
     */
    private function primaryFileSize(array $version): ?int
    {
        $files = $version['files'] ?? null;

        if (!is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (($file['primary'] ?? false) === true) {
                return $this->intOrNull($file['size'] ?? null);
            }
        }

        foreach ($files as $file) {
            if (is_array($file) && isset($file['size'])) {
                return $this->intOrNull($file['size']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(CatalogSearchQuery $query): array
    {
        $limit = min(
            $query->limit,
            self::MAX_UPSTREAM_LIMIT,
        );

        $parameters = [
            'facets' => $this->buildFacets($query),
            'index' => $query->sort->value,
            'limit' => $limit,
            'offset' => $query->offset(),
        ];

        if ($query->query !== null) {
            $parameters['query'] = $query->query;
        }

        try {
            $response = $this->http->get(
                self::API_BASE . self::SEARCH_PATH,
                query: $parameters,
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $response->body;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * Serializes the validated filters into Modrinth facet groups. Each filter
     * group becomes its own facet group: values within a group are OR'ed by
     * Modrinth, groups are AND'ed together. Empty groups are omitted.
     */
    private function buildFacets(CatalogSearchQuery $query): string
    {
        $facets = [
            ['project_type:modpack'],
        ];

        if ($query->gameVersions !== []) {
            $facets[] = array_map(
                static fn (string $version): string =>
                    'versions:' . $version,
                $query->gameVersions,
            );
        }

        if ($query->loaders !== []) {
            $facets[] = array_map(
                static fn (string $loader): string =>
                    'categories:' . $loader,
                $query->loaders,
            );
        }

        if ($query->categories !== []) {
            $facets[] = array_map(
                static fn (string $category): string =>
                    'categories:' . $category,
                $query->categories,
            );
        }

        if ($query->environments !== []) {
            $facets[] = array_map(
                static fn (string $environment): string =>
                    'categories:' . $environment,
                $query->environments,
            );
        }

        return json_encode($facets);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapPayload(
        CatalogSearchQuery $query,
        array $payload,
    ): CatalogResult {
        $hits = $payload['hits'] ?? null;

        if (!is_array($hits)) {
            throw new CatalogProviderException(
                'The modpack catalog provider returned an invalid response.',
            );
        }

        $total = $this->totalHits($payload['total_hits'] ?? null);

        $items = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            $items[] = $this->mapHit($hit);
        }

        return new CatalogResult(
            items: $items,
            pagination: new CatalogPagination(
                $query->page,
                $query->limit,
                $total,
            ),
            provider: $this->name(),
            sort: $query->sort->value,
            appliedQuery: $query->query,
            appliedGameVersions: $query->gameVersions,
            appliedLoaders: $query->loaders,
            appliedCategories: $query->categories,
            appliedEnvironments: $query->environments,
        );
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function mapHit(array $hit): CatalogItem
    {
        $projectId = $this->stringOrNull($hit['project_id'] ?? null)
            ?? $this->stringOrNull($hit['id'] ?? null)
            ?? '';

        $slug = $this->validSlug($hit['slug'] ?? null);

        $categories = $this->stringList($hit['categories'] ?? []);

        $loaders = $this->stringList($hit['display_categories'] ?? []);

        if ($loaders === []) {
            $loaders = array_values(array_filter(
                $categories,
                static fn (string $category): bool =>
                    isset(self::KNOWN_LOADERS[$category]),
            ));
        }

        $tags = array_values(array_diff($categories, $loaders));

        $sourceId = $slug ?? ($this->validSlug($projectId) ?? '');

        return new CatalogItem(
            provider: $this->name(),
            providerProjectId: $projectId,
            slug: $slug,
            name: $this->stringOrNull($hit['title'] ?? null) ?? '',
            summary: $this->stringOrNull($hit['description'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->stringOrNull($hit['icon_url'] ?? null),
            ),
            projectUrl: $sourceId === ''
                ? null
                : 'https://modrinth.com/modpack/' . $sourceId,
            downloads: $this->intOrNull($hit['downloads'] ?? null),
            follows: $this->intOrNull($hit['follows'] ?? null),
            categories: $tags,
            gameVersions: $this->stringList($hit['versions'] ?? []),
            loaders: $loaders,
            latestVersion: $this->stringOrNull($hit['latest_version'] ?? null),
            source: 'modrinth://' . $sourceId,
            author: $this->stringOrNull($hit['author'] ?? null),
            updatedAt: $this->stringOrNull($hit['date_modified'] ?? null),
            bannerUrl: $this->galleryImage($hit),
            environment: $this->environmentFlags(
                $this->stringOrNull($hit['client_side'] ?? null),
                $this->stringOrNull($hit['server_side'] ?? null),
            ),
        );
    }

    /**
     * @param array<string, mixed> $project
     */
    private function mapProject(array $project): CatalogItem
    {
        $projectId = $this->stringOrNull($project['id'] ?? null) ?? '';

        $slug = $this->validSlug($project['slug'] ?? null);

        $categories = $this->stringList($project['categories'] ?? []);

        $loaders = $this->stringList($project['loaders'] ?? []);

        if ($loaders === []) {
            $loaders = array_values(array_filter(
                $categories,
                static fn (string $category): bool =>
                    isset(self::KNOWN_LOADERS[$category]),
            ));
        }

        $tags = array_values(array_diff($categories, $loaders));

        $sourceId = $slug ?? ($this->validSlug($projectId) ?? '');

        return new CatalogItem(
            provider: $this->name(),
            providerProjectId: $projectId,
            slug: $slug,
            name: $this->stringOrNull($project['title'] ?? null) ?? '',
            summary: $this->stringOrNull($project['description'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->stringOrNull($project['icon_url'] ?? null),
            ),
            projectUrl: $sourceId === ''
                ? null
                : 'https://modrinth.com/modpack/' . $sourceId,
            downloads: $this->intOrNull($project['downloads'] ?? null),
            follows: $this->intOrNull($project['followers'] ?? null),
            categories: $tags,
            gameVersions: $this->stringList($project['game_versions'] ?? []),
            loaders: $loaders,
            latestVersion: null,
            source: 'modrinth://' . $sourceId,
            author: $this->stringOrNull($project['author'] ?? null),
            updatedAt: $this->stringOrNull($project['updated'] ?? null),
            bannerUrl: $this->galleryImage($project),
            environment: $this->environmentFlags(
                $this->stringOrNull($project['client_side'] ?? null),
                $this->stringOrNull($project['server_side'] ?? null),
            ),
        );
    }

    /**
     * Best "banner" image available for a project: the featured gallery item
     * when present, otherwise the first gallery entry (Modrinth has no
     * dedicated banner image for modpacks).
     *
     * @param array<string, mixed> $data
     */
    private function galleryImage(array $data): ?string
    {
        foreach (['featured_gallery', 'gallery'] as $key) {
            $entries = $data[$key] ?? null;

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $url = $this->validImageUrl(
                    $this->stringOrNull($entry['url'] ?? null)
                        ?? $this->stringOrNull($entry['raw_url'] ?? null),
                );

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Derive a client/server environment label from the Modrinth side flags,
     * falling back to "client-and-server" when the flags are absent.
     */
    private function environmentFlags(?string $clientSide, ?string $serverSide): ?string
    {
        if ($clientSide === 'unsupported' && $serverSide === 'unsupported') {
            return null;
        }

        if ($serverSide === 'unsupported') {
            return 'client';
        }

        if ($clientSide === 'unsupported') {
            return 'server';
        }

        return 'client-and-server';
    }

    private function totalHits(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private function requestFailure(
        ProviderHttpException $exception,
    ): CatalogUnavailableException|CatalogProviderException {
        $status = $exception->status();

        if ($status === 429) {
            return new CatalogUnavailableException(
                'The modpack catalog provider is rate limited. Please try again later.',
            );
        }

        if ($status !== null && $status >= 500) {
            return new CatalogUnavailableException(
                'The modpack catalog provider is temporarily unavailable. Please try again later.',
            );
        }

        if ($status !== null && $status >= 400) {
            return new CatalogProviderException(
                'The modpack catalog provider rejected the request.',
            );
        }

        return new CatalogUnavailableException(
            'Unable to reach the modpack catalog provider. Please try again later.',
        );
    }

    private function validSlug(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        if (preg_match(self::URL_SLUG_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function validImageUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        return $url;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    fn (mixed $entry): string => $this->stringOrNull($entry) ?? '',
                    $value,
                ),
                static fn (string $entry): bool => $entry !== '',
            ),
        );
    }
}