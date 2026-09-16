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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
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
    public ?string $url = null;

    public int $calls = 0;

    public function __construct(private readonly string $archivePath)
    {
    }

    public function download(string $url): string
    {
        $this->url = $url;
        $this->calls++;

        return $this->archivePath;
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

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void
    {
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

$project = [
    'id' => 'AANobbMI',
    'slug' => 'prominence-2-rpg',
    'title' => 'Prominence 2 RPG',
    'description' => 'A dark fantasy modpack.',
    'icon_url' => 'https://cdn.example/icon.png',
    'project_type' => 'modpack',
    'game_versions' => [],
];

$versions = [
    [
        'id' => 'abc123',
        'project_id' => 'AANobbMI',
        'version_number' => '2.0.1',
        'version_type' => 'release',
        'date_published' => '2025-01-02T10:00:00Z',
        'game_versions' => ['1.21.1'],
        'loaders' => ['fabric'],
        'files' => [
            [
                'primary' => true,
                'filename' => 'prominence-2-rpg-2.0.1.mrpack',
                'url' => 'https://cdn.example/2.0.1.mrpack',
            ],
        ],
    ],
];

$temporaryRoot = sys_get_temp_dir() . '/modrinth-version-resolution-test';
@mkdir($temporaryRoot, 0750, true);

$provider = new ModrinthProvider(
    new FakeProviderHttpClient([]),
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

$pinnedSources = [
    'modrinth://prominence-2-rpg@abc123',
    'modrinth://AANobbMI@abc123',
];

foreach ($pinnedSources as $source) {
    if (!$provider->supports($source)) {
        throw new RuntimeException("Expected pin support for: {$source}");
    }
}

$invalidPins = [
    'modrinth://prominence-2-rpg@',
    'modrinth://prominence-2-rpg@ bad id ',
    'modrinth://prominence-2-rpg@@abc',
    'modrinth://prominence-2-rpg@abc@def',
    'https://modrinth.com/modpack/prominence-2-rpg@abc123',
];

foreach ($invalidPins as $source) {
    if ($provider->supports($source)) {
        throw new RuntimeException("Expected pin rejection for: {$source}");
    }
}

pass('pinned source syntax accepted only when valid');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpResponse(200, $versions[0]),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

$metadata = $provider->getMetadata('modrinth://prominence-2-rpg@abc123');

if (count($http->requests) !== 2) {
    throw new RuntimeException('Expected project + version requests.');
}

if (!str_ends_with($http->requests[0]['url'], '/project/prominence-2-rpg')) {
    throw new RuntimeException('First request should resolve the project.');
}

if (!str_ends_with($http->requests[1]['url'], '/version/abc123')) {
    throw new RuntimeException(
        'Second request should fetch the pinned version: '
        . $http->requests[1]['url'],
    );
}

if ($metadata->version !== '2.0.1') {
    throw new RuntimeException('Pinned version number not respected.');
}

if ($metadata->version !== '2.0.1') {
    throw new RuntimeException('Pinned version number not mapped.');
}

if ($metadata->source !== 'modrinth://prominence-2-rpg@abc123') {
    throw new RuntimeException('Metadata source should keep the pin.');
}

pass('pinned source fetches exact version by id');

$foreign = $versions[0];
$foreign['project_id'] = 'OTHERPROJECT';

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpResponse(200, $foreign),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg@abc123');
    throw new RuntimeException('Foreign project version was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'does not belong')) {
        throw new RuntimeException('Unexpected foreign-version message.');
    }
}

pass('version pinned to another project rejected');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpException(
        'The provider returned an HTTP 404 response.',
        404,
    ),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg@missing');
    throw new RuntimeException('Missing pinned version was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not found')) {
        throw new RuntimeException('Unexpected missing-version message.');
    }
}

pass('missing pinned version handled as clean error');

$http = new FakeProviderHttpClient([
    new ProviderHttpException(
        'The provider returned an HTTP 429 response.',
        429,
    ),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg@abc123');
    throw new RuntimeException('Rate-limited pinned fetch was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'rejected the request')) {
        throw new RuntimeException('Unexpected rate-limit message.');
    }
}

pass('upstream rejections on pinned fetch handled cleanly');

$mrpackPath = $temporaryRoot . '/pinned.mrpack';
buildZip($mrpackPath, [
    'modrinth.index.json' => '{"formatVersion":1}',
    'overrides/config/server.toml' => 'pinned-version',
]);

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpResponse(200, $versions[0]),
]);
$downloader = new FakeDownloader($mrpackPath);
$provider = new ModrinthProvider(
    $http,
    $downloader,
    $temporaryRoot,
);

$package = $provider->getPackage('modrinth://prominence-2-rpg@abc123');

if ($package->source !== 'modrinth://prominence-2-rpg@abc123') {
    throw new RuntimeException('Package source should keep the pin.');
}

if ((string) $downloader->url !== 'https://cdn.example/2.0.1.mrpack') {
    throw new RuntimeException(
        'Package should download the pinned version file.',
    );
}

if (!is_dir($package->archivePath)) {
    throw new RuntimeException('Normalized package directory missing.');
}

$serverToml = file_get_contents($package->archivePath . '/config/server.toml');

if ($serverToml !== 'pinned-version') {
    throw new RuntimeException('Pinned package content did not match.');
}

$provider->cleanup($package);

pass('getPackage downloads pinned version file and normalizes');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpResponse(200, $versions),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

$unpinned = $provider->getMetadata('modrinth://prominence-2-rpg');

if (count($http->requests) !== 2) {
    throw new RuntimeException('Unpinned path should fetch project + versions.');
}

if (!str_ends_with($http->requests[1]['url'], '/version')) {
    throw new RuntimeException('Unpinned path should use the versions list.');
}

if (str_contains($unpinned->source, '@')) {
    throw new RuntimeException('Unpinned source must not gain a pin.');
}

pass('unpinned source path unchanged (backward compatible)');

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

echo "All Modrinth version resolution tests passed.\n";
