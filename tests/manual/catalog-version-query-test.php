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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

function expectRejected(string $name, callable $run): void
{
    try {
        $run();
        throw new RuntimeException("Expected rejection: {$name}");
    } catch (InvalidArgumentException $exception) {
        pass($name);
    }
}

$query = new CatalogVersionQuery(project: 'example-pack');

if ($query->provider !== 'modrinth') {
    throw new RuntimeException('Unexpected default provider.');
}

pass('defaults resolve to the default provider');

$query = new CatalogVersionQuery(
    provider: 'mock',
    project: 'example-pack',
);

if ($query->project !== 'example-pack') {
    throw new RuntimeException('Unexpected project echo.');
}
if ($query->gameVersions !== [] || $query->loaders !== []) {
    throw new RuntimeException('Optional filters should default to empty.');
}

pass('valid query echoes normalized fields');

$query = new CatalogVersionQuery(
    project: '  Prominence-2_RPG  ',
    gameVersion: '1.21.1',
    loader: 'Fabric',
);

if ($query->project !== 'Prominence-2_RPG') {
    throw new RuntimeException('Project was not trimmed.');
}
if ($query->gameVersions !== ['1.21.1']) {
    throw new RuntimeException('Game version not normalized.');
}
if ($query->loaders !== ['fabric']) {
    throw new RuntimeException('Loader was not lowercased.');
}

pass('project trimmed; loader lowercased');

expectRejected('missing project rejected', static function (): void {
    new CatalogVersionQuery(project: null);
});

expectRejected('blank project rejected', static function (): void {
    new CatalogVersionQuery(project: '   ');
});

expectRejected('invalid project characters rejected', static function (): void {
    new CatalogVersionQuery(project: '../evil');
});

expectRejected('project with whitespace rejected', static function (): void {
    new CatalogVersionQuery(project: 'my pack');
});

expectRejected('oversized project rejected', static function (): void {
    new CatalogVersionQuery(project: str_repeat('a', 65));
});

expectRejected('invalid provider rejected', static function (): void {
    new CatalogVersionQuery(provider: 'Bad Provider', project: 'example-pack');
});

expectRejected('malformed game version rejected', static function (): void {
    new CatalogVersionQuery(project: 'example-pack', gameVersion: '1.x;drop');
});

expectRejected('oversized game version rejected', static function (): void {
    new CatalogVersionQuery(project: 'example-pack', gameVersion: str_repeat('1', 33));
});

expectRejected('malformed loader rejected', static function (): void {
    new CatalogVersionQuery(project: 'example-pack', loader: 'for;ge');
});

expectRejected('oversized loader rejected', static function (): void {
    new CatalogVersionQuery(project: 'example-pack', loader: str_repeat('a', 33));
});

echo "All catalog version query tests passed.\n";
