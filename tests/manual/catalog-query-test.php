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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$defaults = new CatalogSearchQuery();

if ($defaults->provider !== 'modrinth') {
    throw new RuntimeException('Unexpected default provider.');
}
if ($defaults->query !== null || $defaults->gameVersions !== []) {
    throw new RuntimeException('Expected empty default query filters.');
}
if (
    $defaults->loaders !== []
    || $defaults->categories !== []
    || $defaults->environments !== []
) {
    throw new RuntimeException('Expected empty default slug filters.');
}
if ($defaults->sort !== CatalogSort::RELEVANCE) {
    throw new RuntimeException('Unexpected default sort.');
}
if ($defaults->page !== 1 || $defaults->limit !== 20) {
    throw new RuntimeException('Unexpected default pagination.');
}
if ($defaults->offset() !== 0) {
    throw new RuntimeException('Unexpected default offset.');
}
if ($defaults->hasActiveFilters()) {
    throw new RuntimeException('Defaults must not report active filters.');
}

pass('defaults are stable');

$full = new CatalogSearchQuery(
    provider: 'modrinth',
    query: '  prominence  ',
    gameVersion: '1.21.1',
    loader: 'Fabric',
    category: 'ADVENTURE',
    sort: CatalogSort::DOWNLOADS,
    page: 3,
    limit: 25,
);

if ($full->query !== 'prominence') {
    throw new RuntimeException('Query was not trimmed.');
}
if ($full->gameVersions !== ['1.21.1']) {
    throw new RuntimeException('Game version was not preserved.');
}
if ($full->loaders !== ['fabric']) {
    throw new RuntimeException('Loader was not lowercased.');
}
if ($full->categories !== ['adventure']) {
    throw new RuntimeException('Category was not lowercased.');
}
if ($full->sort !== CatalogSort::DOWNLOADS) {
    throw new RuntimeException('Sort was not preserved.');
}
if ($full->offset() !== 50) {
    throw new RuntimeException('Offset should be (3-1) * 25 = 50.');
}
if (!$full->hasActiveFilters()) {
    throw new RuntimeException('Active filters must be reported.');
}

pass('values normalized with defaults applied');

$multi = new CatalogSearchQuery(
    provider: 'modrinth',
    gameVersion: ['1.21.1', '1.20.1'],
    loader: ['fabric', 'neoforge'],
    category: ['adventure', 'technology'],
    environments: ['client', 'server'],
);

if ($multi->gameVersions !== ['1.21.1', '1.20.1']) {
    throw new RuntimeException('Multiple game versions not preserved.');
}
if ($multi->loaders !== ['fabric', 'neoforge']) {
    throw new RuntimeException('Multiple loaders not preserved.');
}
if ($multi->categories !== ['adventure', 'technology']) {
    throw new RuntimeException('Multiple categories not preserved.');
}
if ($multi->environments !== ['client', 'server']) {
    throw new RuntimeException('Multiple environments not preserved.');
}

pass('multi-value filter groups preserved and deduplicated');

$dedupe = new CatalogSearchQuery(
    provider: 'mock',
    category: ['Fabric', 'fabric', 'fabric'],
);

if ($dedupe->categories !== ['fabric']) {
    throw new RuntimeException('Filter values were not normalized/deduped.');
}

pass('filter values normalized and deduplicated');

$emptyQuery = new CatalogSearchQuery(query: '   ');

if ($emptyQuery->query !== null) {
    throw new RuntimeException('Blank query should become null.');
}

pass('blank optional text becomes null');

$invalid = [
    fn () => new CatalogSearchQuery(provider: 'UPPER'),
    fn () => new CatalogSearchQuery(provider: ''),
    fn () => new CatalogSearchQuery(provider: 'mod rinth'),
    fn () => new CatalogSearchQuery(query: str_repeat('a', 129)),
    fn () => new CatalogSearchQuery(gameVersion: '1.21.1;DROP'),
    fn () => new CatalogSearchQuery(gameVersion: '../etc'),
    fn () => new CatalogSearchQuery(gameVersion: ['1.21.1', '../etc']),
    fn () => new CatalogSearchQuery(loader: 'Fabric!'),
    fn () => new CatalogSearchQuery(loader: 'forge/extra'),
    fn () => new CatalogSearchQuery(loader: ['fabric', 'for;ge']),
    fn () => new CatalogSearchQuery(category: 'adven ture'),
    fn () => new CatalogSearchQuery(category: 'a;b'),
    fn () => new CatalogSearchQuery(environments: 'bukkits'),
    fn () => new CatalogSearchQuery(environments: ['client', 'spigot']),
    fn () => new CatalogSearchQuery(
        category: array_map(
            static fn (int $index): string => 'cat-' . $index,
            range(1, 33),
        ),
    ),
    fn () => new CatalogSearchQuery(page: 0),
    fn () => new CatalogSearchQuery(page: 10001),
    fn () => new CatalogSearchQuery(page: 2, limit: 0),
    fn () => new CatalogSearchQuery(page: 2, limit: 51),
];

foreach ($invalid as $index => $builder) {
    try {
        $builder();
        throw new RuntimeException("Invalid query #{$index} was accepted.");
    } catch (InvalidArgumentException $exception) {
        pass("invalid query #{$index} rejected");
    }
}

$versionQuery = new CatalogVersionQuery(
    project: 'example-pack',
    gameVersion: ['1.21.1', '1.20.1'],
    loader: ['fabric', 'neoforge'],
);

if (
    $versionQuery->gameVersions !== ['1.21.1', '1.20.1']
    || $versionQuery->loaders !== ['fabric', 'neoforge']
) {
    throw new RuntimeException('Version query multi-value filters rejected.');
}

pass('catalog version query accepts multi-value filters');

echo "All catalog query tests passed.\n";