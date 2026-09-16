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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\CurseForgeProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\ModpackProviderRegistry;
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

final class StubDownloader implements Downloader
{
    public function download(string $url): string
    {
        throw new RuntimeException('Unexpected download.');
    }

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void
    {
    }

    public function markForCleanup(string $path): void
    {
    }
}

function expectProvider(
    ModpackProviderRegistry $registry,
    string $source,
    string $expectedClass,
): void {
    $provider = $registry->resolve($source);

    if (!$provider instanceof $expectedClass) {
        throw new RuntimeException(
            "Expected {$expectedClass} for {$source}, got "
            . get_class($provider),
        );
    }

    echo "PASS: {$source} resolves to {$expectedClass}\n";
}

$http = new StubProviderHttpClient();
$downloader = new StubDownloader();

$registry = new ModpackProviderRegistry([
    new MockModpackProvider(),
    new ModrinthProvider(
        $http,
        $downloader,
        sys_get_temp_dir(),
    ),
    new CurseForgeProvider(
        $http,
        $downloader,
        null,
    ),
]);

expectProvider($registry, 'mock://example-pack', MockModpackProvider::class);
expectProvider($registry, 'modrinth://prominence-2-rpg', ModrinthProvider::class);
expectProvider($registry, 'https://modrinth.com/modpack/prominence-2-rpg', ModrinthProvider::class);
expectProvider($registry, 'curseforge://314768', CurseForgeProvider::class);

try {
    $registry->resolve('mock://no-not-now');
    throw new RuntimeException('Arbitrary mock source was accepted.');
} catch (InvalidArgumentException $exception) {
    echo "PASS: arbitrary mock source rejected\n";
}

try {
    $registry->resolve('garbage://nope');
    throw new RuntimeException('Unsupported source was accepted.');
} catch (InvalidArgumentException $exception) {
    echo "PASS: unsupported source rejected\n";
}

echo "All provider registry tests passed.\n";