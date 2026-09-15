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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\MockCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\ModrinthCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogService;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionList;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class StubProviderHttpClient implements ProviderHttpClient
{
    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        throw new ProviderHttpException('Unexpected HTTP request.', 500);
    }
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$http = new StubProviderHttpClient();

$registry = new CatalogProviderRegistry([
    new MockCatalogProvider(),
    new ModrinthCatalogProvider($http),
    new CurseForgeCatalogProvider(new StubProviderHttpClient(), null),
]);

$service = new CatalogService($registry);

$result = $service->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'example-pack',
    gameVersion: '1.21.1',
    loader: 'fabric',
));

if (!$result instanceof CatalogVersionList) {
    throw new RuntimeException('versions did not return a CatalogVersionList.');
}

if ($result->provider !== 'mock') {
    throw new RuntimeException('Active provider not echoed.');
}

if ($result->appliedGameVersions !== ['1.21.1'] || $result->appliedLoaders !== ['fabric']) {
    throw new RuntimeException('Applied filters not echoed.');
}

if (count($result->versions) !== 2) {
    throw new RuntimeException(
        'Mock versions did not delegate: ' . count($result->versions),
    );
}

$sources = array_map(
    static fn ($version): string => $version->source,
    $result->versions,
);

if ($sources !== ['mock://example-pack@1.0.0', 'mock://example-pack@1.1.0']) {
    throw new RuntimeException('Unexpected mock pinned sources.');
}

pass('service versions delegates with applied filter echo');

$data = $result->toArray();

if ($data['provider'] !== 'mock') {
    throw new RuntimeException('toArray provider mismatch.');
}
if (($data['filters']['game_versions'] ?? null) !== ['1.21.1']) {
    throw new RuntimeException('toArray filter echo mismatch.');
}
if (($data['filters']['loaders'] ?? null) !== ['fabric']) {
    throw new RuntimeException('toArray loader echo mismatch.');
}
if (!is_array($data['versions']) || count($data['versions']) !== 2) {
    throw new RuntimeException('toArray versions mismatch.');
}

if (($data['versions'][0]['version_number'] ?? null) !== '1.0.0') {
    throw new RuntimeException('toArray version mapping mismatch.');
}

pass('version list serializes with provider, filters, and versions');

try {
    $service->versions(new CatalogVersionQuery(
        provider: 'curseforge',
        project: 'example-pack',
    ));
    throw new RuntimeException('Unavailable provider versions were accepted.');
} catch (CatalogUnavailableException $exception) {
    if (
        !str_contains(
            $exception->getMessage(),
            'Please add your API key to the .env file',
        )
    ) {
        throw new RuntimeException('Unexpected unavailable message.');
    }
}

pass('unavailable provider versions rejected');

try {
    $service->versions(new CatalogVersionQuery(
        provider: 'somebody-else',
        project: 'example-pack',
    ));
    throw new RuntimeException('Unknown provider versions were accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not supported')) {
        throw new RuntimeException('Unexpected unknown-provider message.');
    }
}

pass('unknown provider versions rejected');

$empty = $service->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'no-such-modpack',
));

if ($empty->versions !== []) {
    throw new RuntimeException('Unknown mock project should return no versions.');
}

pass('unknown project returns empty version list');

echo "All catalog versions service tests passed.\n";
