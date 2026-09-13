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

/** @return array{project: array, versions: array} */
function sampleResponses(): array
{
    $project = [
        'id' => 'AANobbMI',
        'slug' => 'prominence-2-rpg',
        'title' => 'Prominence 2 RPG',
        'description' => 'A dark fantasy modpack.',
        'icon_url' => 'https://cdn.example/icon.png',
        'project_type' => 'modpack',
        'game_versions' => ['1.20.1'],
    ];

    $versions = [
        [
            'id' => 'version-001',
            'version_number' => '1.0.0',
            'version_type' => 'release',
            'date_published' => '2024-01-01T00:00:00Z',
            'game_versions' => ['1.21.1'],
            'loaders' => ['fabric'],
            'files' => [
                [
                    'primary' => true,
                    'filename' => 'prominence-2-rpg.mrpack',
                    'url' => 'https://cdn.example/pack.mrpack',
                ],
            ],
        ],
    ];

    return compact('project', 'versions');
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$temporaryRoot = sys_get_temp_dir() . '/modrinth-provider-test';
@mkdir($temporaryRoot, 0750, true);

/** @var array<int, ProviderHttpResponse> $queue */
$queue = [];
$projectResponse = new ProviderHttpResponse(200, sampleResponses()['project']);
$versionsResponse = new ProviderHttpResponse(200, sampleResponses()['versions']);

$http = new FakeProviderHttpClient([$projectResponse, $versionsResponse]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

$validSources = [
    'modrinth://prominence-2-rpg',
    'modrinth://AANobbMI',
    'https://modrinth.com/modpack/prominence-2-rpg',
    'https://www.modrinth.com/modpack/prominence-2-rpg',
    '  modrinth://prominence-2-rpg  ',
];

foreach ($validSources as $source) {
    if (!$provider->supports($source)) {
        throw new RuntimeException("Expected support for: {$source}");
    }
}

pass('valid source syntax accepted');

$invalidSources = [
    'modrinth://',
    'modrinth://../evil',
    'modrinth://slug with space',
    'modrinth://slug/extra',
    'https://evil.example/modpack/prominence-2-rpg',
    'https://modrinth.com/mod/prominence-2-rpg',
    'https://modrinth.com/modpack/../../prominence',
    'mock://example-pack',
    'curseforge://314768',
    'garbage',
];

foreach ($invalidSources as $source) {
    if ($provider->supports($source)) {
        throw new RuntimeException("Expected rejection for: {$source}");
    }
}

pass('malformed and unsupported sources rejected');

$metadata = $provider->getMetadata('modrinth://prominence-2-rpg');

if ($metadata->id !== 'AANobbMI') {
    throw new RuntimeException('Unexpected Modrinth project id.');
}
if ($metadata->name !== 'Prominence 2 RPG') {
    throw new RuntimeException('Unexpected Modrinth project name.');
}
if ($metadata->version !== '1.0.0') {
    throw new RuntimeException('Unexpected Modrinth version.');
}
if ($metadata->minecraftVersion !== '1.21.1') {
    throw new RuntimeException('Unexpected Minecraft version.');
}
if ($metadata->loader !== 'fabric') {
    throw new RuntimeException('Unexpected loader.');
}
if ($metadata->description !== 'A dark fantasy modpack.') {
    throw new RuntimeException('Unexpected description.');
}
if ($metadata->iconUrl !== 'https://cdn.example/icon.png') {
    throw new RuntimeException('Unexpected icon URL.');
}
if ($metadata->source !== 'modrinth://prominence-2-rpg') {
    throw new RuntimeException('Unexpected canonical source.');
}

pass('metadata maps released version and canonical source');

$beta = [
    'version_number' => '2.0.0-beta.1',
    'version_type' => 'beta',
    'date_published' => '2024-06-01T00:00:00Z',
    'game_versions' => ['1.21.1'],
    'loaders' => ['fabric'],
    'files' => [],
];
$release = [
    'version_number' => '1.5.0',
    'version_type' => 'release',
    'date_published' => '2024-03-01T00:00:00Z',
    'game_versions' => ['1.21.1'],
    'loaders' => ['fabric'],
    'files' => [],
];

$http = new FakeProviderHttpClient([
    $projectResponse,
    new ProviderHttpResponse(200, [$beta, $release]),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

$metadata = $provider->getMetadata('modrinth://prominence-2-rpg');

if ($metadata->version !== '1.5.0') {
    throw new RuntimeException('Release version was not preferred over beta.');
}

pass('release version preferred deterministically');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, array_merge(
        sampleResponses()['project'],
        ['project_type' => 'mod'],
    )),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg');
    throw new RuntimeException('Non-modpack project was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not a modpack')) {
        throw new RuntimeException('Unexpected non-modpack error message.');
    }
}

pass('non-modpack project produces controlled error');

$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, [
        'id' => 'AANobbMI',
        'slug' => 'prominence-2-rpg',
        'title' => 'Prominence 2 RPG',
        'description' => 'A dark fantasy modpack.',
        'icon_url' => 'https://cdn.example/icon.png',
        'project_type' => 'modpack',
        'game_versions' => [],
    ]),
    new ProviderHttpResponse(200, [
        [
            'version_number' => '1.0.0',
            'version_type' => 'release',
            'date_published' => '2024-01-01T00:00:00Z',
            'game_versions' => [],
            'loaders' => [],
            'files' => [],
        ],
    ]),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg');
    throw new RuntimeException('Version without game versions was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'Minecraft version')) {
        throw new RuntimeException('Unexpected missing-version error message.');
    }
}

pass('version without Minecraft version produces controlled error');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 404 response.', 404),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg');
    throw new RuntimeException('404 was not converted to a clean error.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'not found')) {
        throw new RuntimeException('Unexpected not-found error message.');
    }
}

pass('404 handled as clean not-found error');

$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned a malformed JSON response.', 200),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

try {
    $provider->getMetadata('modrinth://prominence-2-rpg');
    throw new RuntimeException('Malformed JSON was not converted to a clean error.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'Unable to load')) {
        throw new RuntimeException('Unexpected malformed-JSON error message.');
    }
}

pass('malformed JSON handled as clean error');

$mrpackPath = $temporaryRoot . '/fixture.mrpack';
buildZip($mrpackPath, [
    'modrinth.index.json' => '{"formatVersion":1}',
    'overrides/config/normal.json' => '{"a":1}',
    'overrides/config/server.toml' => 'overrides-version',
    'server-overrides/config/server.toml' => 'server-version',
    'server-overrides/ops.json' => 'ops',
    'client-overrides/client-only.txt' => 'client',
]);

$http = new FakeProviderHttpClient([
    $projectResponse,
    $versionsResponse,
]);
$downloader = new FakeDownloader($mrpackPath);
$provider = new ModrinthProvider(
    $http,
    $downloader,
    $temporaryRoot,
);

$package = $provider->getPackage('modrinth://prominence-2-rpg');

if (!is_file($package->archivePath)) {
    throw new RuntimeException('Normalized package archive does not exist.');
}

if ($package->source !== 'modrinth://prominence-2-rpg@version-001') {
    throw new RuntimeException('Unexpected package source.');
}

$zip = new ZipArchive();
$zip->open($package->archivePath);
$names = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $names[] = $zip->statIndex($index)['name'];
}
$serverToml = $zip->getFromName('config/server.toml');
$zip->close();

sort($names);

if ($names !== ['config/normal.json', 'config/server.toml', 'ops.json']) {
    throw new RuntimeException(
        'Unexpected normalized archive contents: ' . implode(',', $names),
    );
}

if ($serverToml !== 'server-version') {
    throw new RuntimeException('server-overrides did not win the merge.');
}

if (!str_contains((string) $downloader->url, 'pack.mrpack')) {
    throw new RuntimeException('Downloader was not given the mrpack URL.');
}

pass('mrpack normalized to server archive (overrides + server-overrides)');

$provider->cleanup($package);

if (is_file($package->archivePath)) {
    throw new RuntimeException('Cleanup did not remove the normalized archive.');
}

pass('cleanup removes temporary package archive');

$noContentPath = $temporaryRoot . '/no-content.mrpack';
buildZip($noContentPath, [
    'modrinth.index.json' => '{"formatVersion":1}',
    'client-overrides/client-only.txt' => 'client',
]);

$http = new FakeProviderHttpClient([
    $projectResponse,
    $versionsResponse,
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader($noContentPath),
    $temporaryRoot,
);

try {
    $provider->getPackage('modrinth://prominence-2-rpg');
    throw new RuntimeException('Empty mrpack was accepted.');
} catch (UnsupportedModpackPackageException $exception) {
    if (!str_contains($exception->getMessage(), 'no server content')) {
        throw new RuntimeException('Unexpected no-content error message.');
    }
}

if (is_file($noContentPath)) {
    throw new RuntimeException('Downloaded archive was not cleaned up on failure.');
}

pass('mrpack without server content rejected and cleaned up');

$traversalPath = $temporaryRoot . '/traversal.mrpack';
buildZip($traversalPath, [
    'overrides/../evil.txt' => 'evil',
]);

$http = new FakeProviderHttpClient([
    $projectResponse,
    $versionsResponse,
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader($traversalPath),
    $temporaryRoot,
);

try {
    $provider->getPackage('modrinth://prominence-2-rpg');
    throw new RuntimeException('Traversal entry was accepted.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'invalid entry path')) {
        throw new RuntimeException('Unexpected traversal error message.');
    }
}

pass('archive path traversal rejected');

$zipPath = $temporaryRoot . '/server.zip';
buildZip($zipPath, [
    'server.properties' => 'motd=hello',
    'config/x.yml' => 'a: b',
]);

$http = new FakeProviderHttpClient([
    $projectResponse,
    new ProviderHttpResponse(200, [
        [
            'version_number' => '1.0.0',
            'version_type' => 'release',
            'date_published' => '2024-01-01T00:00:00Z',
            'game_versions' => ['1.21.1'],
            'loaders' => ['fabric'],
            'files' => [
                [
                    'primary' => true,
                    'filename' => 'server.zip',
                    'url' => 'https://cdn.example/server.zip',
                ],
            ],
        ],
    ]),
]);
$downloader = new FakeDownloader($zipPath);
$provider = new ModrinthProvider(
    $http,
    $downloader,
    $temporaryRoot,
);

$package = $provider->getPackage('modrinth://prominence-2-rpg');

if ($package->archivePath !== $zipPath) {
    throw new RuntimeException('ZIP package should be returned as-is.');
}

$provider->cleanup($package);

if (is_file($zipPath)) {
    throw new RuntimeException('Cleanup did not remove the ZIP package.');
}

pass('ZIP package returned as-is and cleaned up');

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

echo "All Modrinth provider tests passed.\n";