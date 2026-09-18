<?php

// Functional tests for partial package builds (getPackageForPaths), used by the restore flow: - only the requested...

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix =
        'Pterodactyl\\BlueprintFramework\\Extensions\\modpackinstaller\\';

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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModpackPackage;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\PartialPackageProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class PartialFakeProviderHttpClient implements ProviderHttpClient
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

final class RecordingFakeDownloader implements Downloader
{
    /** @var list<string> */
    public array $downloadedUrls = [];

    /** @param array<string, string> $routes URL => file path to return */
    public function __construct(
        private readonly array $routes = [],
    ) {
    }

    public function download(string $url): string
    {
        $this->downloadedUrls[] = $url;

        if (isset($this->routes[$url])) {
            return $this->routes[$url];
        }

        throw new RuntimeException('No route for ' . $url);
    }

    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function setCancelChecker(?callable $checker): void
    {
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

// Lists the relative paths inside a normalized package directory.
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

const SECRET_KEY = 'SUPER-SECRET-CF-KEY';

$temporaryRoot = sys_get_temp_dir()
    . '/partial-restore-test-'
    . bin2hex(random_bytes(4));

@mkdir($temporaryRoot, 0750, true);

$projectResponse = new ProviderHttpResponse(200, [
    'data' => [
        'id' => 314768,
        'name' => 'Prominence 2 RPG',
        'summary' => 'A dark fantasy modpack.',
        'classId' => 4471,
        'links' => [
            'websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/prominence-2-rpg',
        ],
    ],
]);

$fileResponse = new ProviderHttpResponse(200, [
    'data' => [
        'displayName' => 'V1.0.0',
        'fileName' => 'Prominence-2-RPG-1.0.0.zip',
        'fileDate' => '2024-01-01T00:00:00Z',
        'releaseType' => 1,
        'gameVersions' => ['Fabric', '1.20.4'],
        'downloadUrl' => 'https://cdn.example/prominence.zip',
    ],
]);

// The unpinned source resolves through the file-list endpoint first.
$filesListResponse = new ProviderHttpResponse(200, [
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

// The client pack: two overrides files and three manifest mods.
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
            ],
            [
                'projectID' => 456,
                'fileID' => 1002,
                'required' => true,
            ],
            [
                'projectID' => 789,
                'fileID' => 1003,
                'required' => true,
            ],
        ],
    ]),
    'overrides/config/alpha.toml' => 'alpha-config',
    'overrides/config/beta.toml' => 'beta-config',
]);

foreach (['alpha' => 'MODA', 'beta' => 'MODB', 'gamma' => 'MODG'] as $key => $body) {
    file_put_contents($temporaryRoot . "/mod-{$key}.jar", $body);
}

$filesResolveResponse = new ProviderHttpResponse(200, [
    'data' => [
        [
            'id' => 1001,
            'fileName' => 'alpha-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/alpha-mod.jar',
            'fileLength' => 512,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 1002,
            'fileName' => 'beta-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/beta-mod.jar',
            'fileLength' => 256,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
        [
            'id' => 1003,
            'fileName' => 'gamma-mod.jar',
            'downloadUrl' => 'https://cdn.example/mods/gamma-mod.jar',
            'fileLength' => 128,
            'fileStatus' => 4,
            'isAvailable' => true,
        ],
    ],
]);

$downloader = new RecordingFakeDownloader([
    'https://cdn.example/prominence.zip' => $clientPackPath,
    'https://cdn.example/mods/gamma-mod.jar' => $temporaryRoot . '/mod-gamma.jar',
]);

$http = new PartialFakeProviderHttpClient([
    $projectResponse,
    $filesListResponse,
    $filesResolveResponse,
]);

$provider = new CurseForgeProvider(
    $http,
    $downloader,
    SECRET_KEY,
    $temporaryRoot,
);

if (!$provider instanceof PartialPackageProvider) {
    throw new RuntimeException(
        'CurseForgeProvider must implement PartialPackageProvider.'
    );
}

$package = $provider->getPackageForPaths('curseforge://314768', [
    'config/alpha.toml',
    'mods/gamma-mod.jar',
]);

if (!$package instanceof ModpackPackage) {
    throw new RuntimeException('Partial package was not returned.');
}

$entries = packageDirectoryFiles($package->archivePath);

if (in_array('config/beta.toml', $entries, true)) {
    throw new RuntimeException('Unwanted overrides entry leaked into partial package.');
}

if (!in_array('config/alpha.toml', $entries, true)) {
    throw new RuntimeException('Wanted overrides entry missing from partial package.');
}

if (file_get_contents($package->archivePath . '/config/alpha.toml') !== 'alpha-config') {
    throw new RuntimeException('Wanted overrides entry has wrong contents.');
}

if (!in_array('mods/gamma-mod.jar', $entries, true)) {
    throw new RuntimeException('Wanted mod missing from partial package.');
}

if (file_get_contents($package->archivePath . '/mods/gamma-mod.jar') !== 'MODG') {
    throw new RuntimeException('Wanted mod has wrong contents.');
}

$modDownloads = array_values(array_filter(
    $downloader->downloadedUrls,
    static fn (string $url): bool => str_contains($url, 'mods/'),
));

if ($modDownloads !== ['https://cdn.example/mods/gamma-mod.jar']) {
    throw new RuntimeException(
        'Partial build downloaded unexpected mods: ' . implode(', ', $modDownloads)
    );
}

$provider->cleanup($package);

pass('partial build fetches only the requested overrides and mods');

// A wanted path that no longer exists in the pack must fail loudly.
file_put_contents($temporaryRoot . '/mod-gamma.jar', 'MODG');

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
            ],
            [
                'projectID' => 456,
                'fileID' => 1002,
                'required' => true,
            ],
            [
                'projectID' => 789,
                'fileID' => 1003,
                'required' => true,
            ],
        ],
    ]),
    'overrides/config/alpha.toml' => 'alpha-config',
    'overrides/config/beta.toml' => 'beta-config',
]);

$missingDownLoader = new RecordingFakeDownloader([
    'https://cdn.example/prominence.zip' => $clientPackPath,
    'https://cdn.example/mods/gamma-mod.jar' => $temporaryRoot . '/mod-gamma.jar',
]);

$missingHttp = new PartialFakeProviderHttpClient([
    $projectResponse,
    $filesListResponse,
    $filesResolveResponse,
]);

$missingProvider = new CurseForgeProvider(
    $missingHttp,
    $missingDownLoader,
    SECRET_KEY,
    $temporaryRoot,
);

try {
    $missingProvider->getPackageForPaths('curseforge://314768', [
        'mods/gamma-mod.jar',
        'config/does-not-exist.toml',
    ]);

    throw new RuntimeException(
        'Partial build with an unsourcable path should fail loudly.'
    );
} catch (\Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException) {
    throw new RuntimeException('Unexpected cancellation.');
} catch (\Throwable $exception) {
    if (
        !str_contains($exception->getMessage(), 'does-not-exist.toml')
        && !str_contains($exception->getMessage(), 'could not source')
    ) {
        throw new RuntimeException(
            'Unexpected unsourcable-path error message: '
                . $exception->getMessage()
        );
    }
}

pass('partial build fails loudly when a wanted path is not in the pack');

// Invalid and empty wanted lists are rejected before any network traffic.
$rejectingDownLoader = new RecordingFakeDownloader();

$rejectingProvider = new CurseForgeProvider(
    new PartialFakeProviderHttpClient([]),
    $rejectingDownLoader,
    SECRET_KEY,
    $temporaryRoot,
);

try {
    $rejectingProvider->getPackageForPaths('curseforge://314768', []);

    throw new RuntimeException('Empty wanted list must be rejected.');
} catch (\InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'at least one path')) {
        throw new RuntimeException('Unexpected empty-list error message.');
    }
}

try {
    $rejectingProvider->getPackageForPaths('curseforge://314768', ['../escape.jar']);

    throw new RuntimeException('Unsafe wanted path must be rejected.');
} catch (\InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'invalid path')) {
        throw new RuntimeException('Unsafe path accepted.');
    }
}

if ($rejectingDownLoader->downloadedUrls !== []) {
    throw new RuntimeException('Validation rejections must not touch the network.');
}

pass('partial build rejects empty and unsafe wanted lists');

// Cleanup
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

@rmdir($temporaryRoot);

echo "ALL PARTIAL-RESTORE TESTS PASSED\n";
