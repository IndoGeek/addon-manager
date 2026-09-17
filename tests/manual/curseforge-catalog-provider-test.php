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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
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

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->requests[] = [
            'url' => $url,
            'body' => $body,
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

// A mod as returned by the bulk /mods endpoint (includes latestFiles).
function sampleModDetails(int $id, array $latestFiles): array
{
    return [
        'id' => $id,
        'slug' => 'pack-' . $id,
        'classId' => 4471,
        'latestFiles' => $latestFiles,
    ];
}

// @param array<string, mixed> $extra @return array<string, mixed>
function sampleFile(int $id, string $name, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'displayName' => $name,
        'fileName' => $name,
        'gameVersions' => ['1.20.1', 'Forge'],
        'fileDate' => '2025-01-10T00:00:00Z',
        'downloadCount' => 5,
        'fileLength' => 1024,
        'fileStatus' => 4,
        'isAvailable' => true,
        'downloadUrl' => 'https://download.curseforge.com/' . $id,
        'isServerPack' => false,
        'serverPackFileId' => null,
    ], $extra);
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
    if (
        !str_contains(
            $exception->getMessage(),
            'Please add your API key to the .env file',
        )
    ) {
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

// A block of server-capable search hits numbered consecutively from $from.
function searchBlock(int $from, int $count): array
{
    $mods = [];

    for ($i = 0; $i < $count; $i++) {
        $id = $from + $i;
        $mods[] = sampleMod($id, 'pack-' . $id, 4);
    }

    return $mods;
}

// A bulk /mods payload of server-capable details for the given ids.
function capableBulk(array $ids): array
{
    $details = [];

    foreach ($ids as $id) {
        $details[] = sampleModDetails($id, [
            [
                'id' => 5000 + $id,
                'isServerPack' => false,
                'serverPackFileId' => 6000 + $id,
            ],
        ]);
    }

    return $details;
}

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => searchBlock(1, 50),
        'pagination' => ['totalCount' => 137],
    ]),
    new ProviderHttpResponse(200, [
        'data' => capableBulk(range(1, 50)),
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$result = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    sort: \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort::DOWNLOADS,
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

if ((int) ($request['query']['index'] ?? -1) !== 0) {
    throw new RuntimeException('Scan must start at upstream index 0.');
}

if ((int) ($request['query']['pageSize'] ?? 0) !== 50) {
    throw new RuntimeException('Scan blocks must be requested at size 50.');
}

if ((int) ($request['query']['sortField'] ?? 0) !== 6) {
    throw new RuntimeException('Unexpected downloads sort mapping.');
}

if (!str_contains(($request['headers'][0] ?? ''), 'X-Api-Key: secret-key')) {
    throw new RuntimeException('API key header missing from request.');
}

if (count($result->items) !== 25) {
    throw new RuntimeException('A full result page must be filled.');
}

if (($result->items[0]->slug ?? '') !== 'pack-1') {
    throw new RuntimeException('Accepted stream must preserve upstream order.');
}

if ($result->pagination->total !== 137) {
    throw new RuntimeException('Upstream total must be preserved when the scan has not exhausted the corpus.');
}

if ($result->upstreamTotal !== 137 || $result->filteredTotal !== null) {
    throw new RuntimeException('Upstream/filtered totals must be reported separately.');
}

$diagnostics = $result->diagnostics;

if (($diagnostics['original_api_result_count'] ?? 0) !== 50) {
    throw new RuntimeException('Diagnostics must report the raw upstream result count.');
}

if (($diagnostics['accepted'] ?? 0) !== 50) {
    throw new RuntimeException('Diagnostics must report the accepted count.');
}

if (($diagnostics['source_pages'] ?? 0) !== 1 || ($diagnostics['more_source_pages'] ?? true) !== false) {
    throw new RuntimeException('Single-block scans must report one source page.');
}

if (($diagnostics['scan_exhausted'] ?? null) !== false) {
    throw new RuntimeException('A 50-item block must not imply an exhausted scan.');
}

pass('search scans from index 0, fills the page, and preserves the upstream total');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => searchBlock(1, 50),
        'pagination' => ['totalCount' => 137],
    ]),
    new ProviderHttpResponse(200, [
        'data' => capableBulk(range(1, 50)),
    ]),
    new ProviderHttpResponse(200, [
        'data' => searchBlock(51, 50),
        'pagination' => ['totalCount' => 137],
    ]),
    new ProviderHttpResponse(200, [
        'data' => capableBulk(range(51, 100)),
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$deep = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    limit: 25,
    page: 3,
));

$secondBlock = $http->requests[2] ?? null;

if ((int) ($secondBlock['query']['index'] ?? -1) !== 50) {
    throw new RuntimeException('Deep pages must continue the scan at the next block.');
}

if (count($deep->items) !== 25) {
    throw new RuntimeException('Deep pages must pull from later source blocks.');
}

if (($deep->items[0]->slug ?? '') !== 'pack-51') {
    throw new RuntimeException('Page offset must slice the accepted stream correctly.');
}

if (($deep->diagnostics['more_source_pages'] ?? false) !== true) {
    throw new RuntimeException('Multi-block scans must report more source pages.');
}

if (($deep->diagnostics['accepted'] ?? 0) !== 100) {
    throw new RuntimeException('Accepted count must span every scanned block.');
}

pass('pagination on later pages reaches projects from subsequent upstream blocks');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => array_merge(
            searchBlock(1, 30),
            searchBlock(31, 10),
            searchBlock(41, 7),
            searchBlock(48, 3),
        ),
        'pagination' => ['totalCount' => 137],
    ]),
    new ProviderHttpResponse(200, [
        'data' => array_merge(
            capableBulk(range(1, 30)),
            array_map(
                static fn (int $id): array => sampleModDetails($id, [
                    [
                        'id' => 5000 + $id,
                        'isServerPack' => false,
                        'serverPackFileId' => null,
                        'fileStatus' => 4,
                        'isAvailable' => true,
                        'downloadUrl' => 'https://download.curseforge.com/' . $id,
                        'isAlternate' => 0,
                    ],
                ]),
                range(31, 40),
            ),
            array_map(
                static fn (int $id): array => sampleModDetails($id, [
                    [
                        'id' => 5000 + $id,
                        'isServerPack' => false,
                        'serverPackFileId' => null,
                        'fileStatus' => 3,
                        'isAvailable' => true,
                        'downloadUrl' => 'https://download.curseforge.com/' . $id,
                        'isAlternate' => 0,
                    ],
                ]),
                range(48, 50),
            ),
        ),
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$classified = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    limit: 47,
));

if (count($classified->items) !== 47) {
    throw new RuntimeException('Server-pack, main-archive and unknown projects must all be kept.');
}

$slugs = array_map(
    static fn ($item): string => $item->slug,
    $classified->items,
);

foreach (['pack-1', 'pack-31', 'pack-45'] as $expected) {
    if (!in_array($expected, $slugs, true)) {
        throw new RuntimeException("Expected retained project {$expected} missing.");
    }
}

if (in_array('pack-48', $slugs, true) || in_array('pack-50', $slugs, true)) {
    throw new RuntimeException('Client-only projects must be excluded.');
}

$classification = $classified->diagnostics['classification'] ?? [];

if (
    ($classification['server_pack'] ?? 0) !== 30
    || ($classification['main_archive'] ?? 0) !== 10
    || ($classification['unknown'] ?? 0) !== 7
    || ($classification['client_only'] ?? 0) !== 3
) {
    throw new RuntimeException('Classification buckets must be reported accurately.');
}

if (($classified->diagnostics['excluded'] ?? 0) !== 3) {
    throw new RuntimeException('Excluded count must match client-only projects.');
}

if (($classified->diagnostics['exclusion_reasons']['no_server_or_public_archive'] ?? 0) !== 3) {
    throw new RuntimeException('Client-only exclusions must be attributed to a reason.');
}

if ($classified->pagination->total !== 137) {
    throw new RuntimeException('Non-exhausted scans must keep the upstream total even when projects are excluded.');
}

pass('search classifies projects and only excludes client-only ones');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            sampleMod(101, 'small-pack', 4),
        ],
        'pagination' => ['totalCount' => 1],
    ]),
    new ProviderHttpResponse(200, [
        'data' => capableBulk([101]),
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$small = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    limit: 50,
));

if (count($small->items) !== 1) {
    throw new RuntimeException('Short upstream corpora must return their accepted items.');
}

if ($small->pagination->total !== 1 || $small->filteredTotal !== 1) {
    throw new RuntimeException('Exhausted scans must report the exact accepted total.');
}

if (($small->diagnostics['scan_exhausted'] ?? false) !== true) {
    throw new RuntimeException('Short upstream corpora must be flagged as exhausted.');
}

pass('exhausted scans report the exact accepted total');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            sampleMod(111, 'fabric-pack', 4),
            sampleMod(222, 'neo-pack', 6),
        ],
        'pagination' => ['totalCount' => 2],
    ]),
    new ProviderHttpResponse(200, [
        'data' => [
            sampleModDetails(111, [
                ['id' => 501, 'isServerPack' => false, 'serverPackFileId' => 502],
            ]),
        ],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$multi = $provider->search(new CatalogSearchQuery(
    provider: 'curseforge',
    loader: ['fabric', 'forge'],
    limit: 25,
));

$multiRequest = $http->requests[0] ?? null;

if (($multiRequest['query']['modLoaderType'] ?? null) !== 4) {
    throw new RuntimeException(
        'First loader should be applied upstream as modLoaderType.',
    );
}

$multiBulk = $http->requests[1] ?? null;

if (($multiBulk['body'] ?? null) !== ['modIds' => [111]]) {
    throw new RuntimeException(
        'Bulk mods request should only carry the post-filtered ids.',
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

if ($multi->upstreamTotal !== 2) {
    throw new RuntimeException('Upstream total must be preserved alongside the filtered total.');
}

if ($multi->appliedLoaders !== ['fabric', 'forge']) {
    throw new RuntimeException('Applied loaders should echo the full selection.');
}

pass('multi-value loaders apply upstream + provider-side honestly');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            sampleFile(701, 'with server pack', ['serverPackFileId' => 702]),
            sampleFile(703, 'server pack file', ['isServerPack' => true]),
            sampleFile(704, 'client only'),
            sampleFile(705, 'non-public', ['fileStatus' => 3]),
        ],
    ]),
    // Bulk server-pack resolution: one POST /mods/files for every
    // referenced server pack id instead of per-file GETs.
    new ProviderHttpResponse(200, [
        'data' => [
            sampleFile(702, 'dedicated server pack', [
                'isServerPack' => true,
                'modId' => 444,
            ]),
        ],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'curseforge',
    project: '444',
));

$versionRequest = $http->requests[0] ?? null;

if (($versionRequest['url'] ?? '') !== 'https://api.curseforge.com/v1/mods/444/files') {
    throw new RuntimeException('Unexpected files URL.');
}

$serverPackRequest = $http->requests[1] ?? null;

if (($serverPackRequest['url'] ?? '') !== 'https://api.curseforge.com/v1/mods/files') {
    throw new RuntimeException(
        'Server packs should resolve through one bulk /mods/files call.',
    );
}

if (($serverPackRequest['body']['fileIds'] ?? null) !== [702]) {
    throw new RuntimeException(
        'The bulk call should carry exactly the referenced server pack id.',
    );
}

if (count($http->requests) !== 2) {
    throw new RuntimeException(
        'The version list must not make per-file server-pack requests (N+1).',
    );
}

if (count($versions) !== 3) {
    throw new RuntimeException('Unexpected number of listed versions.');
}

$ids = array_map(static fn ($version): string => $version->versionId, $versions);

sort($ids);

if ($ids !== ['702', '703', '704']) {
    throw new RuntimeException('Unexpected resolved version ids.');
}

$mainVersion = array_values(array_filter(
    $versions,
    static fn ($version): bool => $version->versionId === '701',
));

if ($mainVersion !== []) {
    throw new RuntimeException(
        'The client zip must never be listed when a dedicated server pack exists.',
    );
}

$resolved = array_values(array_filter(
    $versions,
    static fn ($version): bool => $version->versionId === '702',
))[0];

if ($resolved->source !== 'curseforge://444@702') {
    throw new RuntimeException(
        'The resolved server pack id should drive the install source.',
    );
}

if (stripos($resolved->versionNumber, 'server pack') === false) {
    throw new RuntimeException(
        'The resolved server pack version label must identify the server pack.',
    );
}

pass('versions resolve dedicated server packs and keep installable main archives');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'data' => [
            sampleFile(8610060, 'Vagrant Saga-1.1.8.zip', [
                'serverPackFileId' => 8610312,
                'fileDate' => '2025-01-01T00:00:00Z',
            ]),
            sampleFile(8610312, 'Vagrant Saga Server Pack-1.1.8.zip', [
                'isServerPack' => true,
                'fileDate' => '2024-12-31T00:00:00Z',
            ]),
        ],
    ]),
    new ProviderHttpResponse(200, [
        'data' => [
            sampleFile(8610312, 'Vagrant Saga Server Pack-1.1.8.zip', [
                'isServerPack' => true,
                'fileDate' => '2024-12-31T00:00:00Z',
                'modId' => 442958,
            ]),
        ],
    ]),
]);
$provider = new CurseForgeCatalogProvider($http, 'secret-key');

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'curseforge',
    project: '442958',
));

if (count($versions) !== 1) {
    throw new RuntimeException(
        'A main file referencing its own server pack must produce a single version.',
    );
}

$version = $versions[0];

if ($version->versionId !== '8610312' || $version->source !== 'curseforge://442958@8610312') {
    throw new RuntimeException(
        'Versions must resolve to the dedicated server pack, not the client zip.',
    );
}

if (stripos($version->versionNumber, 'server pack') === false) {
    throw new RuntimeException(
        'The version label must clearly identify the Vagrant Saga-style server pack.',
    );
}

pass('main-versus-server-pack archives are not confused (Vagrant Saga style)');

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