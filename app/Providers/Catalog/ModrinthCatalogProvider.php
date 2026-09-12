<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
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
 */
final class ModrinthCatalogProvider implements CatalogProvider
{
    private const API_BASE = 'https://api.modrinth.com/v2';

    private const SEARCH_PATH = '/search';

    private const MAX_UPSTREAM_LIMIT = 100;

    private const URL_SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

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

    public function unavailableReason(): ?string
    {
        return null;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchVersions(CatalogVersionQuery $query): array
    {
        $parameters = [];

        if ($query->gameVersion !== null) {
            $parameters['game_versions'] = json_encode([
                $query->gameVersion,
            ]);
        }

        if ($query->loader !== null) {
            $parameters['loaders'] = json_encode([$query->loader]);
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
        );
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
            'facets' => $this->facets($query),
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
     * Serializes the validated filters into Modrinth facet groups.
     */
    private function facets(CatalogSearchQuery $query): string
    {
        $facets = [
            ['project_type:modpack'],
        ];

        if ($query->gameVersion !== null) {
            $facets[] = [
                'versions:' . $query->gameVersion,
            ];
        }

        $categories = [];

        if ($query->loader !== null) {
            $categories[] = 'categories:' . $query->loader;
        }

        if ($query->category !== null) {
            $categories[] = 'categories:' . $query->category;
        }

        if ($categories !== []) {
            $facets[] = $categories;
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
            appliedGameVersion: $query->gameVersion,
            appliedLoader: $query->loader,
            appliedCategory: $query->category,
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
        );
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
