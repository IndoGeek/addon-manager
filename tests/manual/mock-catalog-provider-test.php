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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\MockCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$provider = new MockCatalogProvider();

if ($provider->name() !== 'mock') {
    throw new RuntimeException('Unexpected provider name.');
}
if (!$provider->available()) {
    throw new RuntimeException('Mock catalog should be available.');
}
if ($provider->state() !== 'available') {
    throw new RuntimeException('Unexpected mock provider state.');
}
if (!$provider->developmentOnly()) {
    throw new RuntimeException('Mock catalog must be flagged development-only.');
}

$mockFacets = $provider->facets();

if (!in_array('fabric', $mockFacets['loaders'], true)) {
    throw new RuntimeException('Mock facets missing loader options.');
}
if (!in_array('adventure', $mockFacets['categories'], true)) {
    throw new RuntimeException('Mock facets missing category options.');
}
if (!in_array('1.19.2', $mockFacets['game_versions'], true)) {
    throw new RuntimeException('Mock facets missing game version options.');
}
if ($mockFacets['environments'] === []) {
    throw new RuntimeException('Mock facets missing environment options.');
}

$mockCapabilities = $provider->capabilities();

if (($mockCapabilities['environment'] ?? false) !== true) {
    throw new RuntimeException('Mock must support environment filters.');
}

pass('mock provider identity reported');

$all = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    page: 1,
    limit: 50,
));

if (count($all->items) !== 3) {
    throw new RuntimeException('Expected 3 mock modpacks.');
}
if ($all->pagination->total !== 3) {
    throw new RuntimeException('Expected total of 3.');
}

$sources = array_map(
    static fn ($item) => $item->source,
    $all->items,
);

if ($sources !== ['mock://example-pack', 'mock://vanilla-tweaks', 'mock://barebones-progression']) {
    throw new RuntimeException('Unexpected mock ordering/sources.');
}

foreach ($all->items as $item) {
    if ($item->provider !== 'mock') {
        throw new RuntimeException('Unexpected item provider.');
    }
    if ($item->downloads !== null || $item->follows !== null) {
        throw new RuntimeException('Mock items must not fake popularity metrics.');
    }
    if ($item->iconUrl !== null || $item->projectUrl !== null) {
        throw new RuntimeException('Mock items must not fake live URLs.');
    }
}

pass('mock catalog returns deterministic labeled items');

$query = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    query: 'vanilla',
    page: 1,
    limit: 20,
));

if (count($query->items) !== 1) {
    throw new RuntimeException('Query filter should narrow to one result.');
}

if (($query->items[0]->slug ?? null) !== 'vanilla-tweaks') {
    throw new RuntimeException('Query matched the wrong modpack.');
}

pass('query filter applied');

$byLoader = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    loader: 'forge',
));

if (count($byLoader->items) !== 1) {
    throw new RuntimeException('Loader filter should narrow to forge modpack.');
}

if (($byLoader->items[0]->slug ?? null) !== 'barebones-progression') {
    throw new RuntimeException('Loader matched wrong modpack.');
}

pass('loader filter applied');

$byVersion = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    gameVersion: '1.20.1',
));

if (count($byVersion->items) !== 1) {
    throw new RuntimeException('Version filter should narrow to one result.');
}

if (($byVersion->items[0]->slug ?? null) !== 'vanilla-tweaks') {
    throw new RuntimeException('Version matched wrong modpack.');
}

pass('game version filter applied');

$byCategory = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    category: 'adventure',
));

if (count($byCategory->items) !== 2) {
    throw new RuntimeException('Category filter should return two results.');
}

pass('category filter applied');

$combined = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    query: 'example',
    gameVersion: '1.20.1',
));

if ($combined->items !== []) {
    throw new RuntimeException('Combined filters should produce empty result.');
}

pass('combined filters compose');

$noMatch = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    query: 'zzz-no-such-pack',
));

if ($noMatch->items !== [] || $noMatch->pagination->total !== 0) {
    throw new RuntimeException('No-match search should be an empty valid result.');
}

if ($noMatch->pagination->hasNext || $noMatch->pagination->hasPrevious) {
    throw new RuntimeException('Empty result pagination should be closed.');
}

pass('no-match search is a valid empty result');

$paged = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    page: 2,
    limit: 2,
));

if (count($paged->items) !== 1) {
    throw new RuntimeException('Page 2 of 2 should yield one item.');
}

if (($paged->items[0]->slug ?? null) !== 'barebones-progression') {
    throw new RuntimeException('Pagination returned wrong slice.');
}

if ($paged->pagination->totalPages !== 2) {
    throw new RuntimeException('Expected 2 total pages.');
}

if (!$paged->pagination->hasPrevious || $paged->pagination->hasNext) {
    throw new RuntimeException('Page 2 pagination flags wrong.');
}

pass('pagination slices deterministic dataset');

if ($all->sort !== 'relevance' || $all->appliedQuery !== null) {
    throw new RuntimeException('Echoed metadata mismatch.');
}

$echo = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    query: 'vanilla',
    category: 'utility',
));

if (
    $echo->appliedQuery !== 'vanilla'
    || $echo->appliedCategories !== ['utility']
    || $echo->appliedGameVersions !== []
    || $echo->appliedEnvironments !== []
) {
    throw new RuntimeException('Applied filters not echoed.');
}

pass('mock result echoes applied filters and sort');

$byEnvironment = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    environments: ['server'],
));

if (count($byEnvironment->items) !== 3) {
    throw new RuntimeException('Server environment should match all mock packs.');
}

$byCombinedEnvironment = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    environments: ['client-and-server'],
));

if (count($byCombinedEnvironment->items) !== 2) {
    throw new RuntimeException(
        'Client-and-server should only match dual-environment packs.',
    );
}

pass('environment filters applied realistically');

$multiCategory = $provider->search(new CatalogSearchQuery(
    provider: 'mock',
    category: ['adventure', 'utility'],
));

if (count($multiCategory->items) !== 3) {
    throw new RuntimeException(
        'Multi-category (OR) should match all tagged packs.',
    );
}

pass('multi-value category filters compose with OR semantics');

$project = $provider->project(new \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery(
    provider: 'mock',
    project: 'barebones-progression',
));

if (($project->name ?? '') !== 'Barebones Progression') {
    throw new RuntimeException('Mock project lookup mismatch.');
}

if (($project->source ?? '') !== 'mock://barebones-progression') {
    throw new RuntimeException('Mock project source mismatch.');
}

pass('mock project lookup returns normalized details');

echo "All mock catalog provider tests passed.\n";