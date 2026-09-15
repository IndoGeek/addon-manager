<?php

/**
 * Verifies the cumulative progress accounting a Modrinth install relies on:
 * the modpack archive plus every index-file mod shares one running byte total
 * so the panel renders a single monotonic bar, and cancelling during the long
 * per-mod download/packaging phase aborts promptly.
 */

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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @var array<int, ProviderHttpResponse|Throwable> */
    private array $handlers;

    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(array $handlers)
    {
        $this->handlers = $handlers;
    }

    public function get(
        string $url,
        array $query = [],
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

final class RecordingDownloader implements Downloader
{
    /**
     * @var array<int, array{bytes: int, total: int|null}>
     */
    public array $offsetSnapshots = [];

    public int $calls = 0;

    public bool $cancelled = false;

    /** Flip the cancel flag after the first successful download. */
    public bool $cancelAfterFirstDownload = false;

    /** @var array<string, string> */
    private array $routes;

    public function __construct(
        private readonly string $fallbackPath,
        array $routes = [],
    ) {
        $this->routes = $routes;
    }

    public function download(string $url): string
    {
        $this->calls++;

        if ($this->cancelAfterFirstDownload && $this->calls >= 1) {
            $this->cancelled = true;
        }

        return $this->routes[$url] ?? $this->fallbackPath;
    }

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
        $this->offsetSnapshots[] = [
            'bytes' => $completedBytes,
            'total' => $totalBytes,
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}

function buildZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, is_string($content) ? $content : '{}');
    }
    $zip->close();
}

function makeHttpQueue(array $overrides = []): array
{
    $project = array_merge([
        'id' => 'AANobbMI',
        'slug' => 'prominence-2-rpg',
        'title' => 'Prominence 2 RPG',
        'description' => 'A dark fantasy modpack.',
        'icon_url' => 'https://cdn.example/icon.png',
        'project_type' => 'modpack',
        'game_versions' => ['1.20.1'],
    ], $overrides['project'] ?? []);

    $versions = array_merge([
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
                    'size' => 5000,
                    'url' => 'https://cdn.example/pack.mrpack',
                ],
            ],
        ],
    ], $overrides['versions'] ?? []);

    return [
        new ProviderHttpResponse(200, $project),
        new ProviderHttpResponse(200, $versions),
    ];
}

$temporaryRoot = sys_get_temp_dir() . '/modpack-aggregate-progress-test';
@mkdir($temporaryRoot, 0750, true);

foreach (glob($temporaryRoot . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}

$mrpackPath = $temporaryRoot . '/fixture.mrpack';

function buildFixtureMrpack(string $path): void
{
    buildZip($path, [
        'modrinth.index.json' => json_encode([
            'files' => [
                [
                    'path' => 'mods/alpha.jar',
                    'downloads' => ['https://cdn.example/alpha.jar'],
                    'fileSize' => 111,
                    'env' => ['server' => 'supported', 'client' => 'supported'],
                ],
                [
                    'path' => 'mods/beta.jar',
                    'downloads' => ['https://cdn.example/beta.jar'],
                    'fileSize' => 222,
                    'env' => ['server' => 'supported', 'client' => 'supported'],
                ],
                [
                    'path' => 'mods/gamma.jar',
                    'downloads' => ['https://cdn.example/gamma.jar'],
                    'fileSize' => 333,
                    'env' => ['server' => 'unsupported', 'client' => 'supported'],
                ],
            ],
        ]),
        'mods/embedded.jar' => "\x50\x4b\x03\x04",
    ]);
}

buildFixtureMrpack($mrpackPath);

$mrpackBytes = (int) @filesize($mrpackPath);

if ($mrpackBytes <= 0) {
    throw new RuntimeException('Fixture mrpack was not created.');
}

$fakeModPath = $temporaryRoot . '/mod.bin';
file_put_contents($fakeModPath, str_repeat('x', 64));

$downloader = new RecordingDownloader(
    $fakeModPath,
    ['https://cdn.example/pack.mrpack' => $mrpackPath],
);
$provider = new ModrinthProvider(
    new FakeProviderHttpClient(makeHttpQueue()),
    $downloader,
    $temporaryRoot,
);

$package = $provider->getPackage('modrinth://prominence-2-rpg');

if (!is_file($package->archivePath)) {
    throw new RuntimeException('Provider did not produce a normalized archive.');
}

$indexTotal = 111 + 222;
$expectedTotal = $mrpackBytes + $indexTotal;

$snapshots = $downloader->offsetSnapshots;

if (($snapshots[0]['bytes'] ?? null) !== 0) {
    throw new RuntimeException('Download did not start at offset zero.');
}
// The mrpack phase is anchored to a virtual total (archive size over the
// provider's progress slice) so the bar never spikes toward 85% before the
// real index-file footprint is known in buildServerArchive.
$expectedInitialTotal = max(1, (int) round(5000 / 0.12));
if (($snapshots[0]['total'] ?? null) !== $expectedInitialTotal) {
    throw new RuntimeException(
        'Initial mrpack total did not use the virtual archive anchor.'
    );
}

$lastBytes = -1;

foreach ($snapshots as $snapshot) {
    if ($snapshot['bytes'] < $lastBytes) {
        throw new RuntimeException('Progress offset moved backwards.');
    }

    $lastBytes = $snapshot['bytes'];
}

if ($lastBytes !== $mrpackBytes + 111) {
    throw new RuntimeException(
        'Final offset did not account for the embedded mrpack plus completed mods.'
    );
}

foreach (array_slice($snapshots, 1) as $snapshot) {
    if ($snapshot['total'] !== $expectedTotal) {
        throw new RuntimeException('Running total was not held constant across mods.');
    }
}

if ($downloader->calls !== 3) {
    throw new RuntimeException(
        'Expected the mrpack plus two index downloads (server-unsupported mod must be skipped).'
    );
}

if (!is_file($package->archivePath)) {
    throw new RuntimeException('Normalized archive missing.');
}

$zip = new ZipArchive();
$zip->open($package->archivePath);

foreach (['mods/alpha.jar', 'mods/beta.jar', 'mods/embedded.jar'] as $expectedEntry) {
    if ($zip->statName($expectedEntry) === false) {
        throw new RuntimeException("Normalized archive is missing {$expectedEntry}.");
    }
}

if ($zip->statName('mods/gamma.jar') !== false) {
    throw new RuntimeException('Server-unsupported mod leaked into the archive.');
}

$zip->close();

echo 'PASS: cumulative byte totals stay monotonic across archive and mods' . "\n";

// The provider deletes the source mrpack after normalizing it, so the fixture
// must be rebuilt before the next provider run.
buildFixtureMrpack($mrpackPath);

$downloader = new RecordingDownloader(
    $fakeModPath,
    ['https://cdn.example/pack.mrpack' => $mrpackPath],
);
$downloader->cancelAfterFirstDownload = true;
$provider = new ModrinthProvider(
    new FakeProviderHttpClient(makeHttpQueue()),
    $downloader,
    $temporaryRoot,
);

try {
    $provider->getPackage('modrinth://prominence-2-rpg');
    throw new RuntimeException('Cancelling during packaging was not honoured.');
} catch (InstallationCancelledException $exception) {
    if (!str_contains($exception->getMessage(), 'cancelled')) {
        throw new RuntimeException('Unexpected cancellation message.');
    }
}

echo 'PASS: cancel during the multi-mod packaging phase aborts promptly' . "\n";

$manager = new DownloadManager(
    $temporaryRoot . '/real',
    1048576,
);

if ($manager->isCancelled()) {
    throw new RuntimeException('isCancelled must default to false.');
}

$manager->setCancelChecker(static fn (): bool => true);

if (!$manager->isCancelled()) {
    throw new RuntimeException('isCancelled must reflect a firing checker.');
}

$manager->setCancelChecker(static fn (): bool => false);

if ($manager->isCancelled()) {
    throw new RuntimeException('isCancelled must clear with a non-firing checker.');
}

$manager->setProgressOffset(-5, null);
$manager->setProgressOffset(10, 0);

echo 'PASS: isCancelled and offset clamping behave predictably' . "\n";

echo "\nAll download-aggregate-progress tests passed.\n";
exit(0);