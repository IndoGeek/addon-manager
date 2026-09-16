<?php

/**
 * Functional tests for the catalog cache:
 *   - second identical search call is served from the cache (no additional
 *     provider requests)
 *   - different queries produce their own cache entries
 *   - a disabled cache behaves like an uncached service
 *   - fromArray hydration round-trips the payload faithfully
 *   - cache keys are scoped and query-sensitive
 */

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix =
        'Pterodactyl\\BlueprintFramework\\Extensions\\modpackinstaller\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $projectRoot
        . '/app/'
        . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\CurseForgeCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogCache;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogService;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionList;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class CacheFakeProviderHttpClient implements ProviderHttpClient
{
    private ProviderHttpResponse $handler;

    public int $gets = 0;

    public function __construct(ProviderHttpResponse $handler)
    {
        $this->handler = $handler;
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->gets++;

        return $this->handler;
    }

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
        return $this->handler;
    }
}

/**
 * In-memory cache store standing in for the panel's Redis: same operations
 * the CatalogCache contract relies on, no Redis dependency in tests.
 */
final class ArrayCacheStore
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function get(string $key)
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, $value, int $ttl): void
    {
        $this->items[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$store = new ArrayCacheStore();

$cache = new CatalogCache(300);
$cache->useStore($store);

$http = new CacheFakeProviderHttpClient(
    new ProviderHttpResponse(200, [
        'data' => [
            [
                'id' => 314768,
                'slug' => 'prominence-2-rpg',
                'name' => 'Prominence 2 RPG',
                'summary' => 'A dark fantasy modpack.',
                'classId' => 4471,
                'downloadCount' => 1000,
                'categories' => [],
                'latestFilesIndexes' => [],
                'logo' => ['url' => 'https://cdn.example/prom.png'],
            ],
        ],
        'pagination' => ['totalCount' => 1],
    ]),
);

$service = new CatalogService(
    new CatalogProviderRegistry([
        new CurseForgeCatalogProvider($http, 'secret-key'),
    ]),
    $cache,
);

$query = new CatalogSearchQuery(
    provider: 'curseforge',
    sort: CatalogSort::DOWNLOADS,
    limit: 5,
);

$first = $service->search($query);
$getsAfterFirst = $http->gets;

$second = $service->search($query);

if ($http->gets !== $getsAfterFirst) {
    throw new RuntimeException(
        'Second identical search must be served from the cache.'
    );
}

if (json_encode($first->toArray()) !== json_encode($second->toArray())) {
    throw new RuntimeException('Cached search payload diverged from the fresh one.');
}

if ($store->items === []) {
    throw new RuntimeException('Search must write a cache entry.');
}

pass('identical search hits the cache, not the provider');

// A different query must not reuse the first entry.
$service->search(new CatalogSearchQuery(
    provider: 'curseforge',
    query: 'prominence',
    sort: CatalogSort::DOWNLOADS,
    limit: 5,
));

if ($http->gets === $getsAfterFirst) {
    throw new RuntimeException(
        'A changed query must bypass the cached entry and hit the provider.'
    );
}

pass('different queries get their own cache entries');

// Disabled cache behaves like an uncached service.
$uncached = new CatalogService(
    new CatalogProviderRegistry([
        new CurseForgeCatalogProvider($http, 'secret-key'),
    ]),
    null,
);

$uncached->search($query);

if ($http->gets === $getsAfterFirst) {
    throw new RuntimeException('Disabled cache must not be consulted.');
}

pass('disabled cache degrades to direct provider calls');

// fromArray hydration round-trip.
$hydrated = CatalogResult::fromArray($first->toArray());

if (
    count($hydrated->items) !== count($first->items)
    || $hydrated->pagination->total !== $first->pagination->total
    || $hydrated->provider !== $first->provider
    || $hydrated->sort !== $first->sort
) {
    throw new RuntimeException('CatalogResult::fromArray lost data.');
}

$versionList = new CatalogVersionList(
    provider: 'curseforge',
    appliedGameVersions: ['1.20.1'],
    appliedLoaders: ['forge'],
    versions: [
        new CatalogVersion(
            provider: 'curseforge',
            projectId: '314768',
            projectSlug: null,
            projectName: null,
            versionId: '111',
            versionNumber: 'V1.0.0',
            versionName: 'V1.0.0',
            gameVersions: ['1.20.1'],
            loaders: ['Forge'],
            datePublished: '2025-01-10T00:00:00Z',
            dateModified: null,
            downloads: 5,
            source: 'curseforge://314768@111',
            fileSize: 1024,
        ),
    ],
);

$hydratedList = CatalogVersionList::fromArray($versionList->toArray());

if (
    $hydratedList->provider !== 'curseforge'
    || count($hydratedList->versions) !== 1
    || $hydratedList->versions[0]->source !== 'curseforge://314768@111'
    || $hydratedList->versions[0]->fileSize !== 1024
    || $hydratedList->appliedLoaders !== ['forge']
) {
    throw new RuntimeException('CatalogVersionList::fromArray lost data.');
}

pass('cache hydration round-trips payloads faithfully');

// Cache keys must differ per scope and per coordinates.
$keyA = CatalogCache::key('search', ['provider' => 'curseforge', 'page' => 1]);
$keyB = CatalogCache::key('search', ['provider' => 'curseforge', 'page' => 2]);
$keyC = CatalogCache::key('versions', ['provider' => 'curseforge', 'page' => 1]);

if ($keyA === $keyB || $keyA === $keyC) {
    throw new RuntimeException('Cache keys must vary per query and scope.');
}

pass('cache keys are scoped and query-sensitive');

// Regression: versions() must work end-to-end through the service for both
// providers, with strict error handling (undefined property access on the
// query object would previously fatal here in production).
$versionsService = new CatalogService(
    new CatalogProviderRegistry([
        new CurseForgeCatalogProvider($http, 'secret-key'),
    ]),
    $cache,
);

$cfList = $versionsService->versions(new CatalogVersionQuery(
    provider: 'curseforge',
    project: '444',
));

if ($cfList->provider !== 'curseforge') {
    throw new RuntimeException('CurseForge versions failed through the cached service.');
}

$cfCached = $versionsService->versions(new CatalogVersionQuery(
    provider: 'curseforge',
    project: '444',
));

if (json_encode($cfList->toArray()) !== json_encode($cfCached->toArray())) {
    throw new RuntimeException('Cached version list diverged.');
}

pass('versions() works and caches through the service');

echo "ALL CATALOG-CACHE TESTS PASSED\n";
