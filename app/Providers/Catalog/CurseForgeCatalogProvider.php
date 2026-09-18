<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogDescription;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\DescriptionSanitizer;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Throwable;

// Real CurseForge catalog search against the official api.curseforge.com API.
final class CurseForgeCatalogProvider implements CatalogProvider
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const API_KEY_HEADER = 'X-Api-Key';

    private const GAME_ID = 432;

    private const MODPACK_CLASS_ID = 4471;

    // The Mods class.
    private const MOD_CLASS_ID = 6;

    private const PLUGIN_CLASS_ID = 5;

    private const RESOURCE_PACK_CLASS_ID = 12;

    private const DATAPACK_CLASS_ID = 6945;

    private const SHADER_CLASS_ID = 6552;

    // The CurseForge class behind each content type we install, verified against GET /v1/categories?gameId=432 (cla...
    private const CLASS_BY_CONTENT_TYPE = [
        'modpack' => self::MODPACK_CLASS_ID,
        'mod' => self::MOD_CLASS_ID,
        'plugin' => self::PLUGIN_CLASS_ID,
        'resourcepack' => self::RESOURCE_PACK_CLASS_ID,
        'datapack' => self::DATAPACK_CLASS_ID,
        'shader' => self::SHADER_CLASS_ID,
    ];

    // Content types whose CurseForge files carry a mod loader.
    private const TYPES_WITH_LOADERS = ['modpack', 'mod'];

    // Project page prefix per class, for the "open on CurseForge" link.
    private const URL_BASE_BY_CLASS_ID = [
        self::MODPACK_CLASS_ID => self::MODPACK_URL_BASE,
        self::MOD_CLASS_ID => self::MOD_URL_BASE,
        self::PLUGIN_CLASS_ID => 'https://www.curseforge.com/minecraft/bukkit-plugins/',
        self::RESOURCE_PACK_CLASS_ID => 'https://www.curseforge.com/minecraft/texture-packs/',
        self::DATAPACK_CLASS_ID => 'https://www.curseforge.com/minecraft/data-packs/',
        self::SHADER_CLASS_ID => 'https://www.curseforge.com/minecraft/shaders/',
    ];

    private const MAX_UPSTREAM_LIMIT = 50;

    private const MAX_SEARCH_INDEX = 10_000;

    // Upstream search blocks are always requested at this page size and then sliced locally, so a single request fills a full...
    private const SCAN_PAGE_SIZE = 50;

    // Upper bound on the number of upstream search blocks scanned to satisfy a single request.
    private const MAX_SCAN_BLOCKS = 40;

    // Project classification buckets exposed through diagnostics.
    private const CLASS_SERVER_PACK = 'server_pack';

    private const CLASS_MAIN_ARCHIVE = 'main_archive';

    private const CLASS_UNKNOWN = 'unknown';

    private const CLASS_CLIENT_ONLY = 'client_only';

    private const MODPACK_URL_BASE = 'https://www.curseforge.com/minecraft/modpacks/';

    private const MOD_URL_BASE = 'https://www.curseforge.com/minecraft/mc-mods/';

    private const FILES_PAGE_SIZE = 50;

    private const MAX_FILES_PAGES = 20;

    private const URL_SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)*$/';

    // Curated common game versions offered as facet options.
    private const COMMON_GAME_VERSIONS = [
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

    // CurseForge modpack categories — the REAL slugs returned by GET /v1/categories?gameId=432&classId=4471 (the Mo...
    private const MODPACK_CATEGORIES = [
        'adventure-and-rpg',
        'combat-pvp',
        'expert',
        'exploration',
        'ftb-official-pack',
        'hardcore',
        'horror',
        'magic',
        'map-based',
        'mini-game',
        'multiplayer',
        'quests',
        'rlcraft',
        'sci-fi',
        'skyblock',
        'small-light',
        'tech',
        'vanilla',
        'extra-large',
    ];

    // Fallback Mods-class (6) category slugs, used only when the live category list cannot be loaded (no API key, n...
    private const MOD_CATEGORIES = [
        'adventure-and-rpg',
        'api-and-library',
        'armor-tools-and-weapons',
        'cosmetic',
        'education',
        'food',
        'magic',
        'map-and-information',
        'miscellaneous',
        'optimization',
        'redstone',
        'server-utility',
        'storage',
        'technology',
        'transportation',
        'world-gen',
    ];

    // Fallback category slugs per class, used only when the live category list cannot be loaded (no API key, networ...
    private const PLUGIN_CATEGORIES = [
        'admin-tools',
        'anti-griefing-tools',
        'chat-related',
        'developer-tools',
        'economy',
        'fixes',
        'fun',
        'general',
        'informational',
        'mechanics',
        'miscellaneous',
        'role-playing',
        'teleportation',
        'twitch-integration',
        'website-administration',
        'world-editing-and-management',
        'world-generators',
    ];

    private const RESOURCE_PACK_CATEGORIES = [
        'animated',
        'data-packs',
        'five-twelve-x-and-beyond',
        'font-packs',
        'medieval',
        'miscellaneous',
        'mod-support',
        'modern',
        'modjam-2025',
        'one-twenty-eight-x',
        'photo-realistic',
        'sixteen-x',
        'sixty-four-x',
        'steampunk',
        'thirty-two-x',
        'traditional',
        'two-fifty-six-x',
    ];

    private const DATAPACK_CATEGORIES = [
        'adventure',
        'fantasy',
        'library',
        'magic',
        'miscellaneous',
        'mod-support',
        'modjam-2025',
        'tech',
        'utility',
    ];

    private const SHADER_CATEGORIES = [
        'fantasy',
        'realistic',
        'vanilla',
    ];

    // File statuses that are visible to the public.
    private const PUBLIC_FILE_STATUSES = [
        4 => true, // Approved
        10 => true, // Released
    ];

    // Catalog loader slugs -> CurseForge ModLoaderType values.
    private const LOADER_TO_MOD_LOADER = [
        'fabric' => 4,
        'forge' => 1,
        'liteloader' => 3,
        'neoforge' => 6,
        'quilt' => 5,
        'cauldron' => 2,
    ];

    // CurseForge ModLoaderType values -> catalog loader slugs.
    private const MOD_LOADER_TO_SLUG = [
        1 => 'forge',
        2 => 'cauldron',
        3 => 'liteloader',
        4 => 'fabric',
        5 => 'quilt',
        6 => 'neoforge',
    ];

    // Loader display names that can appear inside a file's gameVersions array.
    private const LOADER_DISPLAY_NAMES = [
        'Forge' => 'forge',
        'Fabric' => 'fabric',
        'Quilt' => 'quilt',
        'NeoForge' => 'neoforge',
        'LiteLoader' => 'liteloader',
        'Rift' => 'rift',
        'Cauldron' => 'cauldron',
    ];

    // Catalog sort values -> CurseForge ModSearchSortField values.
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

    public function state(): string
    {
        return $this->available() ? 'available' : 'not_configured';
    }

    public function developmentOnly(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        if ($this->available()) {
            return null;
        }

        return 'CurseForge is not available. Please add your API key to the .env file.';
    }

    // @return array{query: bool, game_versions: bool, loaders: bool, categories: bool, environment: bool, sort: bool}
    public function capabilities(): array
    {
        return [
            'query' => true,
            'game_versions' => true,
            'loaders' => true,
            'categories' => true,
            'environment' => false,
            'sort' => true,
            // CurseForge serves modpacks plus the four single-file classes and mods; the UI enables exactly the tabs listed...
            'content_types' => array_keys(self::CLASS_BY_CONTENT_TYPE),
        ];
    }

    // The CurseForge class behind a content type, or null when this provider does not serve that type at all.
    private static function classIdFor(string $contentType): ?int
    {
        return self::CLASS_BY_CONTENT_TYPE[$contentType] ?? null;
    }

    // @return array<int, string>
    private static function fallbackCategories(string $contentType): array
    {
        return match ($contentType) {
            'modpack' => self::MODPACK_CATEGORIES,
            'mod' => self::MOD_CATEGORIES,
            'plugin' => self::PLUGIN_CATEGORIES,
            'resourcepack' => self::RESOURCE_PACK_CATEGORIES,
            'datapack' => self::DATAPACK_CATEGORIES,
            'shader' => self::SHADER_CATEGORIES,
            default => [],
        };
    }

    // @return array{game_versions: array<int, string>, loaders: array<int, string>, categories: array<int, string>...
    public function facets(string $contentType = 'modpack'): array
    {
        $classId = self::classIdFor($contentType);

        if ($classId === null) {
            return [
                'game_versions' => [],
                'loaders' => [],
                'categories' => [],
                'environments' => [],
            ];
        }

        // Every type is a different CurseForge class with its own category set, so the facet list follows the active ta...
        return [
            'game_versions' => self::COMMON_GAME_VERSIONS,
            'loaders' => in_array($contentType, self::TYPES_WITH_LOADERS, true)
                ? array_keys(self::LOADER_TO_MOD_LOADER)
                : [],
            'categories' => $this->categoriesForClass(
                $classId,
                self::fallbackCategories($contentType),
            ),
            'environments' => [],
        ];
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $this->assertConfigured();

        $classId = self::classIdFor($query->contentType);

        // A content type CurseForge has no class for (there is no Sponge class, for example) shows an empty result inst...
        if ($classId === null) {
            return $this->emptyResult($query);
        }

        // Single-file content is directly installable, so it takes the plain upstream pagination instead of the modpack...
        if ($query->contentType !== 'modpack') {
            return $this->searchContent($query, $classId);
        }

        if ($query->environments !== []) {
            return $this->emptyResult($query);
        }

        if ($query->offset() > self::MAX_SEARCH_INDEX) {
            throw new InvalidArgumentException(
                'The requested page is out of range for the CurseForge catalog.',
            );
        }

        $parameters = [
            'gameId' => self::GAME_ID,
            'classId' => self::MODPACK_CLASS_ID,
            'sortField' => $this->sortField($query->sort),
            'sortOrder' => 'desc',
        ];

        if ($query->query !== null) {
            $parameters['searchFilter'] = $query->query;
        }

        if ($query->gameVersions !== []) {
            $parameters['gameVersion'] = $query->gameVersions[0];
        }

        if ($query->loaders !== []) {
            $parameters['modLoaderType'] = $this->modLoader($query->loaders[0]);
        }

        $categoryId = null;

        if ($query->categories !== []) {
            $categoryId = $this->resolveCategoryId(
                $query->categories[0],
                self::MODPACK_CLASS_ID,
            );

            if ($categoryId === null) {
                return $this->emptyResult($query);
            }

            $parameters['categoryId'] = $categoryId;
        }

        $needsPostFilter = count($query->gameVersions) > 1
            || count($query->loaders) > 1
            || count($query->categories) > 1;

        $accepted = [];
        $inspected = 0;
        $excluded = 0;
        $exclusionReasons = [];
        $classCounts = [
            self::CLASS_SERVER_PACK => 0,
            self::CLASS_MAIN_ARCHIVE => 0,
            self::CLASS_UNKNOWN => 0,
            self::CLASS_CLIENT_ONLY => 0,
        ];
        $sourcePages = 0;
        $upstreamTotal = null;
        $upstreamCount = 0;
        $scanIndex = 0;
        $exhausted = false;

        $needed = $query->offset() + $query->limit;

        while (count($accepted) < $needed) {
            if (
                $sourcePages >= self::MAX_SCAN_BLOCKS
                || $scanIndex > self::MAX_SEARCH_INDEX
            ) {
                break;
            }

            $block = $parameters;
            $block['index'] = $scanIndex;
            $block['pageSize'] = self::SCAN_PAGE_SIZE;

            $payload = $this->requestSearch($block);

            $sourcePages++;

            $total = $this->intOrNull(
                $payload['pagination']['totalCount'] ?? null,
            );

            if ($upstreamTotal === null && $total !== null) {
                $upstreamTotal = $total;
            }

            $entries = $payload['data'];

            $upstreamCount += count($entries);

            $items = $this->mapSearchItems($entries, self::MODPACK_CLASS_ID);

            if ($needsPostFilter) {
                $items = $this->postFilterItems($query, $items);
            }

            $inspected += count($items);

            [$batch, $batchExcluded, $batchReasons, $batchClasses]
                = $this->classifyItems($items);

            $excluded += $batchExcluded;

            foreach ($batchReasons as $reason => $count) {
                $exclusionReasons[$reason] = ($exclusionReasons[$reason] ?? 0)
                    + $count;
            }

            foreach ($batchClasses as $class => $count) {
                $classCounts[$class] = ($classCounts[$class] ?? 0) + $count;
            }

            foreach ($batch as $item) {
                $accepted[] = $item;
            }

            if (count($entries) < self::SCAN_PAGE_SIZE) {
                $exhausted = true;

                break;
            }

            $scanIndex += self::SCAN_PAGE_SIZE;
        }

        $acceptedCount = count($accepted);

        $filteredTotal = $exhausted ? $acceptedCount : null;

        $total = $exhausted
            ? $acceptedCount
            : ($upstreamTotal ?? 0);

        $moreSourcePages = $sourcePages > 1;

        $diagnostics = [
            'original_api_result_count' => $upstreamCount,
            'inspected' => $inspected,
            'accepted' => $acceptedCount,
            'excluded' => $excluded,
            'exclusion_reasons' => $exclusionReasons,
            'classification' => $classCounts,
            'upstream_total' => $upstreamTotal,
            'source_pages' => $sourcePages,
            'more_source_pages' => $moreSourcePages,
            'page_filled' => $acceptedCount >= $needed,
            'scan_exhausted' => $exhausted,
        ];

        $this->logDiagnostics($query, $diagnostics);

        $pageItems = array_slice(
            $accepted,
            $query->offset(),
            $query->limit,
        );

        return new CatalogResult(
            items: $pageItems,
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
            upstreamTotal: $upstreamTotal,
            filteredTotal: $filteredTotal,
            diagnostics: $diagnostics,
        );
    }

    // Single-file search (mods, plugins, resource packs, data packs, shaders): a straight upstream page of the type...
    private function searchContent(
        CatalogSearchQuery $query,
        int $classId,
    ): CatalogResult {
        if ($query->environments !== []) {
            return $this->emptyResult($query);
        }

        if ($query->offset() > self::MAX_SEARCH_INDEX) {
            throw new InvalidArgumentException(
                'The requested page is out of range for the CurseForge catalog.',
            );
        }

        $parameters = [
            'gameId' => self::GAME_ID,
            'classId' => $classId,
            'sortField' => $this->sortField($query->sort),
            'sortOrder' => 'desc',
        ];

        if ($query->query !== null) {
            $parameters['searchFilter'] = $query->query;
        }

        if ($query->gameVersions !== []) {
            $parameters['gameVersion'] = $query->gameVersions[0];
        }

        // Only the classes that record a loader accept the upstream filter; sending it for a plugin search would narrow...
        if (
            $query->loaders !== []
            && in_array(
                $query->contentType,
                self::TYPES_WITH_LOADERS,
                true,
            )
        ) {
            $parameters['modLoaderType'] = $this->modLoader($query->loaders[0]);
        }

        if ($query->categories !== []) {
            $categoryId = $this->resolveCategoryId(
                $query->categories[0],
                $classId,
            );

            if ($categoryId === null) {
                return $this->emptyResult($query);
            }

            $parameters['categoryId'] = $categoryId;
        }

        $parameters['index'] = $query->offset();
        $parameters['pageSize'] = min(
            self::MAX_UPSTREAM_LIMIT,
            $query->limit,
        );

        $payload = $this->requestSearch($parameters);

        $items = array_values(array_filter(
            $this->mapSearchItems($payload['data'], $classId),
            static fn (CatalogItem $item): bool =>
                self::matchesMultiFilters($query, $item),
        ));

        $upstreamTotal = $this->intOrNull(
            $payload['pagination']['totalCount'] ?? null,
        );

        return new CatalogResult(
            items: $items,
            pagination: new CatalogPagination(
                $query->page,
                $query->limit,
                $upstreamTotal ?? count($items),
            ),
            provider: $this->name(),
            sort: $query->sort->value,
            appliedQuery: $query->query,
            appliedGameVersions: $query->gameVersions,
            appliedLoaders: $query->loaders,
            appliedCategories: $query->categories,
            appliedEnvironments: $query->environments,
            upstreamTotal: $upstreamTotal,
            filteredTotal: count($items),
            diagnostics: [
                'content_type' => $query->contentType,
                'class_id' => $classId,
                'upstream_total' => $upstreamTotal,
                'returned' => count($items),
            ],
        );
    }

    // The category slugs CurseForge actually exposes for a class.
    private function categoriesForClass(int $classId, array $fallback): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/categories',
                query: [
                    'gameId' => self::GAME_ID,
                    'classId' => $classId,
                ],
                headers: $this->headers(),
            );
        } catch (Throwable) {
            return $fallback;
        }

        $categories = is_array($response->body)
            ? ($response->body['data'] ?? null)
            : null;

        if (!is_array($categories)) {
            return $fallback;
        }

        $slugs = [];

        foreach ($categories as $category) {
            if (
                !is_array($category)
                || ($category['isClass'] ?? false) === true
            ) {
                continue;
            }

            $slug = $this->validSlug($category['slug'] ?? null);

            if ($slug !== null) {
                $slugs[$slug] = true;
            }
        }

        return $slugs === [] ? $fallback : array_keys($slugs);
    }

    // @return array<int, CatalogVersion>
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

        if ($query->gameVersions !== []) {
            $parameters['gameVersion'] = $query->gameVersions[0];
        }

        if ($query->loaders !== []) {
            $parameters['modLoaderType'] = $this->modLoader($query->loaders[0]);
        }

        $files = $this->requestAllFiles($query->project, $parameters);

        // Resolve every referenced dedicated server pack in ONE bulk call instead of one sequential HTTP round-trip per...
        $serverPacks = $this->bulkResolveServerPacks($files, $query->project);

        $versions = [];
        $seenFileIds = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if (!$this->isPubliclyDownloadable($file)) {
                continue;
            }

            $installFile = $this->resolveInstallableFile(
                $file,
                $query->project,
                $serverPacks,
            );

            if ($installFile === null) {
                continue;
            }

            $fileId = (string) ($installFile['id'] ?? '');

            if ($fileId === '' || isset($seenFileIds[$fileId])) {
                continue;
            }

            $seenFileIds[$fileId] = true;

            $versions[] = $this->mapVersion($query, $installFile);
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

    public function project(CatalogProjectQuery $query): CatalogItem
    {
        $this->assertConfigured();

        if (!ctype_digit($query->project)) {
            throw new InvalidArgumentException(
                'Invalid CurseForge project id.',
            );
        }

        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $query->project,
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

            // The details modal opens for both classes; anything else is not something this provider installs.
            if (!$this->isCatalogClass($response->body['data'])) {
                throw new CatalogProviderException(
                    'The requested project is not a modpack or a mod.',
                );
            }

            return $this->mapItem($response->body['data']);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    public function description(CatalogProjectQuery $query): CatalogDescription
    {
        $this->assertConfigured();

        if (!ctype_digit($query->project)) {
            throw new InvalidArgumentException(
                'Invalid CurseForge project id.',
            );
        }

        try {
            // The project payload's `description` field is usually empty; the long-form body lives on its dedicated endpoin...
            $response = $this->http->get(
                self::API_BASE
                    . '/mods/'
                    . $query->project
                    . '/description',
                headers: $this->headers(),
            );

            $raw = is_array($response->body)
                && is_string($response->body['data'] ?? null)
                ? $response->body['data']
                : '';

            if (trim($raw) === '') {
                return new CatalogDescription(
                    provider: $this->name(),
                    project: $query->project,
                    html: '',
                );
            }

            // CurseForge serves the description as raw HTML (wrapped in its own page markup).
            $html = (new DescriptionSanitizer())->sanitize($raw);

            return new CatalogDescription(
                provider: $this->name(),
                project: $query->project,
                html: $html,
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // Maps raw search entries, keeping only the class being searched: the modpack scan must never accept another cl...
    private function mapSearchItems(array $entries, int $classId): array
    {
        $items = [];

        foreach ($entries as $mod) {
            if (!is_array($mod)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            // An absent classId is tolerated: the upstream request already filtered on classId, so a payload without one is...
            $entryClassId = $this->intOrNull($mod['classId'] ?? null);

            if ($entryClassId !== null && $entryClassId !== $classId) {
                continue;
            }

            $items[] = $this->mapItem($mod);
        }

        return $items;
    }

    // Keeps only the items that satisfy every multi-value group that could not be expressed upstream (any match within a...
    private function postFilterItems(
        CatalogSearchQuery $query,
        array $items,
    ): array {
        return array_values(array_filter(
            $items,
            static fn (CatalogItem $item): bool => self::matchesMultiFilters(
                $query,
                $item,
            ),
        ));
    }
// Classifies a set of mapped items against their resolved file data.
    private function classifyItems(array $items): array
    {
        if ($items === []) {
            return [[], 0, [], []];
        }

        $ids = [];

        foreach ($items as $item) {
            if (ctype_digit($item->providerProjectId)) {
                $ids[] = (int) $item->providerProjectId;
            }
        }

        if ($ids === []) {
            return [[], 0, [], []];
        }

        $projects = $this->fetchProjects($ids);

        $accepted = [];
        $excluded = 0;
        $reasons = [];
        $classCounts = [
            self::CLASS_SERVER_PACK => 0,
            self::CLASS_MAIN_ARCHIVE => 0,
            self::CLASS_UNKNOWN => 0,
            self::CLASS_CLIENT_ONLY => 0,
        ];

        foreach ($items as $item) {
            $project = $projects[$item->providerProjectId] ?? null;

            $class = $this->classifyProject($project);

            $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;

            if ($class === self::CLASS_CLIENT_ONLY) {
                $excluded++;

                $reasons['no_server_or_public_archive']
                    = ($reasons['no_server_or_public_archive'] ?? 0) + 1;

                continue;
            }

            $accepted[] = $item;
        }

        return [$accepted, $excluded, $reasons, $classCounts];
    }

    // Fetches the given projects through the bulk /mods endpoint, keyed by numeric string id.
    private function fetchProjects(array $ids): array
    {
        try {
            $response = $this->http->post(
                self::API_BASE . '/mods',
                body: ['modIds' => $ids],
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

            $projects = [];

            foreach ($response->body['data'] as $mod) {
                if (!is_array($mod)) {
                    continue;
                }

                $id = (string) ($mod['id'] ?? '');

                if ($id !== '') {
                    $projects[$id] = $mod;
                }
            }

            return $projects;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // Resolves a project's server installability by inspecting its public metadata and the latest file list returned by the...
    private function classifyProject(?array $project): string
    {
        if ($project === null) {
            return self::CLASS_UNKNOWN;
        }

        $files = $this->arrayOf($project['latestFiles'] ?? []);

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (
                $this->fileReferencesServerPack($file)
                || $this->isServerPack($file)
            ) {
                return self::CLASS_SERVER_PACK;
            }
        }

        foreach ($files as $file) {
            if (
                !is_array($file)
                || !$this->isMainArchive($file)
                || !$this->isPubliclyDownloadable($file)
            ) {
                continue;
            }

            return self::CLASS_MAIN_ARCHIVE;
        }

        return $files === []
            ? self::CLASS_UNKNOWN
            : self::CLASS_CLIENT_ONLY;
    }

    // Whether the given file references a dedicated server pack that must be installed alongside it.
    private function fileReferencesServerPack(array $file): bool
    {
        $serverPackFileId = $this->intOrNull(
            $file['serverPackFileId'] ?? null,
        );

        return $serverPackFileId !== null && $serverPackFileId > 0;
    }

    // Whether the given file is itself a server pack.
    private function isServerPack(array $file): bool
    {
        return ($file['isServerPack'] ?? false) === true;
    }

    // Whether the file is a primary (non-alternate) archive rather than an alternate payload such as a source or...
    private function isMainArchive(array $file): bool
    {
        $isAlternate = $this->intOrNull($file['isAlternate'] ?? null);

        return !($isAlternate === 1 || ($file['isAlternate'] ?? false) === true);
    }

    // A file is server installable when it explicitly points at a CurseForge server pack (serverPackFileId) or is itself...
    private function isServerInstallableFile(array $file): bool
    {
        return $this->fileReferencesServerPack($file)
            || $this->isServerPack($file);
    }

    // Resolves a file into the archive a server installation should deploy.
    private function resolveInstallableFile(
        array $file,
        string $project,
        array $serverPacks = [],
    ): ?array {
        if ($this->isServerPack($file)) {
            return $file;
        }

        if ($this->fileReferencesServerPack($file)) {
            $serverPackFileId = (int) ($file['serverPackFileId'] ?? 0);

            $serverPack = $serverPacks[$serverPackFileId]
                ?? $this->fetchServerPackFile($project, $file);

            if ($serverPack !== null) {
                return $this->inheritMissingGameVersions($serverPack, $file);
            }
        }

        if (!$this->isMainArchive($file)) {
            return null;
        }

        return $file;
    }

    // Resolves every distinct referenced server pack id across the file list through the bulk /mods/files endpoint (one call...
    private function bulkResolveServerPacks(
        array $files,
        string $project,
    ): array {
        $ids = [];

        foreach ($files as $file) {
            if (!is_array($file) || !$this->fileReferencesServerPack($file)) {
                continue;
            }

            $id = $this->intOrNull($file['serverPackFileId'] ?? null);

            if ($id !== null && $id > 0) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_map('intval', array_keys($ids));

        $resolved = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            try {
                $response = $this->http->post(
                    self::API_BASE . '/mods/files',
                    body: ['fileIds' => $chunk],
                    headers: $this->headers(),
                );
            } catch (ProviderHttpException $exception) {
                // A bulk failure must not kill the whole version menu; the per-file fallback inside resolveInstallableFile() co...
                continue;
            }

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
            ) {
                continue;
            }

            foreach ($response->body['data'] as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $id = $this->intOrNull($file['id'] ?? null);
                $modId = $this->intOrNull($file['modId'] ?? null);

                // The bulk endpoint returns files across projects, so every payload is checked against the requested project id.
                if (
                    $id === null
                    || $modId !== (int) $project
                    || !$this->isPubliclyDownloadable($file)
                ) {
                    continue;
                }

                $resolved[$id] = $file;
            }
        }

        return $resolved;
    }

    // CurseForge server-pack files frequently ship with an empty gameVersions array even though the client pack that...
    private function inheritMissingGameVersions(
        array $serverPack,
        array $referencingFile,
    ): array {
        $serverVersions = $serverPack['gameVersions'] ?? null;

        if (is_array($serverVersions) && $serverVersions !== []) {
            return $serverPack;
        }

        $referenceVersions = $referencingFile['gameVersions'] ?? null;

        if (!is_array($referenceVersions) || $referenceVersions === []) {
            return $serverPack;
        }

        $serverPack['gameVersions'] = $referenceVersions;

        return $serverPack;
    }

    // Fetches the dedicated server pack referenced by a main file.
    private function fetchServerPackFile(
        string $project,
        array $file,
    ): ?array {
        $serverPackFileId = $this->intOrNull(
            $file['serverPackFileId'] ?? null,
        );

        if ($serverPackFileId === null || $serverPackFileId <= 0) {
            return null;
        }

        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/mods/'
                    . $project
                    . '/files/'
                    . $serverPackFileId,
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

            $serverPack = $response->body['data'];
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                return null;
            }

            throw $this->requestFailure(
                'Failed to fetch the server pack.',
                $exception,
            );
        }

        if (!$this->isPubliclyDownloadable($serverPack)) {
            return null;
        }

        return $serverPack;
    }

    // Emits a structured diagnostics line for the given search so filtering decisions are auditable in the panel's error log.
    private function logDiagnostics(
        CatalogSearchQuery $query,
        array $diagnostics,
    ): void {
        $reasonSummary = [];

        foreach (($diagnostics['exclusion_reasons'] ?? []) as $reason => $count) {
            $reasonSummary[] = "{$reason}:{$count}";
        }

        $classSummary = [];

        foreach (($diagnostics['classification'] ?? []) as $class => $count) {
            $classSummary[] = "{$class}:{$count}";
        }

        $line = sprintf(
            '[addonmanager] CurseForge search page=%d limit=%d query=%s '
                . 'original_api_result_count=%d inspected=%d accepted=%d '
                . 'excluded=%d exclusion_reasons=%s classification=%s '
                . 'upstream_total=%s source_pages=%d more_source_pages=%d '
                . 'page_filled=%d scan_exhausted=%d',
            $query->page,
            $query->limit,
            json_encode($query->query),
            $diagnostics['original_api_result_count'] ?? 0,
            $diagnostics['inspected'] ?? 0,
            $diagnostics['accepted'] ?? 0,
            $diagnostics['excluded'] ?? 0,
            implode(',', $reasonSummary),
            implode(',', $classSummary),
            json_encode($diagnostics['upstream_total'] ?? null),
            $diagnostics['source_pages'] ?? 0,
            (int) ($diagnostics['more_source_pages'] ?? false),
            (int) ($diagnostics['page_filled'] ?? false),
            (int) ($diagnostics['scan_exhausted'] ?? false),
        );

        @error_log($line);
    }

    // @param array<int, CatalogItem> $items
    private static function matchesMultiFilters(
        CatalogSearchQuery $query,
        CatalogItem $item,
    ): bool {
        if (
            count($query->gameVersions) > 1
            && array_intersect(
                $query->gameVersions,
                $item->gameVersions,
            ) === []
        ) {
            return false;
        }

        if (
            count($query->loaders) > 1
            && array_intersect(
                $query->loaders,
                $item->loaders,
            ) === []
        ) {
            return false;
        }

        if (
            count($query->categories) > 1
            && self::categoriesIntersect(
                $query->categories,
                $item->categories,
            ) === false
        ) {
            return false;
        }

        return true;
    }

    // @return array<int, array<string, mixed>>
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

    // @return array<int, array<string, mixed>>
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

    // @return array<string, mixed>
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

    // Resolves a catalog category slug to a CurseForge category id for the modpacks class, or null when no category matches.
    private function resolveCategoryId(string $slug, int $classId): ?int
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/categories',
                query: [
                    'gameId' => self::GAME_ID,
                    'classId' => $classId,
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

            $fallback = null;

            foreach ($categories as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $candidate = strtolower((string) ($category['slug'] ?? ''));

                if ($candidate === $slug) {
                    $id = $category['id'] ?? null;

                    if (is_int($id)) {
                        return $id;
                    }
                }

                // Tolerant fallback: CurseForge slugs use hyphens where a filter may say "vanilla+" or drop separators.
                if (
                    $fallback === null
                    && str_replace(['-', '+', '_'], '', $candidate)
                        === str_replace(['-', '+', '_'], '', $slug)
                ) {
                    $id = $category['id'] ?? null;

                    if (is_int($id)) {
                        $fallback = $id;
                    }
                }
            }

            return $fallback;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // Compares filter category slugs against an item's category slugs with hyphen/underscore/plus normalization, so...
    private static function categoriesIntersect(array $filters, array $itemCategories): bool
    {
        $normalize = static function (string $value): string {
            return str_replace(['-', '_', '+'], '', strtolower($value));
        };

        $itemSet = [];

        foreach ($itemCategories as $category) {
            $itemSet[$normalize($category)] = true;
        }

        foreach ($filters as $filter) {
            if (isset($itemSet[$normalize($filter)])) {
                return true;
            }
        }

        return false;
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
            appliedGameVersions: $query->gameVersions,
            appliedLoaders: $query->loaders,
            appliedCategories: $query->categories,
            appliedEnvironments: $query->environments,
        );
    }

    // @param array<string, mixed> $mod
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

            // Each index entry carries BOTH a mod loader and a Minecraft game version; they are independent facts, so the l...
            $modLoader = $this->intOrNull($entry['modLoader'] ?? null);
            $loaderSlug = $modLoader !== null
                ? (self::MOD_LOADER_TO_SLUG[$modLoader] ?? null)
                : null;

            if ($loaderSlug !== null) {
                $loaders[$loaderSlug] = true;
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

        $author = null;

        foreach ($this->arrayOf($mod['authors'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = $this->stringOrNull($entry['name'] ?? null);

            if ($name !== null) {
                $author = $name;

                break;
            }
        }

        $bannerUrl = null;

        foreach ($this->arrayOf($mod['screenshots'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = $this->validImageUrl(
                $this->stringOrNull($entry['thumbnailUrl'] ?? null)
                    ?? $this->stringOrNull($entry['url'] ?? null),
            );

            if ($url !== null) {
                $bannerUrl = $url;

                break;
            }
        }

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

        // Each class lives under its own CurseForge URL prefix (mods, modpacks, bukkit plugins, texture packs, data pac...
        $urlBase = self::URL_BASE_BY_CLASS_ID[
            $this->intOrNull($mod['classId'] ?? null) ?? self::MODPACK_CLASS_ID
        ] ?? self::MODPACK_URL_BASE;

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
                : $urlBase . $sourceId,
            downloads: $this->intOrNull($mod['downloadCount'] ?? null),
            follows: null,
            categories: array_keys($categories),
            gameVersions: array_keys($gameVersions),
            loaders: array_keys($loaders),
            latestVersion: $latestVersion,
            source: 'curseforge://' . $id,
            author: $author,
            updatedAt: $this->stringOrNull($mod['dateModified'] ?? null),
            bannerUrl: $bannerUrl,
            environment: null,
        );
    }

    // @param array<string, mixed> $file
    private function mapVersion(
        CatalogVersionQuery $query,
        array $file,
    ): CatalogVersion {
        $id = (string) ($file['id'] ?? '');

        [$gameVersions, $loaders] = $this->parseGameVersions(
            $file['gameVersions'] ?? [],
        );

        $version = $this->stringOrNull($file['displayName'] ?? null)
            ?? $this->stringOrNull($file['fileName'] ?? null)
            ?? $id;

        if ($this->isServerPack($file) && stripos($version, 'server pack') === false) {
            $version .= ' (Server Pack)';
        }

        return new CatalogVersion(
            provider: $this->name(),
            projectId: $query->project,
            projectSlug: null,
            projectName: null,
            versionId: $id,
            versionNumber: $version,
            versionName: $version,
            gameVersions: $gameVersions,
            loaders: $loaders,
            datePublished: $this->stringOrNull($file['fileDate'] ?? null),
            dateModified: null,
            downloads: $this->intOrNull($file['downloadCount'] ?? null),
            source: 'curseforge://' . $query->project . '@' . $id,
            fileSize: $this->intOrNull($file['fileLength'] ?? null),
        );
    }

    // A file is installable when its status is public (or unspecified), it is marked available, and it has a public download...
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

    // Every class this provider installs; the URL a project links to and the way it installs both follow from which...
    private function isCatalogClass(array $mod): bool
    {
        $classId = $this->intOrNull($mod['classId'] ?? null);

        if ($classId === null) {
            return true;
        }

        return in_array(
            $classId,
            array_values(self::CLASS_BY_CONTENT_TYPE),
            true,
        );
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

    // @param array<mixed> $gameVersions @return array{0: array<string>, 1: array<string>} [minecraft versions, loader slugs]
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

    // @return array<string>
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
                'CurseForge is not available. Please add your API key to the .env file.',
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

    // @return array<int, mixed>
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}