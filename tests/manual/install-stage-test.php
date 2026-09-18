<?php

// Tests for the install-stage protocol a modpack download reports:
// - DownloadManager announces named stages (archive, extract, manifest, mods)
//   to its stage callback, immediately on a transition and throttled for
//   repeats so a counted stage cannot flood the progress store.
// - The CurseForge client-pack flow announces every step it walks, and each
//   step owns its own progress window instead of re-anchoring a shared one.

require __DIR__ . '/../../app/Services/Download/Downloader.php';
require __DIR__ . '/../../app/Services/Download/StageReporter.php';
require __DIR__ . '/../../app/Services/Download/ConcurrentDownloader.php';
require __DIR__ . '/../../app/Services/Download/DownloadManager.php';
require __DIR__ . '/../../app/Providers/ModpackProvider.php';
require __DIR__ . '/../../app/Providers/ManualDownloadProvider.php';
require __DIR__ . '/../../app/Providers/PartialPackageProvider.php';
require __DIR__ . '/../../app/Providers/ModpackPackage.php';
require __DIR__ . '/../../app/Providers/UnsupportedModpackPackageException.php';
require __DIR__ . '/../../app/Providers/CurseForgeProvider.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\CurseForgeProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\ConcurrentDownloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\StageReporter;

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$temporaryRoot = sys_get_temp_dir() . '/install-stage-test';
@mkdir($temporaryRoot, 0750, true);

// ── Part 1: the stage protocol on the downloader itself ───────────────

$manager = new DownloadManager($temporaryRoot);

if (!$manager instanceof StageReporter) {
    throw new RuntimeException('DownloadManager must report install stages.');
}

// Announcing before anyone is listening must be a no-op, not a fatal.
$manager->stage('archive');

/** @var array<int, array{0: string, 1: int|null, 2: int|null}> $events */
$events = [];

$manager->setStageCallback(
    static function (
        string $key,
        ?int $current = null,
        ?int $total = null,
    ) use (&$events): void {
        $events[] = [$key, $current, $total];
    },
);

$manager->stage('archive');
$manager->stage('extract');
$manager->stage('mods', 0, 3);

if (array_column($events, 0) !== ['archive', 'extract', 'mods']) {
    throw new RuntimeException(
        'Stage transitions were not delivered in order: '
            . json_encode($events),
    );
}

if ($events[2] !== ['mods', 0, 3]) {
    throw new RuntimeException('Stage counts were not delivered verbatim.');
}

pass('stage transitions reach the callback immediately and in order');

// A counted stage refreshes on every fetched file; those repeats are throttled
// so the progress store is not rewritten on every single tick.
$manager->stage('mods', 1, 3);

if (count($events) !== 3) {
    throw new RuntimeException('A repeat stage update was not throttled.');
}

usleep(450000);

$manager->stage('mods', 2, 3);

if (count($events) !== 4 || $events[3] !== ['mods', 2, 3]) {
    throw new RuntimeException('A throttled stage update never arrived.');
}

pass('repeat stage updates are throttled but never lost');

// Model one tick of the batch engine: it writes byte progress and hands the
// per-file states over immediately afterwards. The counted update that follows
// must still get through, or the card sits on "0 / 300" for the whole download
// while its bar fills. Byte progress runs on its own throttle so the stage
// clock here is the one that decides.
usleep(450000);

$manager->reportProgress(100, 100);
$manager->stage('mods', 3, 3);

if (count($events) !== 5 || $events[4] !== ['mods', 3, 3]) {
    throw new RuntimeException(
        'Byte progress starved a counted stage update: '
            . json_encode($events),
    );
}

pass('counted stage updates survive concurrent byte progress');

$manager->setStageCallback(null);
$manager->stage('deploy');

if (count($events) !== 5) {
    throw new RuntimeException('Stage callback was not cleared.');
}

pass('clearing the stage callback stops delivery');

// ── Part 2: a real CurseForge client-pack install announces its steps ─

final class StageFakeHttpClient implements ProviderHttpClient
{
    /** @param array<int, ProviderHttpResponse> $handlers */
    public function __construct(private array $handlers)
    {
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $handler = array_shift($this->handlers);

        if (!$handler instanceof ProviderHttpResponse) {
            throw new RuntimeException('Unexpected fake HTTP GET.');
        }

        return $handler;
    }

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $handler = array_shift($this->handlers);

        if (!$handler instanceof ProviderHttpResponse) {
            throw new RuntimeException('Unexpected fake HTTP POST.');
        }

        return $handler;
    }
}

// Records every stage announcement, every progress window and every batch run,
// which together are what the downloading card renders.
final class StageRecordingDownloader implements ConcurrentDownloader, StageReporter
{
    /** @var array<int, string> */
    public array $stages = [];

    /** @var array<int, array{bytes: int, total: int|null}> */
    public array $offsets = [];

    /** @var array<int, array{0: int|null, 1: int|null}> */
    public array $modCounts = [];

    public int $batchCalls = 0;

    /** @var null|callable(array): void */
    private $stageCallback = null;

    /** @var null|callable(array): void */
    private $batchCallback = null;

    public function __construct(
        private readonly string $archivePath,
        private readonly array $routes = [],
    ) {
    }

    public function setStageCallback(?callable $callback): void
    {
        $this->stageCallback = $callback;
    }

    public function stage(string $key, ?int $current = null, ?int $total = null): void
    {
        $this->stages[] = $key;

        if ($key === 'mods') {
            $this->modCounts[] = [$current, $total];
        }

        $callback = $this->stageCallback;

        if ($callback !== null) {
            $callback($key, $current, $total);
        }
    }

    public function download(string $url): string
    {
        return $this->routes[$url] ?? $this->archivePath;
    }

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
        $this->offsets[] = ['bytes' => $completedBytes, 'total' => $totalBytes];
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void
    {
    }

    public function setBatchProgressCallback(?callable $callback): void
    {
        $this->batchCallback = $callback;
    }

    public function downloadBatch(array $tasks): array
    {
        $this->batchCalls++;

        $results = [];
        $done = 0;

        foreach ($tasks as $task) {
            @file_put_contents(
                $task['destination'],
                str_repeat('M', (int) $task['bytes']),
            );

            $results[$task['id']] = $task['destination'];
            $done++;

            $callback = $this->batchCallback;

            if ($callback !== null) {
                $states = [];

                foreach ($tasks as $index => $other) {
                    $states[$other['id']] = [
                        'downloaded_bytes' => $index < $done
                            ? (int) $other['bytes']
                            : 0,
                        'total_bytes' => (int) $other['bytes'],
                        'done' => $index < $done,
                        'failed' => false,
                    ];
                }

                $callback($states);
            }
        }

        return $results;
    }
}

function buildStageZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
}

$projectResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 314768,
        'name' => 'Prominence 2 RPG',
        'summary' => 'A dark fantasy modpack.',
        'classId' => 4471,
    ],
]);

$filesResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'displayName' => 'V1.0.0',
            'fileName' => 'Prominence-2-RPG-1.0.0.zip',
            'releaseType' => 1,
            'gameVersions' => ['Fabric', '1.20.4'],
            'downloadUrl' => 'https://cdn.example/prominence.zip',
        ],
    ],
]);

$modsResolveResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 1001,
            'fileName' => 'essential-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/essential-mod.jar',
            'fileLength' => 512,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$clientPackPath = $temporaryRoot . '/client-pack.zip';
buildStageZip($clientPackPath, [
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
        ],
    ]),
    'overrides/config/install.toml' => 'server-config',
]);

$downloader = new StageRecordingDownloader($clientPackPath);

$provider = new CurseForgeProvider(
    new StageFakeHttpClient([
        $projectResponse,
        $filesResponse,
        $modsResolveResponse,
    ]),
    $downloader,
    'TEST-KEY',
    $temporaryRoot,
);

$package = $provider->getPackage('curseforge://314768');

// The whole point of the stepped display: an install names each phase it
// really walks, in the order it walks it. The counts refresh on the last step
// is the same step reported again, which the card keeps as one row.
if (
    $downloader->stages
    !== ['archive', 'manifest', 'extract', 'mods', 'mods', 'mods']
) {
    throw new RuntimeException(
        'Unexpected CurseForge stage sequence: '
            . implode(', ', $downloader->stages),
    );
}

$steps = array_values(array_unique($downloader->stages));

if ($steps !== ['archive', 'manifest', 'extract', 'mods']) {
    throw new RuntimeException(
        'Stepped display collapsed into unexpected rows: '
            . implode(', ', $steps),
    );
}

pass('client-pack install announces archive, manifest, extract and mods');

// Each step owns its own window: the overrides footprint for extraction, then
// the manifest mods footprint for the downloads that follow.
$overrideTotals = array_column($downloader->offsets, 'total');

if (!in_array(strlen('server-config'), $overrideTotals, true)) {
    throw new RuntimeException(
        'Extract stage did not anchor on the overrides footprint: '
            . json_encode($downloader->offsets),
    );
}

if (!in_array(512, $overrideTotals, true)) {
    throw new RuntimeException(
        'Mods stage did not anchor on the manifest mods footprint: '
            . json_encode($downloader->offsets),
    );
}

pass('every stage anchors its own progress window');

// The mods step reports how many of the manifest's files it has resolved, so
// the card can read "1 / 1" while that step runs.
if ($downloader->batchCalls !== 1) {
    throw new RuntimeException('Manifest mods were not fetched as one batch.');
}

// The step opens at zero and closes on the full total, whatever the engine's
// last progress tick happened to see.
$counts = $downloader->modCounts;

if (($counts[0] ?? null) !== [0, 1]) {
    throw new RuntimeException(
        'Mods stage did not open at zero: ' . json_encode($counts),
    );
}

if (end($counts) !== [1, 1]) {
    throw new RuntimeException(
        'Mods stage did not close on its full count: ' . json_encode($counts),
    );
}

foreach ($counts as $entry) {
    if ($entry[1] !== 1) {
        throw new RuntimeException(
            'Mods stage lost its total: ' . json_encode($counts),
        );
    }
}

pass('mods stage reports fetched-file counts');

if (!is_dir($package->archivePath)) {
    throw new RuntimeException('Install did not produce a package directory.');
}

$provider->cleanup($package);

// ── Part 3: a Modrinth mrpack install announces its own steps ────────

$mrpackPath = $temporaryRoot . '/stage.mrpack';
buildStageZip($mrpackPath, [
    'modrinth.index.json' => json_encode([
        'formatVersion' => 1,
        'files' => [
            [
                'path' => 'mods/index.jar',
                'downloads' => ['https://cdn.example/index.jar'],
                'fileSize' => 64,
            ],
        ],
    ]),
    'overrides/config/server.toml' => 'server-config',
]);

$indexJarPath = $temporaryRoot . '/index.jar';
file_put_contents($indexJarPath, str_repeat('J', 64));

$projectResponse = new ProviderHttpResponse(200, [
    'id' => 'AANobbMI',
    'slug' => 'prominence-2-rpg',
    'title' => 'Prominence 2 RPG',
    'project_type' => 'modpack',
    'game_versions' => ['1.20.1'],
]);

$versionsResponse = new ProviderHttpResponse(200, [
    [
        'id' => 'version-001',
        'version_number' => '1.0.0',
        'version_type' => 'release',
        'game_versions' => ['1.20.1'],
        'loaders' => ['fabric'],
        'files' => [
            [
                'primary' => true,
                'filename' => 'stage.mrpack',
                'url' => 'https://cdn.example/stage.mrpack',
                'size' => 4096,
            ],
        ],
    ],
]);

$downloader = new StageRecordingDownloader(
    $mrpackPath,
    ['https://cdn.example/index.jar' => $indexJarPath],
);

$provider = new ModrinthProvider(
    new StageFakeHttpClient([$projectResponse, $versionsResponse]),
    $downloader,
    $temporaryRoot,
);

$package = $provider->getPackage('modrinth://prominence-2-rpg');

if (
    $downloader->stages
    !== ['archive', 'index', 'mods']
) {
    throw new RuntimeException(
        'Unexpected Modrinth stage sequence: '
            . implode(', ', $downloader->stages),
    );
}

pass('mrpack install announces archive, index and mods');

// The archive step's window is the mrpack's own size, not a virtual anchor
// reserved for the files that come later.
if (($downloader->offsets[0]['total'] ?? null) !== 4096) {
    throw new RuntimeException(
        'Archive stage did not anchor on the archive footprint: '
            . json_encode($downloader->offsets),
    );
}

pass('mrpack archive stage anchors on the archive itself');

$provider->cleanup($package);

echo "\nAll install stage tests passed.\n";
