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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
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

    public function post(
        string $url,
        array $body = [],
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
if (($mapped['mock']['development_only'] ?? false) !== true) {
    throw new RuntimeException('Mock catalog must be flagged development-only.');
}
if (($mapped['mock']['state'] ?? '') !== 'available') {
    throw new RuntimeException('Mock catalog state mismatch.');
}
if (($mapped['modrinth']['available'] ?? false) !== true) {
    throw new RuntimeException('Modrinth catalog should be available.');
}
if (($mapped['modrinth']['state'] ?? '') !== 'available') {
    throw new RuntimeException('Modrinth catalog state mismatch.');
}
if (($mapped['modrinth']['development_only'] ?? true) !== false) {
    throw new RuntimeException('Modrinth must not be development-only.');
}
if (($mapped['curseforge']['available'] ?? false) !== false) {
    throw new RuntimeException(
        'CurseForge catalog must not claim availability without a key.',
    );
}
if (($mapped['curseforge']['state'] ?? '') !== 'not_configured') {
    throw new RuntimeException(
        'CurseForge catalog should report not_configured.',
    );
}
if (($mapped['curseforge']['label'] ?? '') !== 'CurseForge') {
    throw new RuntimeException('CurseForge label missing.');
}
if (($mapped['mock']['label'] ?? '') === '') {
    throw new RuntimeException('Mock label missing.');
}

foreach ($providers as $entry) {
    if (!isset($entry['capabilities']['query'], $entry['facets']['categories'])) {
        throw new RuntimeException('Provider capabilities/facets missing.');
    }
}

if (
    ($mapped['curseforge']['capabilities']['environment'] ?? true) !== false
) {
    throw new RuntimeException(
        'CurseForge must not claim an environment capability it lacks.',
    );
}

if ($service->defaultProvider() !== 'modrinth') {
    throw new RuntimeException(
        'Default provider should skip development-only providers: '
            . $service->defaultProvider(),
    );
}

pass('provider listing surfaces availability, state, capabilities and facets');

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

$project = $service->project(new CatalogProjectQuery(
    provider: 'mock',
    project: 'vanilla-tweaks',
));

if (($project->name ?? '') !== 'Vanilla Tweaks Pack') {
    throw new RuntimeException('Project lookup did not resolve mock details.');
}

pass('service project lookup resolves normalized details');

try {
    $service->project(new CatalogProjectQuery(
        provider: 'mock',
        project: 'no-such-pack',
    ));
    throw new RuntimeException('Unknown mock project lookup was accepted.');
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'not found')) {
        throw new RuntimeException('Unexpected unknown-project message.');
    }
}

pass('unknown project lookup rejected cleanly');

try {
    $service->project(new CatalogProjectQuery(
        provider: 'curseforge',
        project: '1234',
    ));
    throw new RuntimeException('Unavailable provider project was accepted.');
} catch (CatalogUnavailableException $exception) {
    if (
        !str_contains(
            $exception->getMessage(),
            'Please add your API key to the .env file',
        )
    ) {
        throw new RuntimeException('Unexpected unavailable project message.');
    }
}

pass('unavailable provider project lookup rejected');

try {
    $service->search(new CatalogSearchQuery(
        provider: 'curseforge',
    ));
    throw new RuntimeException('Unavailable provider search was accepted.');
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

$stub = new CurseForgeCatalogProvider(new StubProviderHttpClient(), 'configured-but-stub');

try {
    $stub->search(new CatalogSearchQuery());
    throw new RuntimeException(
        'CurseForge stub returned results even when configured.',
    );
} catch (CatalogUnavailableException $exception) {
    if (!str_contains($exception->getMessage(), 'temporarily unavailable')) {
        throw new RuntimeException('CurseForge stub unavailable message incorrect.');
    }
}

pass('CurseForge stub never returns results even when configured');

$unconfiguredStub = new CurseForgeCatalogProvider(new StubProviderHttpClient(), null);

try {
    $unconfiguredStub->search(new CatalogSearchQuery());
    throw new RuntimeException(
        'Unconfigured CurseForge stub returned a result.',
    );
} catch (CatalogUnavailableException $exception) {
    if (
        !str_contains(
            $exception->getMessage(),
            'Please add your API key to the .env file',
        )
    ) {
        throw new RuntimeException('CurseForge config message incorrect.');
    }
}

pass('CurseForge stub reports missing configuration');

echo "All catalog service tests passed.\n";