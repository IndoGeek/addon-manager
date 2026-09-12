<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

/**
 * Real CurseForge catalog search against the official api.curseforge.com API.
 * Only the constant API base plus validated, scalar query facets are used;
 * the API key travels exclusively in the X-Api-Key header and every payload is
 * type-checked before it leaves this provider.
 */
final class CurseForgeCatalogProvider implements CatalogProvider
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const API_KEY_HEADER = 'X-Api-Key';

    private const GAME_ID = 432;

    private const MODPACK_CLASS_ID = 4471;

    private const MAX_UPSTREAM_LIMIT = 50;

    private const MAX_SEARCH_INDEX = 10_000;

    private const MODPACK_URL_BASE = 'https://www.curseforge.com/minecraft/modpacks/';

    private const FILES_PAGE_SIZE = 50;

    private const MAX_FILES_PAGES = 20;

    private const URL_SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)*$/';

    /**
     * File statuses that are visible to the public.
     *
     * @var array<int, true>
     */
    private const PUBLIC_FILE_STATUSES = [
        4 => true, // Approved
        10 => true, // Released
    ];

    /**
     * Catalog loader slugs -> CurseForge ModLoaderType values.
     *
     * @var array<string, int>
     */
    private const LOADER_TO_MOD_LOADER = [
        'fabric' => 4,
        'forge' => 1,
        'liteloader' => 3,
        'neoforge' => 6,
        'quilt' => 5,
        'cauldron' => 2,
    ];

    /**
     * CurseForge ModLoaderType values -> catalog loader slugs.
     *
     * @var array<int, string>
     */
    private const MOD_LOADER_TO_SLUG = [
        1 => 'forge',
        2 => 'cauldron',
        3 => 'liteloader',
        4 => 'fabric',
        5 => 'quilt',
        6 => 'neoforge',
    ];

    /**
     * Loader display names that can appear inside a file's gameVersions array.
     *
     * @var array<string, string>
     */
    private const LOADER_DISPLAY_NAMES = [
        'Forge' => 'forge',
        'Fabric' => 'fabric',
        'Quilt' => 'quilt',
        'NeoForge' => 'neoforge',
        'LiteLoader' => 'liteloader',
        'Rift' => 'rift',
        'Cauldron' => 'cauldron',
    ];

    /**
     * Catalog sort values -> CurseForge ModSearchSortField values.
     *
     * @var array<string, int>
     */
    private const SORT_FIELDS = [
        CatalogSort::RELEVANCE->value => 1, // Featured
        CatalogSort::DOWNLOADS->value => 6, // TotalDownloads
        CatalogSort::FOLLOWS->value => 2, // Popularity (CurseForge has no follows)
        CatalogSort::NEWEST->value => 11, // Release Date
        CatalogSort::UPDATED->value => 3, // LastUpdated
    ];

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly ?string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    public function available(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function unavailableReason(): ?string
    {
        if ($this->available()) {
            return null;
        }

        return 'The CurseForge catalog is not configured. Set the CURSEFORGE_API_KEY server-side environment variable.';
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $this->assertConfigured();

        $index = $query->offset();

        if ($index + $query->limit > self::MAX_SEARCH_INDEX) {
            throw new InvalidArgumentException(
                'The requested page is out of range for the CurseForge catalog.',
            );
        }

        $parameters = [
            'gameId' => self::GAME_ID,
            'classId' => self::MODPACK_CLASS_ID,
            'index' => $index,
            'pageSize' => $query->limit,
            'sortField' => $this->sortField($query->sort),
            'sortOrder' => 'desc',
        ];

        if ($query->query !== null) {
            $parameters['searchFilter'] = $query->query;
        }

        if ($query->gameVersion !== null) {
            $parameters['gameVersion'] = $query->gameVersion;
        }

        if ($query->loader !== null) {
            $parameters['modLoaderType'] = $this->modLoader($query->loader);
        }

        $categoryId = null;

        if ($query->category !== null) {
            $categoryId = $this->resolveCategoryId($query->category);

            if ($categoryId === null) {
                return $this->emptyResult($query);
            }

            $parameters['categoryId'] = $categoryId;
        }

        return $this->mapSearchPayload($query, $this->requestSearch($parameters));
    }

    /**
     * @return array<int, CatalogVersion>
     */
    public function versions(CatalogVersionQuery $query): array
    {
        $this->assertConfigured();

        if (!ctype_digit($query->project)) {
            throw new InvalidArgumentException(
                'Invalid CurseForge project id.',
            );
        }

        $parameters = [
            'pageSize' => self::FILES_PAGE_SIZE,
        ];

        if ($query->gameVersion !== null) {
            $parameters['gameVersion'] = $query->gameVersion;
        }

        if ($query->loader !== null) {
            $parameters['modLoaderType'] = $this->modLoader($query->loader);
        }

        $files = $this->requestAllFiles($query->project, $parameters);

        $versions = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if (!$this->isPubliclyDownloadable($file)) {
                continue;
            }

            $fileId = (string) ($file['id'] ?? '');

            if ($fileId === '') {
                continue;
            }

            $versions[] = $this->mapVersion($query, $file);
        }

        usort($versions, static function (
            CatalogVersion $a,
            CatalogVersion $b,
        ): int {
            $aTime = strtotime((string) $a->datePublished);
            $bTime = strtotime((string) $b->datePublished);

            return $bTime <=> $aTime;
        });

        return $versions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requestSearch(array $parameters): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/search',
                query: $parameters,
                headers: $this->headers(),
            );

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
                || !is_array($response->body['pagination'] ?? null)
            ) {
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
     * @return array<int, array<string, mixed>>
     */
    private function requestAllFiles(
        string $project,
        array $parameters,
    ): array {
        $collected = [];
        $parameters['index'] = 0;

        for ($page = 0; $page < self::MAX_FILES_PAGES; $page++) {
            $parameters['index'] = $page * self::FILES_PAGE_SIZE;

            $payload = $this->requestFilesPage($project, $parameters);

            $entries = $payload['data'];

            foreach ($entries as $entry) {
                $collected[] = $entry;
            }

            if (count($entries) < self::FILES_PAGE_SIZE) {
                break;
            }
        }

        return $collected;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFilesPage(
        string $project,
        array $parameters,
    ): array {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $project . '/files',
                query: $parameters,
                headers: $this->headers(),
            );

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
            ) {
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
     * Resolves a catalog category slug to a CurseForge category id for the
     * modpacks class, or null when no category matches.
     */
    private function resolveCategoryId(string $slug): ?int
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/categories',
                query: [
                    'gameId' => self::GAME_ID,
                    'classId' => self::MODPACK_CLASS_ID,
                ],
                headers: $this->headers(),
            );

            $categories = is_array($response->body)
                ? ($response->body['data'] ?? null)
                : null;

            if (!is_array($categories)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            foreach ($categories as $category) {
                if (!is_array($category)) {
                    continue;
                }

                if (
                    strtolower((string) ($category['slug'] ?? '')) === $slug
                ) {
                    $id = $category['id'] ?? null;

                    if (is_int($id)) {
                        return $id;
                    }
                }
            }

            return null;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapSearchPayload(
        CatalogSearchQuery $query,
        array $payload,
    ): CatalogResult {
        $items = [];

        foreach ($payload['data'] as $mod) {
            if (!is_array($mod)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if (!$this->isModpackClass($mod)) {
                continue;
            }

            $items[] = $this->mapItem($mod);
        }

        $total = $this->intOrNull(
            $payload['pagination']['totalCount'] ?? null,
        ) ?? 0;

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

    private function emptyResult(CatalogSearchQuery $query): CatalogResult
    {
        return new CatalogResult(
            items: [],
            pagination: new CatalogPagination(
                $query->page,
                $query->limit,
                0,
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
     * @param array<string, mixed> $mod
     */
    private function mapItem(array $mod): CatalogItem
    {
        $id = (string) ($mod['id'] ?? '');

        $slug = $this->validSlug($mod['slug'] ?? null);

        $loaders = [];
        $gameVersions = [];

        foreach ($this->arrayOf($mod['latestFilesIndexes'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $modLoader = $this->intOrNull($entry['modLoader'] ?? null);
            $loaderSlug = $modLoader !== null
                ? ($this->MOD_LOADER_TO_SLUG[$modLoader] ?? null)
                : null;

            if ($loaderSlug !== null) {
                $loaders[$loaderSlug] = true;

                continue;
            }

            $gameVersion = $this->stringOrNull(
                $entry['gameVersion'] ?? null,
            );

            if (
                $gameVersion !== null
                && preg_match(self::VERSION_PATTERN, $gameVersion) === 1
            ) {
                $gameVersions[$gameVersion] = true;
            }
        }

        $categories = [];

        foreach ($this->arrayOf($mod['categories'] ?? []) as $category) {
            if (!is_array($category) || ($category['isClass'] ?? false) === true) {
                continue;
            }

            $slugValue = $this->validSlug($category['slug'] ?? null);

            if ($slugValue !== null) {
                $categories[$slugValue] = true;
            }
        }

        $logo = is_array($mod['logo'] ?? null)
            ? $mod['logo']
            : [];

        $latestVersion = null;

        foreach ($this->arrayOf($mod['latestFilesIndexes'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $filename = $this->stringOrNull($entry['filename'] ?? null);

            if ($filename !== null) {
                $latestVersion = $filename;

                break;
            }
        }

        $sourceId = $slug ?? $id;

        return new CatalogItem(
            provider: $this->name(),
            providerProjectId: $id,
            slug: $slug,
            name: $this->stringOrNull($mod['name'] ?? null) ?? '',
            summary: $this->stringOrNull($mod['summary'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->stringOrNull($logo['thumbnailUrl'] ?? null)
                    ?? $this->stringOrNull($logo['url'] ?? null),
            ),
            projectUrl: $sourceId === ''
                ? null
                : self::MODPACK_URL_BASE . $sourceId,
            downloads: $this->intOrNull($mod['downloadCount'] ?? null),
            follows: null,
            categories: array_keys($categories),
            gameVersions: array_keys($gameVersions),
            loaders: array_keys($loaders),
            latestVersion: $latestVersion,
            source: 'curseforge://' . $id,
        );
    }

    /**
     * @param array<string, mixed> $file
     */
    private function mapVersion(
        CatalogVersionQuery $query,
        array $file,
    ): CatalogVersion {
        $id = (string) ($file['id'] ?? '');

        [$gameVersions, $loaders] = $this->parseGameVersions(
            $file['gameVersions'] ?? [],
        );

        return new CatalogVersion(
            provider: $this->name(),
            projectId: $query->project,
            projectSlug: null,
            projectName: null,
            versionId: $id,
            versionNumber: $this->stringOrNull($file['displayName'] ?? null)
                ?? $this->stringOrNull($file['fileName'] ?? null)
                ?? $id,
            versionName: $this->stringOrNull($file['displayName'] ?? null),
            gameVersions: $gameVersions,
            loaders: $loaders,
            datePublished: $this->stringOrNull($file['fileDate'] ?? null),
            dateModified: null,
            downloads: $this->intOrNull($file['downloadCount'] ?? null),
            source: 'curseforge://' . $query->project . '@' . $id,
        );
    }

    /**
     * A file is installable when its status is public (or unspecified), it is
     * marked available, and it has a public download URL.
     *
     * @param array<string, mixed> $file
     */
    private function isPubliclyDownloadable(array $file): bool
    {
        $status = $this->intOrNull($file['fileStatus'] ?? null);

        if ($status !== null && !isset(self::PUBLIC_FILE_STATUSES[$status])) {
            return false;
        }

        if (($file['isAvailable'] ?? true) === false) {
            return false;
        }

        return $this->stringOrNull($file['downloadUrl'] ?? null) !== null;
    }

    /**
     * Whether a mod belongs to the Modpacks class. A mod without a class id is
     * tolerated (the search already filters by classId upstream), but any id
     * that is present must be the modpacks class.
     *
     * @param array<string, mixed> $mod
     */
    private function isModpackClass(array $mod): bool
    {
        $classId = $this->intOrNull($mod['classId'] ?? null);

        if ($classId === null) {
            return true;
        }

        return $classId === self::MODPACK_CLASS_ID;
    }

    private function sortField(CatalogSort $sort): int
    {
        return self::SORT_FIELDS[$sort->value] ?? 1;
    }

    private function modLoader(string $loader): int
    {
        $value = self::LOADER_TO_MOD_LOADER[$loader] ?? null;

        if ($value === null) {
            throw new InvalidArgumentException(
                'The selected loader is not supported by the CurseForge catalog.',
            );
        }

        return $value;
    }

    /**
     * @param array<mixed> $gameVersions
     *
     * @return array{0: array<string>, 1: array<string>} [minecraft versions, loader slugs]
     */
    private function parseGameVersions(array $gameVersions): array
    {
        $versions = [];
        $loaders = [];

        foreach ($gameVersions as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            if (isset(self::LOADER_DISPLAY_NAMES[$entry])) {
                $loaders[self::LOADER_DISPLAY_NAMES[$entry]] = true;

                continue;
            }

            if (preg_match(self::VERSION_PATTERN, $entry) === 1) {
                $versions[$entry] = true;
            }
        }

        return [array_keys($versions), array_keys($loaders)];
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            self::API_KEY_HEADER . ': ' . $this->apiKey,
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->available()) {
            throw new CatalogUnavailableException(
                'The CurseForge catalog is not configured. Set the CURSEFORGE_API_KEY server-side environment variable.',
            );
        }
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

        if (($parts['host'] ?? '') === '') {
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
     * @return array<int, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}