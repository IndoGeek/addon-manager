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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @param array<int, ProviderHttpResponse|Throwable> $handlers */
    public function __construct(private array $handlers)
    {
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

class FakeDownloader implements Downloader
{
    public int $calls = 0;

    /** @param array<string, string> $routes URL => archive path */
    public function __construct(
        private readonly string $archivePath,
        private array $routes = [],
    ) {
    }

    public function download(string $url): string
    {
        $this->calls++;

        return $this->routes[$url] ?? $this->archivePath;
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

/**
 * @return list<string>
 */
function packageDirectoryFiles(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $files[] = ltrim(
            substr($file->getPathname(), strlen($directory)),
            DIRECTORY_SEPARATOR,
        );
    }

    sort($files);

    return $files;
}

function removeDirectoryTree(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($directory);
}

const SECRET_KEY = 'SUPER-SECRET-CF-KEY';

$temporaryRoot = sys_get_temp_dir() . '/curseforge-large-entry-test';
@mkdir($temporaryRoot, 0750, true);

// A large, incompressible override payload forces the streaming writer through
// many 256 KiB chunks and exercises local-header patching (CRC + sizes only
// known once the stream has been fully consumed).
$bigWorld = random_bytes(2_621_440 + 1024); // 2.5 MiB + tail

$clientPackPath = $temporaryRoot . '/client-pack.zip';
buildZip($clientPackPath, [
    'manifest.json' => json_encode([
        'minecraft' => [
            'version' => '1.20.4',
            'modLoaders' => [['id' => 'fabric-0.14.21', 'primary' => true]],
        ],
        'manifestType' => 'minecraftModpack',
        'manifestVersion' => 1,
        'name' => 'Large Override Pack',
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
    'overrides/world/big-region.mca' => $bigWorld,
    'overrides/config/meta.toml' => 'meta',
]);

$modJarPath = $temporaryRoot . '/downloaded-mod.jar';
file_put_contents($modJarPath, 'MODJAR');

$projectResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 314768,
        'name' => 'Large Override Pack',
        'summary' => 'Packs a multi-megabyte override entry.',
        'classId' => 4471,
        'links' => [
            'websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/large-override-pack',
            'iconUrl' => 'https://cdn.example/icon.png',
        ],
    ],
]);

$filesResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'displayName' => 'V1.0.0',
            'fileName' => 'large-override-pack-1.0.0.zip',
            'fileDate' => '2024-01-01T00:00:00Z',
            'releaseType' => 1,
            'gameVersions' => ['Fabric', '1.20.4'],
            'downloadUrl' => 'https://cdn.example/large-override-pack.zip',
        ],
    ],
]);

$filesResolveResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 1001,
            'fileName' => 'essential-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/essential-mod.jar',
            'fileLength' => strlen($bigWorld),
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

$entries = packageDirectoryFiles($package->archivePath);

if ($entries !== ['config/meta.toml', 'mods/essential-mod.jar', 'world/big-region.mca']) {
    throw new RuntimeException(
        'Unexpected normalized contents: ' . implode(',', $entries),
    );
}

if (file_get_contents($package->archivePath . '/world/big-region.mca') !== $bigWorld) {
    throw new RuntimeException('Large entry did not round-trip byte-for-byte.');
}

if (file_get_contents($package->archivePath . '/config/meta.toml') !== 'meta') {
    throw new RuntimeException('Small override entry did not round-trip.');
}

if (file_get_contents($package->archivePath . '/mods/essential-mod.jar') !== 'MODJAR') {
    throw new RuntimeException('Downloaded mod entry did not round-trip.');
}

if (is_file($package->archivePath . '/manifest.json')) {
    throw new RuntimeException('manifest.json leaked into the normalized package.');
}

removeDirectoryTree($package->archivePath);
@unlink($modJarPath);

pass('large multi-chunk override entry round-trips through the streaming package writer');

// The writer must also abort mid-stream when cancellation is requested. The
// flag flips only after the first few checks so the abort happens inside
// writeArchiveEntry() once some bytes have already been streamed.
$cancellingDownloader = new class ($clientPackPath) extends FakeDownloader {
    private int $checks = 0;

    public function isCancelled(): bool
    {
        $this->checks++;

        return $this->checks > 4;
    }
};

buildZip($clientPackPath, [
    'manifest.json' => json_encode([
        'minecraft' => ['version' => '1.20.4'],
        'manifestType' => 'minecraftModpack',
        'manifestVersion' => 1,
        'name' => 'Large Override Pack',
        'version' => '1.0.0',
        'overrides' => 'overrides',
        'files' => [],
    ]),
    'overrides/world/big-region.mca' => $bigWorld,
]);

try {
    $http = new FakeProviderHttpClient([
        $projectResponse,
        $filesResponse,
        $filesResolveResponse,
    ]);
    $provider = new CurseForgeProvider(
        $http,
        $cancellingDownloader,
        SECRET_KEY,
        $temporaryRoot,
    );

    $provider->getPackage('curseforge://314768');
    throw new RuntimeException('Cancellation did not abort the archive build.');
} catch (Throwable $exception) {
    if (
        get_class($exception)
        !== Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException::class
    ) {
        throw new RuntimeException('Expected an installation-cancelled exception.');
    }
}

@unlink($clientPackPath);

pass('archive build aborts mid-entry when cancellation is requested');