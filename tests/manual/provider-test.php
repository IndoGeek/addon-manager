<?php

require_once __DIR__ . '/../../app/Models/ModpackMetadata.php';
require_once __DIR__ . '/../../app/Providers/ModpackProvider.php';
require_once __DIR__ . '/../../app/Providers/MockModpackProvider.php';
require_once __DIR__ . '/../../app/Services/ModpackProviderRegistry.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\ModpackProviderRegistry;

$registry = new ModpackProviderRegistry([
    new MockModpackProvider(),
]);

$provider = $registry->resolve('mock://example-pack');
$metadata = $provider->getMetadata('mock://example-pack');

if ($metadata->id !== 'example-pack') {
    throw new RuntimeException('Unexpected modpack ID.');
}

if ($metadata->name !== 'Example Modpack') {
    throw new RuntimeException('Unexpected modpack name.');
}

if ($metadata->version !== '1.0.0') {
    throw new RuntimeException('Unexpected modpack version.');
}

echo "Valid source test: PASS\n";

try {
    $registry->resolve('mock://no-not-now');

    throw new RuntimeException(
        'Invalid mock source was incorrectly accepted.'
    );
} catch (InvalidArgumentException $e) {
    echo "Invalid source test: PASS\n";
}
