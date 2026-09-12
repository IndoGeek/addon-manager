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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\UnsupportedModpackPackageException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @var array<int, array{url: string, query: array, headers: array}> */
    public array $requests = [];

    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(private array $handlers)
    {
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->requests[] = [
            'url' => $url,
            'query' => $query,
            'headers' => $headers,
        ];

        $handler = array_shift($this->handlers);

        if ($handler instanceof Throwable) {
            throw $handler;
        }

        if (!$handler instanceof ProviderHttpResponse) {
            throw new RuntimeException('Unexpected fake HTTP handler.');
        }

        return $handler;
    }
}

final class FakeDownloader implements Downloader
{
    public int $calls = 0;

    public function __construct(private readonly string $archivePath)
    {
    }

    public function download(string $url): string
    {
        $this->calls++;

        return $this->archivePath;
    }
}

function buildZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

const SECRET_KEY = 'SUPER-SECRET-CF-KEY';

$temporaryRoot = sys_get_temp_dir() . '/curseforge-provider-test';
@mkdir($temporaryRoot, 0750, true);

$projectResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 314768,
        'name' => 'Prominence 2 RPG',
        'summary' => 'A dark fantasy modpack.',
        'links' => [
            'websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/prominence-2-rpg',
            'iconUrl' => 'https://cdn.example/icon.png',
        ],
    ],
]);

$filesResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'displayName' => 'V1.0.0',
            'fileName' => 'Prominence-2-RPG-1.0.0.zip',
            'fileDate' => '2024-01-01T00:00:00Z',
            'releaseType' => 1,
            'gameVersions' => ['Fabric', '1.20.4'],
            'downloadUrl' => 'https://cdn.example/prominence.zip',
        ],
    ],
]);

$provider = new CurseForgeProvider(
    new FakeProviderHttpClient([]),
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

$validSources = ['curseforge://314768', 'curseforge://999'];

foreach ($validSources as $source) {
    if (!$provider->supports($source)) {
        throw new RuntimeException("Expected support for: {$source}");
    }
}

pass('valid source syntax accepted');

$invalidSources = [
    'curseforge://',
    'curseforge://abc',
    'curseforge://1.5',
    'curseforge://123/extra',
    'https://www.curseforge.com/minecraft/modpacks/prominence-2-rpg',
    'modrinth://example-pack',
    'mock://example-pack',
];

foreach ($invalidSources as $source) {
    if ($provider->supports($source)) {
        throw new RuntimeException("Expected rejection for: {$source}");
    }
}

pass('malformed and unsupported sources rejected');

$unconfigured = new CurseForgeProvider(
    new FakeProviderHttpClient([]),
    new FakeDownloader('/does/not/matter'),
    null,
);

if (!$unconfigured->supports('curseforge://314768')) {
    throw new RuntimeException('Provider should remain selectable when unconfigured.');
}

try {
    $unconfigured->getMetadata('curseforge://314768');
    throw new RuntimeException('Missing API key was not rejected.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'CURSEFORGE_API_KEY')) {
        throw new RuntimeException('Unexpected missing-key error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

pass('missing API key produces clear configuration error');

$http = new FakeProviderHttpClient([$projectResponse, $filesResponse]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

$metadata = $provider->getMetadata('curseforge://314768');

if ($metadata->id !== '314768') {
    throw new RuntimeException('Unexpected CurseForge project id.');
}
if ($metadata->name !== 'Prominence 2 RPG') {
    throw new RuntimeException('Unexpected CurseForge project name.');
}
if ($metadata->version !== 'V1.0.0') {
    throw new RuntimeException('Unexpected CurseForge version.');
}
if ($metadata->minecraftVersion !== '1.20.4') {
    throw new RuntimeException('Unexpected Minecraft version.');
}
if ($metadata->loader !== 'Fabric') {
    throw new RuntimeException('Unexpected loader.');
}
if ($metadata->description !== 'A dark fantasy modpack.') {
    throw new RuntimeException('Unexpected description.');
}
if ($metadata->iconUrl !== 'https://cdn.example/icon.png') {
    throw new RuntimeException('Unexpected icon URL.');
}
if ($metadata->source !== 'curseforge://314768') {
    throw new RuntimeException('Unexpected canonical source.');
}

$firstRequest = $http->requests[0] ?? null;

if ($firstRequest === null) {
    throw new RuntimeException('No HTTP request was recorded.');
}

if (!in_array('X-Api-Key: ' . SECRET_KEY, $firstRequest['headers'], true)) {
    throw new RuntimeException('API key was not sent as a request header.');
}

pass('metadata maps response and sends API key header');

$twoPartFiles = new ProviderHttpResponse(200, [
    'data' => [
        [
            'displayName' => 'V2.0.0',
            'fileName' => 'Pack-2.0.0.zip',
            'fileDate' => '2024-05-01T00:00:00Z',
            'releaseType' => 1,
            'gameVersions' => ['NeoForge', '1.20'],
            'downloadUrl' => 'https://cdn.example/pack-2.0.0.zip',
        ],
    ],
]);

$http = new FakeProviderHttpClient([$projectResponse, $twoPartFiles]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

$metadata = $provider->getMetadata('curseforge://314768');

if ($metadata->minecraftVersion !== '1.20') {
    throw new RuntimeException(
        'Two-part Minecraft version was not mapped: ' . $metadata->minecraftVersion,
    );
}

if ($metadata->loader !== 'NeoForge') {
    throw new RuntimeException('NeoForge loader was not mapped.');
}

pass('two-part Minecraft version (1.20) mapped with loader');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 404 response.', 404),
]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

try {
    $provider->getMetadata('curseforge://314768');
    throw new RuntimeException('404 was not converted to a clean error.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not found')) {
        throw new RuntimeException('Unexpected not-found error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

pass('404 handled as clean not-found error without leaking the key');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned a malformed JSON response.', 200),
]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

try {
    $provider->getMetadata('curseforge://314768');
    throw new RuntimeException('Malformed JSON was not converted to a clean error.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'Unable to load')) {
        throw new RuntimeException('Unexpected malformed-JSON error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

pass('malformed JSON handled as clean error without leaking the key');

$noDownload = new ProviderHttpResponse(200, [
    'data' => [
        [
            'displayName' => 'V1.0.0',
            'fileDate' => '2024-01-01T00:00:00Z',
            'releaseType' => 1,
            'gameVersions' => ['Fabric', '1.20.4'],
            'downloadUrl' => null,
        ],
    ],
]);

$downloader = new FakeDownloader('/does/not/matter');
$http = new FakeProviderHttpClient([$projectResponse, $noDownload]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
);

try {
    $provider->getPackage('curseforge://314768');
    throw new RuntimeException('Missing download URL was not rejected.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'public download URL')) {
        throw new RuntimeException('Unexpected missing-download error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

if ($downloader->calls !== 0) {
    throw new RuntimeException('Downloader should not be called without a URL.');
}

pass('missing public download URL produces controlled error');

$clientPackPath = $temporaryRoot . '/client-pack.zip';
buildZip($clientPackPath, [
    'manifest.json' => '{"minecraft":{"version":"1.20.4"}}',
    'overrides/config/client.toml' => 'client-only',
]);

$downloader = new FakeDownloader($clientPackPath);
$http = new FakeProviderHttpClient([$projectResponse, $filesResponse]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
);

try {
    $provider->getPackage('curseforge://314768');
    throw new RuntimeException('Client pack was not rejected.');
} catch (UnsupportedModpackPackageException $exception) {
    if (!str_contains($exception->getMessage(), 'client pack')) {
        throw new RuntimeException('Unexpected client-pack error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

if (is_file($clientPackPath)) {
    throw new RuntimeException('Downloaded client pack was not cleaned up.');
}

pass('client-pack archive rejected with controlled exception and cleaned up');

$serverPackPath = $temporaryRoot . '/server-pack.zip';
buildZip($serverPackPath, [
    'server.properties' => 'motd=hello',
    'config/server.yml' => 'a: b',
    'versions.json' => '{"1.20.4":true}',
]);

$downloader = new FakeDownloader($serverPackPath);
$http = new FakeProviderHttpClient([$projectResponse, $filesResponse]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
);

$package = $provider->getPackage('curseforge://314768');

if ($package->archivePath !== $serverPackPath) {
    throw new RuntimeException('Server pack should be returned as-is.');
}

if ($package->source !== 'curseforge://314768') {
    throw new RuntimeException('Unexpected package source.');
}

$provider->cleanup($package);

if (is_file($serverPackPath)) {
    throw new RuntimeException('Cleanup did not remove the server pack.');
}

pass('server-pack archive returned as-is and cleaned up');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $temporaryRoot,
        FilesystemIterator::SKIP_DOTS,
    ),
    RecursiveIteratorIterator::CHILD_FIRST,
);

foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}

echo "All CurseForge provider tests passed.\n";