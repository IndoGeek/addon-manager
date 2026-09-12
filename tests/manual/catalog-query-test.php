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

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$defaults = new CatalogSearchQuery();

if ($defaults->provider !== 'modrinth') {
    throw new RuntimeException('Unexpected default provider.');
}
if ($defaults->query !== null || $defaults->gameVersion !== null) {
    throw new RuntimeException('Expected null default query filters.');
}
if ($defaults->loader !== null || $defaults->category !== null) {
    throw new RuntimeException('Expected null default slug filters.');
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
if ($full->gameVersion !== '1.21.1') {
    throw new RuntimeException('Game version was not preserved.');
}
if ($full->loader !== 'fabric') {
    throw new RuntimeException('Loader was not lowercased.');
}
if ($full->category !== 'adventure') {
    throw new RuntimeException('Category was not lowercased.');
}
if ($full->sort !== CatalogSort::DOWNLOADS) {
    throw new RuntimeException('Sort was not preserved.');
}
if ($full->offset() !== 50) {
    throw new RuntimeException('Offset should be (3-1) * 25 = 50.');
}

pass('values normalized with defaults applied');

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
    fn () => new CatalogSearchQuery(loader: 'Fabric!'),
    fn () => new CatalogSearchQuery(loader: 'forge/extra'),
    fn () => new CatalogSearchQuery(category: 'adven ture'),
    fn () => new CatalogSearchQuery(category: 'a;b'),
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

echo "All catalog query tests passed.\n";