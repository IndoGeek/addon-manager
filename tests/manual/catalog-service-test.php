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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogService;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
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
    new CurseForgeCatalogProvider(null),
]);

if (count($registry->all()) !== 3) {
    throw new RuntimeException('Registry should expose all providers.');
}

pass('registry exposes all providers');

foreach ($registry->all() as $provider) {
    if (!$provider instanceof CatalogProvider) {
        throw new RuntimeException('Registry contains non-provider.');
    }
}

pass('all registry entries implement CatalogProvider');

$service = new CatalogService($registry);

$providers = $service->providers();

$mapped = [];

foreach ($providers as $entry) {
    $mapped[(string) $entry['name']] = $entry;
}

if (($mapped['mock']['available'] ?? false) !== true) {
    throw new RuntimeException('Mock catalog should be available.');
}
if (($mapped['modrinth']['available'] ?? false) !== true) {
    throw new RuntimeException('Modrinth catalog should be available.');
}
if (($mapped['curseforge']['available'] ?? false) !== false) {
    throw new RuntimeException(
        'CurseForge catalog must not claim availability (not implemented).',
    );
}
if (($mapped['curseforge']['label'] ?? '') !== 'CurseForge') {
    throw new RuntimeException('CurseForge label missing.');
}
if (($mapped['mock']['label'] ?? '') === '') {
    throw new RuntimeException('Mock label missing.');
}

pass('provider listing surfaces availability flags');

$result = $service->search(new CatalogSearchQuery(
    provider: 'mock',
    query: 'vanilla',
));

if (!$result instanceof CatalogResult) {
    throw new RuntimeException('Search did not return a CatalogResult.');
}

if (($result->items[0]->slug ?? null) !== 'vanilla-tweaks') {
    throw new RuntimeException('Service search did not delegate correctly.');
}

pass('service search delegates to resolved provider');

try {
    $service->search(new CatalogSearchQuery(
        provider: 'curseforge',
    ));
    throw new RuntimeException('Unavailable provider search was accepted.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'not available')) {
        throw new RuntimeException('Unexpected unavailable message.');
    }
}

pass('unavailable provider search rejected');

try {
    $service->search(new CatalogSearchQuery(
        provider: 'somebody-else',
    ));
    throw new RuntimeException('Unknown provider was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not supported')) {
        throw new RuntimeException('Unexpected unknown-provider message.');
    }
}

pass('unknown provider rejected');

$registryMissing = new CatalogProviderRegistry([
    new MockCatalogProvider(),
]);

try {
    $registryMissing->get('modrinth');
    throw new RuntimeException('Unknown registry lookup was accepted.');
} catch (InvalidArgumentException $exception) {
    pass('registry lookup of unknown provider rejected');
}

$stub = new CurseForgeCatalogProvider('configured-but-stub');

try {
    $stub->search(new CatalogSearchQuery());
    throw new RuntimeException(
        'CurseForge stub returned results even when configured.',
    );
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'not available yet')) {
        throw new RuntimeException('CurseForge stub unavailable message incorrect.');
    }
}

pass('CurseForge stub never returns results even when configured');

$unconfiguredStub = new CurseForgeCatalogProvider(null);

try {
    $unconfiguredStub->search(new CatalogSearchQuery());
    throw new RuntimeException(
        'Unconfigured CurseForge stub returned a result.',
    );
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'CURSEFORGE_API_KEY')) {
        throw new RuntimeException('CurseForge config message incorrect.');
    }
}

pass('CurseForge stub reports missing configuration');

echo "All catalog service tests passed.\n";