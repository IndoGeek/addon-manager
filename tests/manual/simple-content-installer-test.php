<?php

// Tests for the simple content (mods) install path: - SimpleContentInstaller filename sanitization and target v...

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionFileResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\ConcurrentDownloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\SimpleContentInstaller;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;

final class FakeSimpleDownloader implements Downloader
{
    public function __construct(private readonly string $path) {}

    public function download(string $url): string
    {
        // Mirror the real DownloadManager: every call materializes its own temporary file which the caller owns afterwa...
        $copy = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        copy($this->path, $copy);

        return $copy;
    }

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void {}

    public function isCancelled(): bool
    {
        return false;
    }

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void {}
}

final class FakeBatchDownloader implements ConcurrentDownloader
{
    public int $batchCalls = 0;

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $batches = [];

    /** @var array<int, array{0: int, 1: ?int}> */
    public array $offsets = [];

    /** @var array<int, array<string, mixed>> */
    public array $reportedFiles = [];

    /** @var array<int, int> */
    public array $skips = [];

    // @var null|callable(array<int, array<string, mixed>>): void
    private $batchProgressCallback = null;

    public function __construct(private readonly string $path) {}

    public function download(string $url): string
    {
        $copy = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        copy($this->path, $copy);

        return $copy;
    }

    public function downloadBatch(array $tasks): array
    {
        $this->batchCalls++;
        $this->batches[] = $tasks;

        $results = [];
        $files = [];

        foreach ($tasks as $task) {
            if (in_array($task['id'], $this->skips, true)) {
                $results[$task['id']] = null;
                continue;
            }

            copy($this->path, $task['destination']);

            $results[$task['id']] = $task['destination'];
            $files[$task['id']] = [
                'downloaded_bytes' => (int) filesize($task['destination']),
                'total_bytes' => (int) ($task['bytes'] ?? 0),
                'done' => true,
                'failed' => false,
            ];
        }

        $this->reportedFiles[] = $files;

        if ($this->batchProgressCallback !== null) {
            ($this->batchProgressCallback)($files);
        }

        return $results;
    }

    public function setBatchProgressCallback(?callable $callback): void
    {
        $this->batchProgressCallback = $callback;
    }

    public function setProgressCallback(?callable $callback): void {}

    public function setCancelChecker(?callable $checker): void {}

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
        $this->offsets[] = [$completedBytes, $totalBytes];
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void {}
}

final class RecordingTarget implements ServerFileTarget
{
    public array $files = [];

    public array $directories = [];

    public function exists(string $relativePath): bool
    {
        return isset($this->files[$relativePath]);
    }

    public function isDirectory(string $relativePath): bool
    {
        return isset($this->directories[$relativePath]);
    }

    public function read(string $relativePath): string
    {
        return $this->files[$relativePath] ?? '';
    }

    public function write(string $relativePath, string $contents): void
    {
        $this->files[$relativePath] = $contents;
    }

    public function putFile(string $relativePath, string $sourcePath): void
    {
        $this->files[$relativePath] = (string) file_get_contents($sourcePath);
    }

    public function getFile(string $relativePath, string $destinationPath): void {}

    public function delete(string $relativePath): void
    {
        unset($this->files[$relativePath]);
    }

    public function isEmptyDirectory(string $relativePath): bool
    {
        return true;
    }

    public function removeDirectory(string $relativePath): void
    {
        unset($this->directories[$relativePath]);
    }

    public function ensureDirectory(string $relativePath): void
    {
        $this->directories[$relativePath] = true;
    }
}

final class FakeResolverHttp implements ProviderHttpClient
{
    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(private array $handlers) {}

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
        throw new RuntimeException('Unexpected POST.');
    }
}

function passSimple(string $name): void
{
    echo "PASS: {$name}\n";
}

$root = sys_get_temp_dir()
    . '/simple-content-'
    . bin2hex(random_bytes(8));

mkdir($root, 0750, true);

// ── SimpleContentInstaller ────────────────────────────────────────────

$jar = $root . '/fake-mod.jar';
file_put_contents($jar, 'fake jar bytes');

$downloader = new FakeSimpleDownloader($jar);
$installer = new SimpleContentInstaller($downloader);
$target = new RecordingTarget();

$result = $installer->install(
    sourceUrl: 'https://cdn.modrinth.com/data/AANobbMI/versions/abc/sodium.jar',
    filename: 'sodium-0.6.0.jar',
    targetDirectory: 'mods',
    target: $target,
);

if ($result['path'] !== 'mods/sodium-0.6.0.jar') {
    throw new RuntimeException('Unexpected install path: ' . $result['path']);
}

if (($target->files['mods/sodium-0.6.0.jar'] ?? '') !== 'fake jar bytes') {
    throw new RuntimeException('File content was not uploaded.');
}

passSimple('jar uploaded into mods directory');

// Path traversal in the filename is neutralized.
$installer->install(
    sourceUrl: 'https://cdn.modrinth.com/x/a.jar',
    filename: '../../evil.jar',
    targetDirectory: 'mods',
    target: $target,
);

if (isset($target->files['evil.jar']) || isset($target->files['../evil.jar'])) {
    throw new RuntimeException('Path traversal was not neutralized.');
}

passSimple('filename traversal neutralized');

// A bad target directory is rejected.
try {
    $installer->install(
        sourceUrl: 'https://cdn.modrinth.com/x/a.jar',
        filename: 'a.jar',
        targetDirectory: '../escape',
        target: $target,
    );
    throw new RuntimeException('Bad target directory accepted.');
} catch (InvalidArgumentException) {
    passSimple('invalid target directory rejected');
}

// ── Multi-file installs run through one concurrent batch ──────────────

$workspace = $root . '/batch-workspace';
$batch = new FakeBatchDownloader($jar);
$batchInstaller = new SimpleContentInstaller($batch);
$batchTarget = new RecordingTarget();

$reportedProgress = [];
$uploads = [];

$batchResults = $batchInstaller->installBatch(
    items: [
        [
            'url' => 'https://cdn.modrinth.com/data/A/versions/x/sodium.jar',
            'bytes' => 10,
            'filename' => 'sodium-fabric-1-20-1.jar',
            'directory' => 'mods',
        ],
        [
            'url' => 'https://cdn.modrinth.com/data/B/versions/x/fabric-api.jar',
            'bytes' => 20,
            'filename' => 'fabric-api-fabric-1-20-1.jar',
            'directory' => 'mods',
        ],
        [
            'url' => 'https://cdn.modrinth.com/data/C/versions/x/terralith.zip',
            'bytes' => 30,
            'filename' => 'terralith-datapack-1-20-1.zip',
            'directory' => 'world/datapacks',
        ],
    ],
    target: $batchTarget,
    workspace: $workspace,
    onFileProgress: function (array $files) use (&$reportedProgress): void {
        $reportedProgress = $files;
    },
    onUpload: function (int $index, int $count) use (&$uploads): void {
        $uploads[] = [$index, $count];
    },
);

if ($batch->batchCalls !== 1) {
    throw new RuntimeException(
        'A multi-file install must download in a single concurrent batch.'
    );
}

if (count($batch->batches[0] ?? []) !== 3) {
    throw new RuntimeException('Not every file joined the download batch.');
}

passSimple('multi-file install downloads every file in one batch');

// The declared sizes anchor the aggregate bar and each file's own total.
if (($batch->offsets[0] ?? null) !== [0, 60]) {
    throw new RuntimeException(
        'The batch must be anchored on the declared total size.'
    );
}

if (count($reportedProgress) !== 3) {
    throw new RuntimeException('Per-file progress was not reported.');
}

foreach ($reportedProgress as $state) {
    if (($state['done'] ?? false) !== true) {
        throw new RuntimeException('A completed file was not marked done.');
    }
}

passSimple('per-file progress reaches the caller');

// Each file lands in its own directory, keyed by its item index.
if (!isset($batchTarget->files['mods/sodium-fabric-1-20-1.jar'])) {
    throw new RuntimeException('The mod was not uploaded into mods/.');
}

if (!isset($batchTarget->files['mods/fabric-api-fabric-1-20-1.jar'])) {
    throw new RuntimeException('The dependency was not uploaded into mods/.');
}

if (
    !isset($batchTarget->files['world/datapacks/terralith-datapack-1-20-1.zip'])
) {
    throw new RuntimeException(
        'The datapack was not uploaded into world/datapacks/.'
    );
}

if (($batchResults[2]['path'] ?? '') !== 'world/datapacks/terralith-datapack-1-20-1.zip') {
    throw new RuntimeException('Batch results are not keyed by item index.');
}

if ($uploads !== [[0, 3], [1, 3], [2, 3]]) {
    throw new RuntimeException('Upload progress was not reported per file.');
}

passSimple('every file uploads into its own target directory');

// The scratch workspace is removed once the run finishes.
if (is_dir($workspace)) {
    throw new RuntimeException('The download workspace was not cleaned up.');
}

passSimple('download workspace cleaned up');

// A downloader without the concurrent engine falls back to one at a time.
$sequential = new SimpleContentInstaller(new FakeSimpleDownloader($jar));
$sequentialTarget = new RecordingTarget();

$sequentialResults = $sequential->installBatch(
    items: [
        [
            'url' => 'https://cdn.modrinth.com/a.jar',
            'bytes' => 5,
            'filename' => 'a-fabric-1-20-1.jar',
            'directory' => 'mods',
        ],
        [
            'url' => 'https://cdn.modrinth.com/b.jar',
            'bytes' => 5,
            'filename' => 'b-fabric-1-20-1.jar',
            'directory' => 'mods',
        ],
    ],
    target: $sequentialTarget,
    workspace: $root . '/unused-workspace',
);

if (count($sequentialResults) !== 2) {
    throw new RuntimeException('Sequential fallback lost a file.');
}

if (
    !isset($sequentialTarget->files['mods/a-fabric-1-20-1.jar'])
    || !isset($sequentialTarget->files['mods/b-fabric-1-20-1.jar'])
) {
    throw new RuntimeException('Sequential fallback did not upload both files.');
}

passSimple('sequential fallback installs every file');

// A hostile target directory is refused before anything downloads.
$reject = new FakeBatchDownloader($jar);

try {
    (new SimpleContentInstaller($reject))->installBatch(
        items: [
            [
                'url' => 'https://cdn.modrinth.com/a.jar',
                'filename' => 'a.jar',
                'directory' => '../escape',
            ],
        ],
        target: new RecordingTarget(),
        workspace: $root . '/reject-workspace',
    );
    throw new RuntimeException('Bad batch directory accepted.');
} catch (InvalidArgumentException) {
    if ($reject->batchCalls !== 0) {
        throw new RuntimeException('Refused batch still downloaded.');
    }

    passSimple('invalid batch target directory rejected');
}

// A file the engine could not fetch aborts the run and cleans up.
$failing = new FakeBatchDownloader($jar);
$failing->skips = [1];
$failingWorkspace = $root . '/failing-workspace';

try {
    (new SimpleContentInstaller($failing))->installBatch(
        items: [
            [
                'url' => 'https://cdn.modrinth.com/a.jar',
                'filename' => 'a.jar',
                'directory' => 'mods',
            ],
            [
                'url' => 'https://cdn.modrinth.com/b.jar',
                'filename' => 'b.jar',
                'directory' => 'mods',
            ],
        ],
        target: new RecordingTarget(),
        workspace: $failingWorkspace,
    );
    throw new RuntimeException('An unavailable file was ignored.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'An unavailable file was ignored.') {
        throw $exception;
    }

    if (is_dir($failingWorkspace)) {
        throw new RuntimeException(
            'A failed batch left its workspace behind.'
        );
    }

    passSimple('a missing file aborts the run and cleans up');
}

// ── CatalogVersionFileResolver ────────────────────────────────────────

$versionPayload = [
    'id' => 'version001',
    'version_number' => '0.6.0',
    'files' => [
        [
            'primary' => false,
            'filename' => 'sodium-sources.jar',
            'url' => 'https://cdn.modrinth.com/alt/sources.jar',
            'size' => 10,
        ],
        [
            'primary' => true,
            'filename' => 'sodium-0.6.0.jar',
            'url' => 'https://cdn.modrinth.com/data/A/versions/x/sodium-0.6.0.jar',
            'size' => 1234,
            'hashes' => ['sha1' => 'deadbeef'],
        ],
    ],
];

$resolver = new CatalogVersionFileResolver(
    new FakeResolverHttp([
        new ProviderHttpResponse(200, $versionPayload),
    ]),
);

$file = $resolver->resolve('modrinth', 'AANobbMI', 'version001');

if ($file['filename'] !== 'sodium-0.6.0.jar') {
    throw new RuntimeException('Primary file was not selected.');
}

if ($file['sha1'] !== 'deadbeef') {
    throw new RuntimeException('sha1 hash was not surfaced.');
}

passSimple('primary file selected from version payload');

// Non-Modrinth providers are refused.
try {
    $resolver->resolve('curseforge', 'x', 'version001');
    throw new RuntimeException('CurseForge accepted by the resolver.');
} catch (CatalogProviderException) {
    passSimple('non-modrinth provider rejected');
}

// A non-CDN URL is rejected.
$resolver = new CatalogVersionFileResolver(
    new FakeResolverHttp([
        new ProviderHttpResponse(200, [
            'id' => 'version001',
            'files' => [
                [
                    'primary' => true,
                    'filename' => 'evil.jar',
                    'url' => 'https://evil.example/evil.jar',
                ],
            ],
        ]),
    ]),
);

try {
    $resolver->resolve('modrinth', 'x', 'version001');
    throw new RuntimeException('Untrusted URL accepted.');
} catch (CatalogProviderException) {
    passSimple('non-CDN file URL rejected');
}

@unlink($jar);
@rmdir($root);

echo "\nAll simple content installer tests passed.\n";
