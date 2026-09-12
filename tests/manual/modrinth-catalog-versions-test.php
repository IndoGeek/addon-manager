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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
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

function sampleVersions(): array
{
    return [
        [
            'id' => 'abc123',
            'project_id' => 'AANobbMI',
            'name' => 'Prominence 2 RPG 2.0.1',
            'version_number' => '2.0.1',
            'game_versions' => ['1.21.1'],
            'loaders' => ['fabric'],
            'date_published' => '2025-01-02T10:00:00Z',
            'date_modified' => '2025-01-03T10:00:00Z',
            'downloads' => 4200,
            'status' => 'listed',
        ],
        [
            'id' => 'def456',
            'version_number' => '2.1.0',
            'name' => 'Beta build',
            'game_versions' => ['1.21.1'],
            'loaders' => ['fabric'],
            'date_published' => '',
            'date_modified' => null,
            'downloads' => '77',
            'status' => 'draft',
        ],
        [
            'id' => 'ghi789',
            'project_id' => 'AANobbMI',
            'version_number' => '1.5.0',
            'name' => 'Prominence 2 RPG 1.5.0',
            'game_versions' => ['1.20','1.21.1'],
            'loaders' => ['forge','neoforge'],
            'downloads' => 8123,
            'status' => 'listed',
        ],
    ];
}

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, sampleVersions()),
]);
$provider = new ModrinthCatalogProvider($http);

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'modrinth',
    project: 'prominence-2-rpg',
    gameVersion: '1.21.1',
    loader: 'fabric',
));

$request = $http->requests[0] ?? null;

if ($request === null) {
    throw new RuntimeException('No HTTP request was made.');
}

$expectedUrl = 'https://api.modrinth.com/v2/project/prominence-2-rpg/version';

if ($request['url'] !== $expectedUrl) {
    throw new RuntimeException('Unexpected versions URL: ' . $request['url']);
}

if (($request['query']['game_versions'] ?? null) !== '["1.21.1"]') {
    throw new RuntimeException('game_versions filter not JSON encoded.');
}

if (($request['query']['loaders'] ?? null) !== '["fabric"]') {
    throw new RuntimeException('loaders filter not JSON encoded.');
}

pass('version request targets project versions endpoint with encoded filters');

if (count($versions) !== 2) {
    throw new RuntimeException('Draft status should have been filtered.');
}

$first = $versions[0];

if ($first->provider !== 'modrinth') {
    throw new RuntimeException('Unexpected version provider.');
}

if ($first->projectId !== 'prominence-2-rpg') {
    throw new RuntimeException('Unexpected project id echo.');
}

if ($first->versionId !== 'abc123') {
    throw new RuntimeException('Unexpected version id.');
}

if ($first->versionNumber !== '2.0.1') {
    throw new RuntimeException('Unexpected version number.');
}

if ($first->versionName !== 'Prominence 2 RPG 2.0.1') {
    throw new RuntimeException('Unexpected version name.');
}

if ($first->gameVersions !== ['1.21.1']) {
    throw new RuntimeException('Unexpected game versions.');
}

if ($first->loaders !== ['fabric']) {
    throw new RuntimeException('Unexpected loaders.');
}

if ($first->datePublished !== '2025-01-02T10:00:00Z') {
    throw new RuntimeException('Unexpected publish date.');
}

if ($first->dateModified !== '2025-01-03T10:00:00Z') {
    throw new RuntimeException('Unexpected modified date.');
}

if ($first->downloads !== 4200) {
    throw new RuntimeException('Unexpected downloads.');
}

if ($first->source !== 'modrinth://prominence-2-rpg@abc123') {
    throw new RuntimeException('Unexpected pinned source.');
}

pass('listed version mapped with pinned installable source');

$second = $versions[1];

if ($second->datePublished !== null) {
    throw new RuntimeException('Blank dates should be null.');
}

if ($second->downloads !== 8123) {
    throw new RuntimeException('Unexpected downloads on second version.');
}

if ($second->projectSlug !== null || $second->projectName !== null) {
    throw new RuntimeException('Slug/name should be null on version map.');
}

pass('nullable fields mapped defensively');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, []),
]);
$provider = new ModrinthCatalogProvider($http);

$empty = $provider->versions(new CatalogVersionQuery(
    project: 'nothing',
));

if ($empty !== []) {
    throw new RuntimeException('Empty version list should be valid.');
}

pass('empty version list is a valid response, not an error');

$invalidBodies = [
    ['nope', 5],
    [['ok'], 'bad', ['ok']],
];

foreach ($invalidBodies as $index => $body) {
    $http = new FakeProviderHttpClient([
        new ProviderHttpResponse(200, $body),
    ]);
    $provider = new ModrinthCatalogProvider($http);

    try {
        $provider->versions(new CatalogVersionQuery(project: 'example-pack'));
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
    $provider->versions(new CatalogVersionQuery(project: 'example-pack'));
    throw new RuntimeException('429 was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'rate limited')) {
        throw new RuntimeException('Unexpected rate-limit message.');
    }
}

pass('rate limited versions mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 500 response.', 500),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->versions(new CatalogVersionQuery(project: 'example-pack'));
    throw new RuntimeException('500 was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'temporarily unavailable')) {
        throw new RuntimeException('Unexpected 5xx message.');
    }
}

pass('upstream 5xx versions mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('Unable to reach the provider.'),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->versions(new CatalogVersionQuery(project: 'example-pack'));
    throw new RuntimeException('Unreachable provider was not handled.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'Unable to reach')) {
        throw new RuntimeException('Unexpected unreachable message.');
    }
}

pass('unreachable versions mapped to unavailable (503)');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 400 response.', 400),
]);
$provider = new ModrinthCatalogProvider($http);

try {
    $provider->versions(new CatalogVersionQuery(project: 'example-pack'));
    throw new RuntimeException('400 was not handled.');
} catch (CatalogProviderException $exception) {
    if (!str_contains($exception->getMessage(), 'rejected')) {
        throw new RuntimeException('Unexpected rejected message.');
    }
}

pass('upstream 4xx versions mapped to provider failure (502)');

echo "All Modrinth catalog versions tests passed.\n";
