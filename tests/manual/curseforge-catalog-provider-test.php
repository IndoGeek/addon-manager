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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\CurseForgeCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @var array<int, ProviderHttpResponse|Throwable> */
    public function __construct(private array $handlers)
    {
    }

    /** @var array<int, array{url: string, query: array, headers: array}> */
    public array $requests = [];

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

function sampleMod(int $id, string $slug, string $loaderValue): array
{
    return [
        'id' => $id,
        'slug' => $slug,
        'name' => ucfirst($slug),
        'summary' => 'A sample modpack.',
        'classId' => 4471,
        'downloadCount' => 100,
        'categories' => [
            ['id' => 1, 'slug' => 'adventure', 'isClass' => false],
        ],
        'latestFilesIndexes' => [
            [
                'modLoader' => $loaderValue,
                'gameVersion' => '1.20.1',
                'filename' => $slug . '-1.0.0.zip',
            ],
        ],
        'authors' => [
            ['name' => ucfirst($slug) . ' Author'],
        ],
        'dateModified' => '2025-01-10T00:00:00Z',
        'screenshots' => [
            ['thumbnailUrl' => 'https://cdn.example/' . $slug . '-shot.png'],
        ],
        'logo' => ['url' => 'https://cdn.example/' . $slug . '.png'],
    ];
}

$unconfigured = new CurseForgeCatalogProvider(new FakeProviderHttpClient([]), null);

if ($unconfigured->available() || $unconfigured->state() !== 'not_configured') {
    throw new RuntimeException('Unconfigured CurseForge state mismatch.');
}

if ($unconfigured->developmentOnly()) {
    throw new RuntimeException('CurseForge must not be development-only.');
}

$unconfiguredCapabilities = $unconfigured->capabilities();

if (($unconfiguredCapabilities['environment'] ?? true) !== false) {
    throw new RuntimeException(
        'CurseForge must not advertise an environment capability.',
    );
}

if ($unconfigured->facets()['environments'] !== []) {
    throw new RuntimeException(
        'CurseForge must not offer environment options.',
    );
}

try {
    $unconfigured->search(new CatalogSearchQuery(provider: 'curseforge'));
    throw new RuntimeException('Unconfigured search was accepted.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'CURSEFORGE_API_KEY')) {
        throw new RuntimeException('Unexpected config message.');
    }
}

pass('missing credentials report not_configured and refuse search');

$configured = new CurseForgeCatalogProvider(new FakeProviderHttpClient([]), 'secret-key');

if (!$configured->available() || $configured->state() !== 'available') {
    throw new RuntimeException('Configured CurseForge state mismatch.');
}

$headers = $configured->capabilities();

if (($headers['sort'] ?? false) !== true || ($headers['loaders'] ?? false) !== true) {
    throw new RuntimeException('CurseForge capabilities missing.');
}

pass('configured credentials report available state');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [sampleMod(111, 'fabric-pack', 4)],
        'pagination' => ['totalCount' => 137],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$result = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    sort: \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort::DOWNLOADS,
    page: 3,
    limit: 25,
));

$request = $http->requests[0] ?? null;

if ($request === null) {
    throw new RuntimeException('No search request was made.');
}

if ($request['url'] !== 'https://api.curseforge.com/v1/mods/search') {
    throw new RuntimeException('Unexpected search URL.');
}

if (($request['query']['gameId'] ?? null) !== 432) {
    throw new RuntimeException('Missing CurseForge gameId.');
}

if (($request['query']['classId'] ?? null) !== 4471) {
    throw new RuntimeException('Missing modpacks classId.');
}

if ((int) ($request['query']['index'] ?? -1) !== 50) {
    throw new RuntimeException('Unexpected index for page 3.');
}

if ((int) ($request['query']['pageSize'] ?? 0) !== 25) {
    throw new RuntimeException('Unexpected pageSize.');
}

if ((int) ($request['query']['sortField'] ?? 0) !== 6) {
    throw new RuntimeException('Unexpected downloads sort mapping.');
}

if (!str_contains(($request['headers'][0] ?? ''), 'X-Api-Key: secret-key')) {
    throw new RuntimeException('API key header missing from request.');
}

if ($result->pagination->total !== 137) {
    throw new RuntimeException('Unexpected upstream total.');
}

pass('search builds correct request parameters and API key header');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            sampleMod(111, 'fabric-pack', 4),
            sampleMod(222, 'neo-pack', 6),
        ],
        'pagination' => ['totalCount' => 500],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$multi = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    loader: ['fabric', 'forge'],
));

$multiRequest = $http->requests[0] ?? null;

if (($multiRequest['query']['modLoaderType'] ?? null) !== 4) {
    throw new RuntimeException(
        'First loader should be applied upstream as modLoaderType.',
    );
}

if (count($multi->items) !== 1) {
    throw new RuntimeException(
        'Post-filter should remove the neoforge-only item.',
    );
}

if (($multi->items[0]->slug ?? '') !== 'fabric-pack') {
    throw new RuntimeException('Post-filter kept the wrong item.');
}

if (($multi->items[0]->author ?? null) !== 'Fabric-pack Author') {
    throw new RuntimeException('Author should map from the first author entry.');
}

if (($multi->items[0]->updatedAt ?? null) !== '2025-01-10T00:00:00Z') {
    throw new RuntimeException('dateModified should map to updated date.');
}

if (($multi->items[0]->bannerUrl ?? null) !== 'https://cdn.example/fabric-pack-shot.png') {
    throw new RuntimeException('First screenshot should map to the banner.');
}

if ($multi->pagination->total !== 1) {
    throw new RuntimeException(
        'Conservative total should only count visible items.',
    );
}

if ($multi->appliedLoaders !== ['fabric', 'forge']) {
    throw new RuntimeException('Applied loaders should echo the full selection.');
}

pass('multi-value loaders apply upstream + provider-side honestly');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('Unexpected request.', 500),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$environmentFiltered = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    environments: ['server'],
));

if ($environmentFiltered->items !== [] || $environmentFiltered->pagination->total !== 0) {
    throw new RuntimeException(
        'Unsupported environment filter must return an honest empty result.',
    );
}

if ($http->requests !== []) {
    throw new RuntimeException(
        'Unsupported environment filter must not hit the upstream API.',
    );
}

pass('unsupported filter resolves to an honest empty result');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            'id' => 333,
            'slug' => 'project-details',
            'name' => 'Project Details',
            'summary' => 'Details view will use this.',
            'classId' => 4471,
            'downloadCount' => 999,
            'categories' => [],
            'latestFilesIndexes' => [],
            'logo' => ['url' => 'https://cdn.example/pd.png'],
        ],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$details = $provider->project(new CatalogProjectQuery(
    provider: 'curseforge',
    project: '333',
));

if (($details->name ?? '') !== 'Project Details') {
    throw new RuntimeException('CurseForge project name mismatch.');
}

if (($details->source ?? '') !== 'curseforge://333') {
    throw new RuntimeException('CurseForge project source mismatch.');
}

if (($details->downloads ?? 0) !== 999) {
    throw new RuntimeException('CurseForge project downloads mismatch.');
}

$detailRequest = $http->requests[0] ?? null;

if (($detailRequest['url'] ?? '') !== 'https://api.curseforge.com/v1/mods/333') {
    throw new RuntimeException('Unexpected project details URL.');
}

pass('project details mapped from CurseForge mod payload');

try {
    $provider->project(new CatalogProjectQuery(
        provider: 'curseforge',
        project: 'not-a-number',
    ));
    throw new RuntimeException('Non-numeric CurseForge project was accepted.');
} catch (InvalidArgumentException $exception) {
    pass('non-numeric CurseForge project id rejected');
}

echo "All CurseForge catalog provider tests passed.\n";