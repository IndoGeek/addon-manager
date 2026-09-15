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

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
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

    /** @param array<string, string> $routes       URL => archive path */
    /** @param array<int, string>    $failedUrls   URLs that throw during download */
    public function __construct(
        private readonly string $archivePath,
        private array $routes = [],
        private array $failedUrls = [],
    ) {
    }

    public function download(string $url): string
    {
        $this->calls++;

        if (in_array($url, $this->failedUrls, true)) {
            throw new RuntimeException('Download failed for ' . $url);
        }

        return $this->routes[$url] ?? $this->archivePath;
    }

    public int $offsetBytes = 0;

    public ?int $offsetTotal = null;

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
        $this->offsetBytes = $completedBytes;
        $this->offsetTotal = $totalBytes;
    }

    public function isCancelled(): bool
    {
        return false;
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
        'classId' => 4471,
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
    'manifest.json' => json_encode([
        'minecraft' => [
            'version' => '1.20.4',
            'modLoaders' => [['id' => 'fabric-0.14.21', 'primary' => true]],
        ],
        'manifestType' => 'minecraftModpack',
        'manifestVersion' => 1,
        'name' => 'Prominence 2 RPG',
        'version' => '1.0.0',
        'overrides' => 'overrides',
        'files' => [
            [
                'projectID' => 123,
                'fileID' => 1001,
                'required' => true,
                'env' => ['client' => 'required', 'server' => 'required'],
            ],
            [
                'projectID' => 456,
                'fileID' => 1002,
                'required' => true,
                'env' => ['client' => 'required', 'server' => 'unsupported'],
            ],
            [
                'projectID' => 789,
                'fileID' => 1003,
                'required' => false,
            ],
        ],
    ]),
    'overrides/config/install.toml' => 'server-config',
    'config/client-only.toml' => 'should-not-leak',
]);

$modJarPath = $temporaryRoot . '/downloaded-mod.jar';
file_put_contents($modJarPath, 'MODJAR');

$filesResolveResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 1001,
            'fileName' => 'essential-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/essential-mod.jar',
            'fileLength' => 512,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 1002,
            'fileName' => 'client-only-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/client-only-mod.jar',
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 1003,
            'fileName' => 'optional-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/optional-mod.jar',
            'fileLength' => 128,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$downloader = new FakeDownloader($clientPackPath, [
    'https://cdn.example/mods/essential-mod.jar' => $modJarPath,
]);
$http = new FakeProviderHttpClient([
    $projectResponse,
    $filesResponse,
    $filesResolveResponse,
]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

$package = $provider->getPackage('curseforge://314768');

$normalized = new ZipArchive();
$normalized->open($package->archivePath);

try {
    $entries = [];

    for ($index = 0; $index < $normalized->numFiles; $index++) {
        $entries[] = $normalized->getNameIndex($index);
    }

    sort($entries);

    if ($entries !== ['config/install.toml', 'mods/essential-mod.jar']) {
        throw new RuntimeException(
            'Unexpected normalized client-pack contents: ' . implode(',', $entries),
        );
    }

    if ($normalized->getFromName('mods/essential-mod.jar') !== 'MODJAR') {
        throw new RuntimeException('Resolved manifest mod was not embedded.');
    }

    if ($normalized->getFromName('config/install.toml') !== 'server-config') {
        throw new RuntimeException('Overrides were not applied to the server root.');
    }

    if ($normalized->statName('manifest.json') !== false) {
        throw new RuntimeException(
            'The client-pack manifest must not ship in the server archive.',
        );
    }

    if ($normalized->statName('config/client-only.toml') !== false) {
        throw new RuntimeException(
            'Non-override client-pack content leaked into the server archive.',
        );
    }
} finally {
    $normalized->close();
}

if ($package->source !== 'curseforge://314768') {
    throw new RuntimeException('Unexpected client-pack package source.');
}

if (is_file($clientPackPath)) {
    throw new RuntimeException('Downloaded client pack was not cleaned up.');
}

$provider->cleanup($package);

if (is_file($package->archivePath)) {
    throw new RuntimeException('Cleanup did not remove the normalized archive.');
}

pass('client-pack archive resolved through the manifest into a normalized server archive');

$brokenClientPackPath = $temporaryRoot . '/broken-client-pack.zip';
buildZip($brokenClientPackPath, [
    'manifest.json' => 'not-json',
    'overrides/config/x.txt' => 'x',
]);

$downloader = new FakeDownloader($brokenClientPackPath);
$http = new FakeProviderHttpClient([$projectResponse, $filesResponse]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

try {
    $provider->getPackage('curseforge://314768');
    throw new RuntimeException('Malformed client-pack manifest was not rejected.');
} catch (UnsupportedModpackPackageException $exception) {
    if (!str_contains($exception->getMessage(), 'malformed')) {
        throw new RuntimeException('Unexpected malformed-manifest error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

if (is_file($brokenClientPackPath)) {
    throw new RuntimeException('Broken client pack was not cleaned up.');
}

pass('malformed client-pack manifest fails loudly and cleans up');

$partialPackPath = $temporaryRoot . '/partial-client-pack.zip';
buildZip($partialPackPath, [
    'manifest.json' => json_encode([
        'minecraft' => ['version' => '1.20.4'],
        'overrides' => 'overrides',
        'files' => [
            ['projectID' => 11, 'fileID' => 2001, 'required' => true],
            ['projectID' => 22, 'fileID' => 2002, 'required' => true],
        ],
    ]),
    'overrides/config/x.toml' => 'x',
]);
$partialModPath = $temporaryRoot . '/partial-mod.jar';
file_put_contents($partialModPath, 'PARTIAL');

$cdnDeadJarPath = $temporaryRoot . '/dead-mod-cdn.jar';
file_put_contents($cdnDeadJarPath, 'DEADMOD');

$partialResolve = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 2001,
            'fileName' => 'available-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/available-mod.jar',
            'fileLength' => 512,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 2002,
            'fileName' => 'dead-mod.jar',
            'downloadUrl' => null,
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$downloader = new FakeDownloader($partialPackPath, [
    'https://cdn.example/mods/available-mod.jar' => $partialModPath,
    'https://edge.forgecdn.net/files/2/2/dead-mod.jar' => $cdnDeadJarPath,
]);
$http = new FakeProviderHttpClient([
    $projectResponse,
    $filesResponse,
    $partialResolve,
]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

$package = $provider->getPackage('curseforge://314768');

$partial = new ZipArchive();
$partial->open($package->archivePath);

try {
    if ($partial->getFromName('mods/available-mod.jar') !== 'PARTIAL') {
        throw new RuntimeException(
            'Public manifest mods must still be installed when siblings lack a URL.',
        );
    }

    if ($partial->getFromName('mods/dead-mod.jar') !== 'DEADMOD') {
        throw new RuntimeException(
            'A manifest mod without a download URL must fall back to the CurseForge CDN layout.',
        );
    }
} finally {
    $partial->close();
}

$provider->cleanup($package);

pass('manifest mods without a download URL fall back to the CurseForge CDN');

$allDeadPackPath = $temporaryRoot . '/all-dead-client-pack.zip';
buildZip($allDeadPackPath, [
    'manifest.json' => json_encode([
        'minecraft' => ['version' => '1.20.4'],
        'overrides' => 'overrides',
        'files' => [
            ['projectID' => 11, 'fileID' => 2003, 'required' => true],
            ['projectID' => 22, 'fileID' => 2004, 'required' => true],
        ],
    ]),
    'overrides/config/x.toml' => 'x',
]);

$allDeadResolve = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 2003,
            'fileName' => 'dead-a.jar',
            'downloadUrl' => null,
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 2004,
            'fileName' => 'dead-b.jar',
            'downloadUrl' => null,
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$downloader = new FakeDownloader(
    $allDeadPackPath,
    [],
    [
        'https://edge.forgecdn.net/files/2/3/dead-a.jar',
        'https://edge.forgecdn.net/files/2/4/dead-b.jar',
    ],
);
$http = new FakeProviderHttpClient([
    $projectResponse,
    $filesResponse,
    $allDeadResolve,
]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

try {
    $provider->getPackage('curseforge://314768');
    throw new RuntimeException(
        'A client pack with no installable mods must not be accepted.',
    );
} catch (UnsupportedModpackPackageException $exception) {
    if (!str_contains($exception->getMessage(), 'no server mods')) {
        throw new RuntimeException('Unexpected all-undownloadable error message.');
    }
    if (str_contains($exception->getMessage(), SECRET_KEY)) {
        throw new RuntimeException('API key leaked into error message.');
    }
}

if (is_file($allDeadPackPath)) {
    throw new RuntimeException('Undownloadable client pack was not cleaned up.');
}

pass('client pack with no installable mods fails loudly');

$fallbackPackPath = $temporaryRoot . '/fallback-client-pack.zip';
buildZip($fallbackPackPath, [
    'manifest.json' => json_encode([
        'minecraft' => ['version' => '1.20.4'],
        'overrides' => 'overrides',
        'files' => [
            ['projectID' => 33, 'fileID' => 3001, 'required' => true],
        ],
    ]),
    'overrides/config/x.toml' => 'x',
]);
$fallbackModPath = $temporaryRoot . '/fallback-mod-cdn.jar';
file_put_contents($fallbackModPath, 'FALLBACKCDN');

$fallbackResolve = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 3001,
            'fileName' => 'fallback-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/stale-mod.jar',
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$downloader = new FakeDownloader(
    $fallbackPackPath,
    [
        'https://edge.forgecdn.net/files/3/1/fallback-mod.jar' => $fallbackModPath,
    ],
    [
        'https://cdn.example/mods/stale-mod.jar',
    ],
);
$http = new FakeProviderHttpClient([
    $projectResponse,
    $filesResponse,
    $fallbackResolve,
]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

$package = $provider->getPackage('curseforge://314768');

$fallback = new ZipArchive();
$fallback->open($package->archivePath);

try {
    if ($fallback->getFromName('mods/fallback-mod.jar') !== 'FALLBACKCDN') {
        throw new RuntimeException(
            'A failed metadata download must fall back to the reconstructed CDN URL.',
        );
    }
} finally {
    $fallback->close();
}

$provider->cleanup($package);

pass('a failed metadata download falls back to the reconstructed CDN URL');

$serverPackFileResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 222222,
        'displayName' => 'Dedicated Server Pack',
        'fileName' => 'Server-Pack.zip',
        'fileDate' => '2024-01-10T00:00:00Z',
        'releaseType' => 1,
        'fileStatus' => 4,
        'isAvailable' => true,
        'downloadUrl' => 'https://cdn.example/server-pack.zip',
    ],
]);

$mainWithServerPack = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 111111,
            'displayName' => 'Main Client Zip',
            'fileName' => 'Main.zip',
            'fileDate' => '2024-01-01T00:00:00Z',
            'releaseType' => 1,
            'gameVersions' => ['Fabric', '1.20.4'],
            'downloadUrl' => 'https://cdn.example/main.zip',
            'serverPackFileId' => 222222,
        ],
    ],
]);

$preferredServerPack = $temporaryRoot . '/preferred-server-pack.zip';
buildZip($preferredServerPack, [
    'server.properties' => 'motd=hello',
]);

$downloader = new FakeDownloader($preferredServerPack);
$http = new FakeProviderHttpClient([
    $projectResponse,
    $mainWithServerPack,
    $serverPackFileResponse,
]);
$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

$package = $provider->getPackage('curseforge://314768');

if ($package->archivePath !== $preferredServerPack) {
    throw new RuntimeException(
        'The dedicated server pack should be installed instead of the main client zip.',
    );
}

if ($package->source !== 'curseforge://314768@222222') {
    throw new RuntimeException('Unexpected server-pack package source.');
}

$provider->cleanup($package);

pass('download prefers the dedicated server pack over the main client zip');

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

$http = new FakeProviderHttpClient([$projectResponse, $filesResponse]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

if ($provider->manualDownloadInfoFor('curseforge://314768') !== null) {
    throw new RuntimeException(
        'Publicly downloadable file should not require manual download.',
    );
}

pass('downloadable file reports no manual-download requirement');

$http = new FakeProviderHttpClient([$projectResponse, $noDownload]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    SECRET_KEY,
);

$info = $provider->manualDownloadInfoFor('curseforge://314768');

if ($info === null) {
    throw new RuntimeException(
        'Non-downloadable file should report manual-download guidance.',
    );
}

if (($info['provider'] ?? '') !== 'curseforge') {
    throw new RuntimeException('Unexpected manual-download provider.');
}

if (($info['project_name'] ?? '') !== 'Prominence 2 RPG') {
    throw new RuntimeException('Unexpected manual-download project name.');
}

if (($info['version'] ?? '') !== 'V1.0.0') {
    throw new RuntimeException('Unexpected manual-download version.');
}

if (array_key_exists('download_url', $info) && $info['download_url'] !== null) {
    throw new RuntimeException('Missing download URL must stay null.');
}

if (($info['project_url'] ?? '') === '') {
    throw new RuntimeException('Manual-download guidance must include a project URL.');
}

if (($info['reason'] ?? '') === '') {
    throw new RuntimeException('Manual-download guidance must include a reason.');
}

if (str_contains(json_encode($info), SECRET_KEY)) {
    throw new RuntimeException('API key leaked into manual-download guidance.');
}

pass('missing-download file yields normalized manual-download guidance');

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