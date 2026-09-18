<?php

// Tests for the mod (single-file content) version window: - ModVersionCatalog version mapping, dependency recom...

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\ModVersionCatalog;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeCatalogHttp implements ProviderHttpClient
{
    /** @var array<int, array{url: string, query: array}> */
    public array $requests = [];

    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(private array $handlers) {}

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->requests[] = [
            'url' => $url,
            'query' => $query,
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
        throw new RuntimeException('Unexpected POST.');
    }
}

function passCatalog(string $name): void
{
    echo "PASS: {$name}\n";
}

$versionsPayload = [
    [
        'id' => 'versionAAA',
        'version_number' => '1.2.0',
        'status' => 'listed',
        'date_published' => '2026-01-02T00:00:00Z',
        'downloads' => 500,
        'game_versions' => ['1.20.1'],
        'loaders' => ['fabric', 'quilt'],
        'files' => [
            [
                'primary' => true,
                'filename' => 'example-mod-1.2.0.jar',
                'url' => 'https://cdn.modrinth.com/x/1.2.0.jar',
                'size' => 123456,
            ],
        ],
        'dependencies' => [
            [
                'version_id' => null,
                'project_id' => 'depReq01',
                'dependency_type' => 'required',
            ],
            [
                'version_id' => null,
                'project_id' => 'depOpt01',
                'dependency_type' => 'optional',
            ],
            [
                'version_id' => null,
                'project_id' => 'depBad01',
                'dependency_type' => 'incompatible',
            ],
        ],
    ],
    [
        'id' => 'versionBBB',
        'version_number' => '1.1.0',
        'status' => 'draft', // excluded
        'date_published' => '2025-01-02T00:00:00Z',
        'downloads' => 10,
        'game_versions' => ['1.19.4'],
        'loaders' => ['forge'],
        'files' => [],
        'dependencies' => [],
    ],
];

$http = new FakeCatalogHttp([
    new ProviderHttpResponse(200, $versionsPayload),
    // Batch project lookup for the two recommendable dependencies.
    new ProviderHttpResponse(200, [
        [
            'id' => 'depReq01',
            'title' => 'Required Lib',
            'slug' => 'required-lib',
            'icon_url' => 'https://cdn.modrinth.com/req.png',
        ],
        [
            'id' => 'depOpt01',
            'title' => 'Optional Lib',
            'slug' => null,
            'icon_url' => '',
        ],
    ]),
]);

$catalog = new ModVersionCatalog($http);

$result = $catalog->versions('example-mod');

if (count($result['versions']) !== 1) {
    throw new RuntimeException('Draft version must be excluded.');
}

$version = $result['versions'][0];

if ($version['version_number'] !== '1.2.0') {
    throw new RuntimeException('Unexpected version number.');
}

if ($version['loaders'] !== ['fabric', 'quilt']) {
    throw new RuntimeException('Loader options missing.');
}

if ($version['game_versions'] !== ['1.20.1']) {
    throw new RuntimeException('Game version options missing.');
}

if ($version['file_size'] !== 123456) {
    throw new RuntimeException('Primary file size missing.');
}

if ($version['source'] !== 'modrinth://example-mod@versionAAA') {
    throw new RuntimeException('Source encoding wrong: ' . $version['source']);
}

$dependencyTypes = [];

foreach ($version['dependencies'] as $dependency) {
    $dependencyTypes[$dependency['project_id']] = $dependency;
}

$required = $dependencyTypes['depReq01'] ?? [];

if (
    ($required['title'] ?? '') !== 'Required Lib'
    || ($required['type'] ?? '') !== 'required'
    || ($required['slug'] ?? '') !== 'required-lib'
    || ($required['icon_url'] ?? '') !== 'https://cdn.modrinth.com/req.png'
) {
    throw new RuntimeException('Required dependency card data wrong.');
}

$optional = $dependencyTypes['depOpt01'] ?? [];

if (
    ($optional['title'] ?? '') !== 'Optional Lib'
    || ($optional['type'] ?? '') !== 'optional'
    || $optional['slug'] !== null
    || $optional['icon_url'] !== null
) {
    throw new RuntimeException('Optional dependency card data wrong.');
}

if (isset($dependencyTypes['depBad01'])) {
    throw new RuntimeException('Incompatible dependency must not be recommended.');
}

passCatalog('versions mapped with loaders, game versions and dependency cards');

// The card lookup is a single batch request.
$titleRequest = $http->requests[1] ?? null;

if (
    $titleRequest === null
    || !str_ends_with($titleRequest['url'], '/projects')
) {
    throw new RuntimeException('Dependency titles must be batch-resolved.');
}

passCatalog('dependency cards batch-resolved');

// Malformed project ids are rejected.
try {
    $catalog->versions('../escape');
    throw new RuntimeException('Malformed project id accepted.');
} catch (CatalogProviderException) {
    passCatalog('malformed project id rejected');
}

echo "\nAll mod version catalog tests passed.\n";
