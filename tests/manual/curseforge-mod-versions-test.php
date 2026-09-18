<?php

// CurseForge single-file content wiring:
// - CurseForgeModVersionCatalog normalizes a mod's files into the same shape
//   the version window consumes for Modrinth (loaders, game versions, sources
//   and dependencies), and refuses files that cannot be downloaded.
// - CatalogVersionFileResolver turns a "curseforge://mod@file" source into the
//   concrete CDN URL, and rejects anything that is not on CurseForge's own CDN.

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix =
        'Pterodactyl\\BlueprintFramework\\Extensions\\modpackinstaller\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $projectRoot . '/app/'
        . str_replace('\\', '/', substr($class, strlen($prefix)))
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionFileResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CurseForgeModVersionCatalog;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class QueueHttpClient implements ProviderHttpClient
{
    /** @var array<int, array{method: string, url: string, query: array, body: array, headers: array}> */
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
        return $this->shift('GET', $url, $query, [], $headers);
    }

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
        return $this->shift('POST', $url, [], $body, $headers);
    }

    private function shift(
        string $method,
        string $url,
        array $query,
        array $body,
        array $headers,
    ): ProviderHttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'query' => $query,
            'body' => $body,
            'headers' => $headers,
        ];

        $handler = array_shift($this->handlers);

        if ($handler instanceof Throwable) {
            throw $handler;
        }

        if (!$handler instanceof ProviderHttpResponse) {
            throw new RuntimeException('Unexpected fake HTTP request: ' . $url);
        }

        return $handler;
    }
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

function expectFailure(callable $call, string $needle, string $label): void
{
    try {
        $call();
    } catch (CatalogProviderException $exception) {
        if (!str_contains($exception->getMessage(), $needle)) {
            throw new RuntimeException(
                "{$label}: unexpected message: {$exception->getMessage()}",
            );
        }

        return;
    }

    throw new RuntimeException("{$label}: expected a failure.");
}

// @return array<string, mixed>
function sampleFile(int $id, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'modId' => 238222,
        'displayName' => 'File ' . $id,
        'fileName' => 'example-' . $id . '.jar',
        'fileDate' => '2024-01-0' . (($id % 9) + 1) . 'T00:00:00Z',
        'fileLength' => 1024 * $id,
        'downloadCount' => 100 * $id,
        'fileStatus' => 4,
        'isAvailable' => true,
        'downloadUrl' => 'https://edge.forgecdn.net/files/1/' . $id . '/example.jar',
        'gameVersions' => ['1.20.1', 'Fabric'],
        'dependencies' => [],
        'hashes' => ['sha1' => str_repeat('a', 40)],
    ], $extra);
}

$modFiles = new ProviderHttpResponse(200, [
    'data' => [
        // Newest file: Fabric, 1.20.1, with one required and one optional dep.
        sampleFile(300, [
            'fileDate' => '2024-05-01T00:00:00Z',
            'gameVersions' => ['1.20.1', 'Fabric'],
            'dependencies' => [
                ['modId' => 306612, 'relationType' => 3],
                ['modId' => 111111, 'relationType' => 2],
                ['modId' => 222222, 'relationType' => 1],
            ],
        ]),
        // A Forge file on another game version.
        sampleFile(200, [
            'fileDate' => '2024-02-01T00:00:00Z',
            'gameVersions' => ['1.19.2', 'Forge'],
        ]),
        // Files that can never be installed must not appear as versions.
        sampleFile(400, [
            'fileDate' => '2024-06-01T00:00:00Z',
            'downloadUrl' => null,
        ]),
        sampleFile(500, [
            'fileDate' => '2024-07-01T00:00:00Z',
            'isAvailable' => false,
        ]),
        sampleFile(600, [
            'fileDate' => '2024-08-01T00:00:00Z',
            'fileStatus' => 1,
        ]),
    ],
]);

$dependencyProjects = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 306612,
            'name' => 'Fabric API',
            'slug' => 'fabric-api',
            'logo' => ['thumbnailUrl' => 'https://cdn.example/fabric-api.png'],
        ],
        [
            'id' => 111111,
            'name' => 'Optional Helper',
            'slug' => 'optional-helper',
            'logo' => ['url' => 'https://cdn.example/optional.png'],
        ],
    ],
]);

$http = new QueueHttpClient([$modFiles, $dependencyProjects]);

$catalog = new CurseForgeModVersionCatalog($http, 'secret-key');

$payload = $catalog->versions('238222');

if ($payload['provider'] !== 'curseforge' || $payload['project'] !== '238222') {
    throw new RuntimeException('Unexpected catalog identity.');
}

$ids = array_column($payload['versions'], 'version_id');

// Only the two installable files, newest first.
if ($ids !== ['300', '200']) {
    throw new RuntimeException(
        'Unavailable files were not dropped: ' . json_encode($ids),
    );
}

if (
    !array_key_exists('unavailable_reason', $payload)
    || $payload['unavailable_reason'] !== null
) {
    throw new RuntimeException(
        'A project with installable files must report no unavailable reason.'
    );
}

pass('uninstallable CurseForge files are dropped, newest first');

// A project whose author turned off automated downloads: CurseForge returns
// the files with downloadUrl = null (verified live on EssentialsX, whose
// allowModDistribution is false). The list is empty, but the reason must
// travel so the UI can say why instead of "no versions".
$withheldFiles = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 9001,
            'fileName' => 'withheld-1.0.jar',
            'displayName' => 'withheld-1.0',
            'gameVersions' => ['1.21.1'],
            'fileStatus' => 4,
            'isAvailable' => true,
            'downloadUrl' => null,
        ],
    ],
]);

$withheld = (new CurseForgeModVersionCatalog(
    new QueueHttpClient([$withheldFiles]),
    'secret-key',
))->versions('93271');

if ($withheld['versions'] !== []) {
    throw new RuntimeException('A withheld file must never be offered.');
}

if (($withheld['unavailable_reason'] ?? null) !== 'distribution_disabled') {
    throw new RuntimeException(
        'A withheld download must be reported as distribution_disabled, got '
            . json_encode($withheld['unavailable_reason'] ?? null),
    );
}

pass('a withheld download reports why instead of an empty list');

// A project with no public files at all is not a distribution problem.
$unlistedFiles = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 9002,
            'fileName' => 'pending-1.0.jar',
            'gameVersions' => ['1.21.1'],
            'fileStatus' => 1,
            'isAvailable' => true,
            'downloadUrl' => 'https://edge.forgecdn.net/files/9002/pending.jar',
        ],
    ],
]);

$unlisted = (new CurseForgeModVersionCatalog(
    new QueueHttpClient([$unlistedFiles]),
    'secret-key',
))->versions('123');

if ($unlisted['versions'] !== [] || $unlisted['unavailable_reason'] !== null) {
    throw new RuntimeException(
        'A non-public file must be dropped without claiming a distribution ban.'
    );
}

pass('a non-public file is dropped without a misleading reason');

$latest = $payload['versions'][0];

if ($latest['version_number'] !== 'File 300') {
    throw new RuntimeException('Version label was not normalized.');
}

if ($latest['loaders'] !== ['fabric'] || $latest['game_versions'] !== ['1.20.1']) {
    throw new RuntimeException(
        'Loader and game version were not split apart: '
            . json_encode([$latest['loaders'], $latest['game_versions']]),
    );
}

if ($latest['source'] !== 'curseforge://238222@300') {
    throw new RuntimeException('Source was not built: ' . $latest['source']);
}

if ($latest['file_size'] !== 307200) {
    throw new RuntimeException('File size was not carried over.');
}

pass('loaders and game versions are separated and the source is built');

$dependencies = $latest['dependencies'];

if (count($dependencies) !== 2) {
    throw new RuntimeException(
        'Dependency relations were not filtered: '
            . json_encode($dependencies),
    );
}

if (
    $dependencies[0]['project_id'] !== '306612'
    || $dependencies[0]['type'] !== 'required'
    || $dependencies[0]['title'] !== 'Fabric API'
    || $dependencies[0]['slug'] !== 'fabric-api'
    || $dependencies[0]['icon_url'] !== 'https://cdn.example/fabric-api.png'
) {
    throw new RuntimeException(
        'Required dependency was not resolved: ' . json_encode($dependencies[0]),
    );
}

if (
    $dependencies[1]['type'] !== 'optional'
    || $dependencies[1]['icon_url'] !== 'https://cdn.example/optional.png'
) {
    throw new RuntimeException(
        'Optional dependency was not resolved: ' . json_encode($dependencies[1]),
    );
}

pass('required and optional dependencies resolve to titled cards');

// The dependency lookup is one batched POST carrying every referenced mod id.
$lookup = $http->requests[1] ?? null;

if (
    $lookup === null
    || $lookup['method'] !== 'POST'
    || !str_ends_with($lookup['url'], '/mods')
    || ($lookup['body']['modIds'] ?? null) !== [306612, 111111]
) {
    throw new RuntimeException(
        'Dependency lookup was not batched: ' . json_encode($lookup),
    );
}

// The header must be a raw "Name: value" LINE: the HTTP client hands these
// straight to CURLOPT_HTTPHEADER, which uses the array's values. A
// name => value map would be sent as a bare value, the API key would never
// reach CurseForge and every request would come back 403.
if (($lookup['headers'][0] ?? null) !== 'X-Api-Key: secret-key') {
    throw new RuntimeException(
        'Dependency lookup did not send the API key as a header line: '
            . json_encode($lookup['headers']),
    );
}

pass('dependency titles come from one batched, authenticated lookup');

// The version list request must stay on the mods endpoints and identify
// itself too, or CurseForge answers 403.
$list = $http->requests[0] ?? null;

if (
    $list === null
    || $list['method'] !== 'GET'
    || !str_ends_with($list['url'], '/mods/238222/files')
    || ($list['query']['pageSize'] ?? null) !== 50
    || ($list['headers'][0] ?? null) !== 'X-Api-Key: secret-key'
) {
    throw new RuntimeException(
        'Version list request was malformed: ' . json_encode($list),
    );
}

pass('version list is fetched from the mods endpoint with the API key');

// A dependency lookup that fails must not break the version list.
$resilient = new CurseForgeModVersionCatalog(
    new QueueHttpClient([$modFiles, new ProviderHttpException('boom')]),
    'secret-key',
);

$resilientPayload = $resilient->versions('238222');

if (
    ($resilientPayload['versions'][0]['dependencies'][0]['title'] ?? null)
    !== '306612'
) {
    throw new RuntimeException(
        'A failed dependency lookup must fall back to the id.',
    );
}

pass('a failed dependency lookup degrades to ids instead of failing');

expectFailure(
    fn () => (new CurseForgeModVersionCatalog($http, null))->versions('238222'),
    'add your API key',
    'unconfigured catalog',
);

expectFailure(
    fn () => (new CurseForgeModVersionCatalog($http, 'key'))->versions('not-a-mod'),
    'Invalid project parameter',
    'non-numeric project',
);

pass('unconfigured and malformed requests are rejected clearly');

// ── File resolution ──────────────────────────────────────────────────

$fileResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 300,
        'fileName' => 'example-300.jar',
        'fileLength' => 307200,
        'isAvailable' => true,
        'downloadUrl' => 'https://edge.forgecdn.net/files/1/300/example.jar',
        'hashes' => ['sha1' => str_repeat('b', 40)],
    ],
]);

$resolverHttp = new QueueHttpClient([$fileResponse]);

$resolved = (new CatalogVersionFileResolver($resolverHttp, 'secret-key'))
    ->resolve('curseforge', '238222', '300');

if (
    $resolved['url'] !== 'https://edge.forgecdn.net/files/1/300/example.jar'
    || $resolved['filename'] !== 'example-300.jar'
    || $resolved['size'] !== 307200
    || $resolved['sha1'] !== str_repeat('b', 40)
) {
    throw new RuntimeException('CurseForge file was not resolved: ' . json_encode($resolved));
}

$request = $resolverHttp->requests[0] ?? null;

if (
    $request === null
    || !str_ends_with($request['url'], '/mods/238222/files/300')
    || ($request['headers'][0] ?? null) !== 'X-Api-Key: secret-key'
) {
    throw new RuntimeException(
        'Resolver did not call the file endpoint. '
            . json_encode($request['headers']),
    );
}

pass('CurseForge versions resolve to a trusted CDN file');

// Modrinth keeps working through the same resolver.
$modrinthResponse = new ProviderHttpResponse(200, [
    'id' => 'abc12345',
    'files' => [
        [
            'primary' => true,
            'filename' => 'example.jar',
            'url' => 'https://cdn.modrinth.com/data/abc/versions/abc12345/example.jar',
            'size' => 2048,
            'hashes' => ['sha1' => str_repeat('c', 40)],
        ],
    ],
]);

$modrinth = (new CatalogVersionFileResolver(
    new QueueHttpClient([$modrinthResponse]),
))->resolve('modrinth', 'example-mod', 'abc12345');

if ($modrinth['filename'] !== 'example.jar' || $modrinth['size'] !== 2048) {
    throw new RuntimeException('Modrinth resolution regressed.');
}

pass('Modrinth resolution is unchanged by the new provider');

// Only CurseForge's own CDN may be fetched, and a file that forbids
// automated distribution must say so instead of downloading something else.
$untrusted = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 300,
        'fileName' => 'example.jar',
        'isAvailable' => true,
        'downloadUrl' => 'https://evil.example/mods/example.jar',
    ],
]);

expectFailure(
    fn () => (new CatalogVersionFileResolver(
        new QueueHttpClient([$untrusted]),
        'secret-key',
    ))->resolve('curseforge', '238222', '300'),
    'not a trusted CurseForge CDN address',
    'untrusted host',
);

$withheld = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 300,
        'fileName' => 'example.jar',
        'isAvailable' => true,
        'downloadUrl' => null,
    ],
]);

expectFailure(
    fn () => (new CatalogVersionFileResolver(
        new QueueHttpClient([$withheld]),
        'secret-key',
    ))->resolve('curseforge', '238222', '300'),
    'does not allow automated downloads',
    'withheld file',
);

$unavailable = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 300,
        'fileName' => 'example.jar',
        'isAvailable' => false,
        'downloadUrl' => 'https://edge.forgecdn.net/files/1/300/example.jar',
    ],
]);

expectFailure(
    fn () => (new CatalogVersionFileResolver(
        new QueueHttpClient([$unavailable]),
        'secret-key',
    ))->resolve('curseforge', '238222', '300'),
    'no longer available',
    'removed file',
);

expectFailure(
    fn () => (new CatalogVersionFileResolver(
        new QueueHttpClient([$fileResponse]),
        null,
    ))->resolve('curseforge', '238222', '300'),
    'add your API key',
    'unconfigured resolver',
);

expectFailure(
    fn () => (new CatalogVersionFileResolver(
        new QueueHttpClient([$fileResponse]),
        'secret-key',
    ))->resolve('curseforge', '238222', 'not-a-file'),
    'selected version is invalid',
    'malformed version id',
);

pass('unsafe, withheld and malformed CurseForge files are refused');

echo "\nAll CurseForge mod version tests passed.\n";
