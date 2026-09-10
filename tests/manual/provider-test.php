<?php

require_once __DIR__ . '/../../app/Models/ModpackMetadata.php';
require_once __DIR__ . '/../../app/Providers/ModpackProvider.php';
require_once __DIR__ . '/../../app/Providers/MockModpackProvider.php';
require_once __DIR__ . '/../../app/Services/ModpackProviderRegistry.php';

use ModpackInstaller\Providers\MockModpackProvider;
use ModpackInstaller\Services\ModpackProviderRegistry;

$registry = new ModpackProviderRegistry([
    new MockModpackProvider(),
]);

$provider = $registry->resolve('mock://example-pack');
$metadata = $provider->getMetadata('mock://example-pack');

echo "ID: {$metadata->id}\n";
echo "Name: {$metadata->name}\n";
echo "Version: {$metadata->version}\n";
echo "Minecraft: {$metadata->minecraftVersion}\n";
echo "Loader: {$metadata->loader}\n";
echo "Source: {$metadata->source}\n";
