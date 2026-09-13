<?php

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix =
        'Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\\';

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\ModrinthCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @var array<int, array{url: string, query: array, headers: array}> */
    public array $requests = [];

    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(private array $handlers)
    {
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->requests[] = [
            'url' => $url,
            'query' => $query,
            'headers' => $headers,
        ];

        $handler = array_shift($this->handlers);

        if ($handler instanceof Throwable) {
            throw $handler;
        }

        if (!$handler instanceof ProviderHttpResponse) {
            throw new RuntimeException('Unexpected fake HTTP handler.');
        }

        return $handler;
    }
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

function sampleHits(): array
{
    return [
        [
            'project_id' => 'AANobbMI',
            'slug' => 'prominence-2-rpg',
            'title' => 'Prominence 2 RPG',
            'description' => 'A dark fantasy modpack.',
            'icon_url' => 'https://cdn.example/icon.png',
            'author' => 'Team Prominence',
            'date_modified' => '2025-01-15T10:00:00Z',
            'featured_gallery' => [
                ['url' => 'https://cdn.example/banner.png'],
            ],
            'client_side' => 'required',
            'server_side' => 'required',
            'categories' => ['fabric', 'adventure', 'combat'],
            'display_categories' => ['fabric'],
            'versions' => ['1.21.1', '1.20'],
            'downloads' => 120000,
            'follows' => 8500,
            'latest_version' => '2.0.1',
            'project_type' => 'modpack',
        ],
        [
            'project_id' => 'abc123',
            'slug' => 'missing-meta',
            'title' => 'Missing Meta',
            'description' => '',
            'categories' => ['forge', 'technology'],
            'display_categories' => [],
            'versions' => [],
            'downloads' => '500',
            'latest_version' => '',
            'client_side' => 'unsupported',
            'server_side' => 'required',
            'project_type' => 'modpack',
        ],
    ];
}

$provider = new ModrinthCatalogProvider(new FakeProviderHttpClient([]));

if ($provider->name() !== 'modrinth') {
    throw new RuntimeException('Unexpected provider name.');
}
if (!$provider->available()) {
    throw new RuntimeException('Modrinth catalog should be available.');
}
if ($provider->state() !== 'available') {
    throw new RuntimeException('Unexpected Modrinth provider state.');
}
if ($provider->developmentOnly()) {
    throw new RuntimeException('Modrinth must not be development-only.');
}

$capabilities = $provider->capabilities();
$requiredCapabilities = [
    'query',
    'game_versions',
    'loaders',
    'categories',
    'environment',
    'sort',
];

foreach ($requiredCapabilities as $capability) {
    if ($capabilities[$capability] !== true) {
        throw new RuntimeException("Modrinth should support {$capability}.");
    }
}

$facets = $provider->facets();

if (!in_array('1.20.1', $facets['game_versions'], true)) {
    throw new RuntimeException('Modrinth facets missing common game version.');
}
if (!in_array('fabric', $facets['loaders'], true)) {
    throw new RuntimeException('Modrinth facets missing fabric loader.');
}
if (!in_array('client-and-server', $facets['environments'], true)) {
    throw new RuntimeException('Modrinth facets missing environment values.');
}
if ($facets['categories'] === []) {
    throw new RuntimeException('Modrinth facets missing categories.');
}

pass('provider identity reported');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'hits' => sampleHits(),
        'total_hits' => 137,
    ]),
]);
$provider = new ModrinthCatalogProvider($http);

$result = $provider->search(new CatalogSearchQuery(
    provider: 'modrinth',
    query: 'prominence',
    gameVersion: '1.21.1',
    loader: 'fabric',
    category: 'adventure',
    sort: CatalogSort::DOWNLOADS,
    page: 2,
    limit: 25,
));

$request = $http->requests[0] ?? null;

if ($request === null) {
    throw new RuntimeException('No HTTP request was made.');
}

if ($request['url'] !== 'https://api.modrinth.com/v2/search') {
    throw new RuntimeException('Unexpected search URL: ' . $request['url']);
}

$expectedFacets = json_encode([
    ['project_type:modpack'],
    ['versions:1.21.1'],
    ['categories:fabric'],
    ['categories:adventure'],
]);

if (($request['query']['facets'] ?? null) !== $expectedFacets) {
    throw new RuntimeException(
        'Unexpected facets: ' . ($request['query']['facets'] ?? 'null'),
    );
}

if (($request['query']['query'] ?? null) !== 'prominence') {
    throw new RuntimeException('Unexpected query parameter.');
}

if (($request['query']['index'] ?? null) !== 'downloads') {
    throw new RuntimeException('Unexpected sort mapping.');
}

if ((int) ($request['query']['limit'] ?? 0) !== 25) {
    throw new RuntimeException('Unexpected limit.');
}

if ((int) ($request['query']['offset'] ?? -1) !== 25) {
    throw new RuntimeException('Unexpected offset for page 2.');
}

pass('search request builders correct facets/sort/offset');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'hits' => [],
        'total_hits' => 0,
    ]),
]);
$multiProvider = new ModrinthCatalogProvider($http);

$multiProvider->search(new CatalogSearchQuery(
    provider: 'modrinth',
    gameVersion: ['1.21.1', '1.20.1'],
    loader: ['fabric', 'neoforge'],
    category: ['adventure', 'technology'],
    environments: ['client', 'server'],
));

$multiRequest = $http->requests[0] ?? null;

if ($multiRequest === null) {
    throw new RuntimeException('No multi-value HTTP request was made.');
}

if (($multiRequest['query']['facets'] ?? null) !== json_encode([
    ['project_type:modpack'],
    ['versions:1.21.1', 'versions:1.20.1'],
    ['categories:fabric', 'categories:neoforge'],
    ['categories:adventure', 'categories:technology'],
    ['categories:client', 'categories:server'],
])) {
    throw new RuntimeException(
        'Multi-value facets did not map to separate ANDed groups.',
    );
}

pass('multi-value filters map to separate ANDed facet groups');

$items = $result->items;

if (count($items) !== 2) {
    throw new RuntimeException('Expected 2 catalog items.');
}

$first = $items[0];

if ($first->provider !== 'modrinth') {
    throw new RuntimeException('Unexpected item provider.');
}
if ($first->providerProjectId !== 'AANobbMI') {
    throw new RuntimeException('Unexpected project id.');
}
if ($first->slug !== 'prominence-2-rpg') {
    throw new RuntimeException('Unexpected slug.');
}
if ($first->name !== 'Prominence 2 RPG') {
    throw new RuntimeException('Unexpected name.');
}
if ($first->summary !== 'A dark fantasy modpack.') {
    throw new RuntimeException('Unexpected summary.');
}
if ($first->iconUrl !== 'https://cdn.example/icon.png') {
    throw new RuntimeException('Unexpected icon URL.');
}
if ($first->projectUrl !== 'https://modrinth.com/modpack/prominence-2-rpg') {
    throw new RuntimeException('Unexpected project URL.');
}
if ($first->downloads !== 120000) {
    throw new RuntimeException('Unexpected downloads.');
}
if ($first->follows !== 8500) {
    throw new RuntimeException('Unexpected follows.');
}
if ($first->loaders !== ['fabric']) {
    throw new RuntimeException('Loaders should come from display_categories.');
}
if (!in_array('adventure', $first->categories, true)) {
    throw new RuntimeException('Categories should exclude loaders.');
}
if (in_array('fabric', $first->categories, true)) {
    throw new RuntimeException('Loader leaked into categories.');
}
if ($first->gameVersions !== ['1.21.1', '1.20']) {
    throw new RuntimeException('Unexpected game versions.');
}
if ($first->latestVersion !== '2.0.1') {
    throw new RuntimeException('Unexpected latest version.');
}
if ($first->source !== 'modrinth://prominence-2-rpg') {
    throw new RuntimeException('Unexpected source.');
}
if ($first->author !== 'Team Prominence') {
    throw new RuntimeException('Unexpected author.');
}
if ($first->updatedAt !== '2025-01-15T10:00:00Z') {
    throw new RuntimeException('Unexpected updated date.');
}
if ($first->bannerUrl !== 'https://cdn.example/banner.png') {
    throw new RuntimeException('Featured gallery should map to banner.');
}
if ($first->environment !== 'client-and-server') {
    throw new RuntimeException('Unexpected environment mapping.');
}

pass('hit mapped to normalized catalog item');

$second = $items[1];

if ($second->summary !== null) {
    throw new RuntimeException('Blank summary should be null.');
}

if ($second->loaders !== ['forge']) {
    throw new RuntimeException(
        'Loaders should fall back to known loader categories.',
    );
}

if ($second->downloads !== 500) {
    throw new RuntimeException('Numeric string downloads should map to int.');
}

if ($second->latestVersion !== null) {
    throw new RuntimeException('Blank latest version should be null.');
}

if ($second->slug !== 'missing-meta' || $second->projectUrl === null) {
    throw new RuntimeException('Slug/URL mapping failed.');
}

if ($second->author !== null || $second->updatedAt !== null) {
    throw new RuntimeException('Missing author/updated fields should stay null.');
}

if ($second->bannerUrl !== null) {
    throw new RuntimeException('Missing gallery should leave banner null.');
}

if ($second->environment !== 'server') {
    throw new RuntimeException(
        'Unsupported client side should map to a server-only environment.',
    );
}

pass('nullable and fallback fields mapped defensively');

if ($result->provider !== 'modrinth') {
    throw new RuntimeException('Active provider not echoed.');
}
if ($result->appliedQuery !== 'prominence') {
    throw new RuntimeException('Applied query not echoed.');
}
if ($result->appliedGameVersions !== ['1.21.1']) {
    throw new RuntimeException('Applied game versions not echoed.');
}
if (
    $result->appliedLoaders !== ['fabric']
    || $result->appliedCategories !== ['adventure']
) {
    throw new RuntimeException('Applied filters not echoed.');
}
if ($result->appliedEnvironments !== []) {
    throw new RuntimeException('Applied environments should be empty.');
}
if ($result->sort !== 'downloads') {
    throw new RuntimeException('Applied sort not echoed.');
}
if ($result->pagination->total !== 137) {
    throw new RuntimeException('Unexpected total.');
}
if ($result->pagination->totalPages !== 6) {
    throw new RuntimeException('Unexpected total pages for 137/25.');
}
if (!$result->pagination->hasNext) {
    throw new RuntimeException('Page 2 of 6 should have next.');
}
if (!$result->pagination->hasPrevious) {
    throw new RuntimeException('Page 2 should have previous.');
}

pass('pagination and applied filter echo correct');

$data = $result->toArray();

if ($data['pagination']['total'] !== 137) {
    throw new RuntimeException('toArray pagination mismatch.');
}

if (!isset($data['filters']['game_versions'])) {
    throw new RuntimeException('toArray missing filter echo.');
}

if ($data['filters']['loaders'] !== ['fabric']) {
    throw new RuntimeException('toArray loader echo mismatch.');
}

$serializedItem = $data['items'][0] ?? null;

if ($serializedItem === null || !is_array($serializedItem)) {
    throw new RuntimeException('toArray missing serialized items.');
}

foreach (['author', 'updated_at', 'banner_url', 'environment'] as $key) {
    if (!array_key_exists($key, $serializedItem)) {
        throw new RuntimeException("toArray missing {$key}.");
    }
}

if (
    $serializedItem['author'] !== 'Team Prominence'
    || $serializedItem['updated_at'] !== '2025-01-15T10:00:00Z'
) {
    throw new RuntimeException('toArray author/updated serialization mismatch.');
}

pass('normalized contract serializes with pagination + filters');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'hits' => [],
        'total_hits' => 0,
    ]),
]);
$provider = new ModrinthCatalogProvider($http);

$empty = $provider->search(new CatalogSearchQuery(query: 'nothing'));

if ($empty->items !== [] || $empty->pagination->total !== 0) {
    throw new RuntimeException('Empty result should be a valid response.');
}

if ($empty->pagination->totalPages !== 0 || $empty->pagination->hasNext) {
    throw new RuntimeException('Empty result pagination should be closed.');
}

pass('empty result is a valid response, not an error');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'id' => 'AANobbMI',
        'slug' => 'prominence-2-rpg',
        'title' => 'Prominence 2 RPG',
        'description' => 'A dark fantasy modpack.',
        'icon_url' => 'https://cdn.example/icon.png',
        'author' => 'Team Prominence',
        'updated' => '2025-01-15T10:00:00Z',
        'gallery' => [
            ['url' => 'https://cdn.example/shot.png'],
        ],
        'client_side' => 'required',
        'server_side' => 'unsupported',
        'downloads' => 120000,
        'followers' => 8500,
        'categories' => ['fabric', 'adventure', 'combat'],
        'loaders' => ['fabric'],
        'game_versions' => ['1.21.1', '1.20'],
    ]),
]);
$provider = new ModrinthCatalogProvider($http);

$details = $provider->project(new \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery(
    provider: 'modrinth',
    project: 'prominence-2-rpg',
));

if ($request = ($http->requests[0] ?? null)) {
    if (
        $request['url'] !== 'https://api.modrinth.com/v2/project/prominence-2-rpg'
    ) {
        throw new RuntimeException('Unexpected project URL.');
    }
}

if (($details->name ?? '') !== 'Prominence 2 RPG') {
    throw new RuntimeException('Project name mismatch.');
}

if (($details->follows ?? 0) !== 8500) {
    throw new RuntimeException('Project followers should map to follows.');
}

if ($details->loaders !== ['fabric']) {
    throw new RuntimeException('Project loaders mismatch.');
}

if (in_array('fabric', $details->categories, true)) {
    throw new RuntimeException('Loader leaked into project categories.');
}

if ($details->author !== 'Team Prominence') {
    throw new RuntimeException('Project author not mapped.');
}

if ($details->updatedAt !== '2025-01-15T10:00:00Z') {
    throw new RuntimeException('Project updated date not mapped.');
}

if ($details->bannerUrl !== 'https://cdn.example/shot.png') {
    throw new RuntimeException('Project gallery should map to banner.');
}

if ($details->environment !== 'client') {
    throw new RuntimeException('Project environment flags not mapped.');
}

if (($details->source ?? '') !== 'modrinth://prominence-2-rpg') {
    throw new RuntimeException('Project source mismatch.');
}

if (($details->projectUrl ?? '') !== 'https://modrinth.com/modpack/prominence-2-rpg') {
    throw new RuntimeException('Project URL mismatch.');
}

pass('project details mapped to a normalized catalog item');

$invalidBodies = [
    [
        'total_hits' => 5,
    ],
    [
        'hits' => 'nope',
        'total_hits' => 5,
    ],
    [
        'hits' => [[], 'bad', []],
        'total_hits' => 5,
    ],
];

foreach ($invalidBodies as $index => $body) {
    $http = new FakeProviderHttpClient([
        new ProviderHttpResponse(200, $body),
    ]);
    $provider = new ModrinthCatalogProvider($http);

    try {
        $provider->search(new CatalogSearchQuery());
        throw new RuntimeException("Invalid body #{$index} was accepted.");
    } catch (CatalogProviderException $exception) {
        pass("invalid upstream body #{$index} rejected cleanly");
    }
}

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 429 response.', 429),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->search(new CatalogSearchQuery());
    throw new RuntimeException('429 was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'rate limited')) {
        throw new RuntimeException('Unexpected rate-limit message.');
    }
}

pass('rate limited mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 500 response.', 500),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->search(new CatalogSearchQuery());
    throw new RuntimeException('500 was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'temporarily unavailable')) {
        throw new RuntimeException('Unexpected 5xx message.');
    }
}

pass('upstream 5xx mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('Unable to reach the provider.'),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->search(new CatalogSearchQuery());
    throw new RuntimeException('Unreachable provider was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'Unable to reach')) {
        throw new RuntimeException('Unexpected unreachable message.');
    }
}

pass('unreachable provider mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 400 response.', 400),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->search(new CatalogSearchQuery());
    throw new RuntimeException('400 was not handled.');
} catch (CatalogProviderException $exception) {
    if (!str_contains($exception->getMessage(), 'rejected')) {
        throw new RuntimeException('Unexpected rejected message.');
    }
}

pass('upstream 4xx mapped to provider failure (502)');

echo "All Modrinth catalog provider tests passed.\n";