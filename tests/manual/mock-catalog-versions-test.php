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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$provider = new MockCatalogProvider();

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'example-pack',
));

if (count($versions) !== 2) {
    throw new RuntimeException('Expected two example-pack versions.');
}

$first = $versions[0];

if ($first->projectSlug !== 'example-pack') {
    throw new RuntimeException('Project slug not filled.');
}

if ($first->projectName !== 'Example Modpack') {
    throw new RuntimeException('Project name not filled.');
}

if ($first->versionNumber !== '1.0.0' || $first->versionId !== '1.0.0') {
    throw new RuntimeException('Version identity mismatch.');
}

if ($first->source !== 'mock://example-pack@1.0.0') {
    throw new RuntimeException('Pinned source mismatch.');
}

if ($first->gameVersions !== ['1.21.1']) {
    throw new RuntimeException('Game versions mismatch.');
}

if ($first->loaders !== ['fabric']) {
    throw new RuntimeException('Loaders mismatch.');
}

pass('mock versions map to pinned installable sources');

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'vanilla-tweaks',
    gameVersion: '1.20.1',
    loader: 'fabric',
));

if (count($versions) !== 2) {
    throw new RuntimeException(
        'Both vanilla-tweaks versions support 1.20.1 fabric: ' . count($versions),
    );
}

$numbers = array_map(
    static fn ($version): string => $version->versionNumber,
    $versions,
);

if ($numbers !== ['2.3.0', '2.4.0']) {
    throw new RuntimeException('Unexpected filtered version numbers.');
}

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'vanilla-tweaks',
    loader: 'forge',
));

if ($versions !== []) {
    throw new RuntimeException('Forge filter should exclude fabric modpacks.');
}

pass('mock versions respect game version + loader filters');

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'barebones-progression',
));

if (count($versions) !== 3) {
    throw new RuntimeException('Expected three barebones versions.');
}

if ($versions[2]->source !== 'mock://barebones-progression@3.1.0') {
    throw new RuntimeException('Latest barebones pin mismatch.');
}

$versions = $provider->versions(new CatalogVersionQuery(
    provider: 'mock',
    project: 'unknown-modpack',
));

if ($versions !== []) {
    throw new RuntimeException('Unknown project should yield no versions.');
}

pass('mock versions handle unknown projects and ordering');

$installProvider = new MockModpackProvider();

if (!$installProvider->supports('mock://example-pack')) {
    throw new RuntimeException('Unpinned mock source must stay supported.');
}

if (!$installProvider->supports('mock://example-pack@1.0.0')) {
    throw new RuntimeException('Pinned mock source not supported.');
}

if (!$installProvider->supports('mock://example-pack@1.1.0')) {
    throw new RuntimeException('Second pinned mock source not supported.');
}

if ($installProvider->supports('mock://example-pack@9.9.9')) {
    throw new RuntimeException('Unknown pinned version must be rejected.');
}

if ($installProvider->supports('mock://other-pack@1.0.0')) {
    throw new RuntimeException('Other mock projects must be rejected.');
}

pass('mock install provider validates pinned sources');

$metadata = $installProvider->getMetadata('mock://example-pack@1.1.0');

if ($metadata->version !== '1.1.0') {
    throw new RuntimeException('Pinned metadata version mismatch.');
}

if ($metadata->source !== 'mock://example-pack@1.1.0') {
    throw new RuntimeException('Pinned metadata source mismatch.');
}

if ($metadata->minecraftVersion !== '1.21.1' || $metadata->loader !== 'fabric') {
    throw new RuntimeException('Resolved metadata fields mismatch.');
}

$metadata = $installProvider->getMetadata('mock://example-pack');

if ($metadata->version !== '1.0.0') {
    throw new RuntimeException('Unpinned metadata should fall back to 1.0.0.');
}

if (str_contains($metadata->source, '@')) {
    throw new RuntimeException('Unpinned metadata source must not gain a pin.');
}

$package = $installProvider->getPackage('mock://example-pack@1.0.0');

if (!str_ends_with($package->archivePath, 'fixtures/example-pack.zip')) {
    throw new RuntimeException('Unexpected package archive path.');
}

if ($package->source !== 'mock://example-pack@1.0.0') {
    throw new RuntimeException('Package source mismatch.');
}

$installProvider->cleanup($package);

pass('mock install provider resolves pinned metadata and packages');

echo "All mock catalog versions tests passed.\n";
