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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpResponse;

final class FakeProviderHttpClient implements ProviderHttpClient
{
    /** @var array<int, array{url: string, headers: array}> */
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
    public function __construct(private readonly string $archivePath)
    {
    }

    public function download(string $url): string
    {
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
}

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

function assertRejected(string $name, callable $run): void
{
    try {
        $run();
        throw new RuntimeException("Expected rejection: {$name}");
    } catch (InvalidArgumentException) {
        pass($name);
    }
}

$temporaryRoot = sys_get_temp_dir()
    . '/modpack-provider-security-'
    . bin2hex(random_bytes(8));

mkdir($temporaryRoot, 0750, true);

$project = [
    'id' => 'AANobbMI',
    'slug' => 'prominence-2-rpg',
    'title' => 'Prominence 2 RPG',
    'project_type' => 'modpack',
];

$versions = [
    [
        'version_number' => '1.0.0',
        'version_type' => 'release',
        'date_published' => '2024-01-01T00:00:00Z',
        'game_versions' => ['1.21.1'],
        'loaders' => ['fabric'],
        'files' => [
            [
                'primary' => true,
                'filename' => 'pack.mrpack',
                'url' => 'https://cdn.example/pack.mrpack',
            ],
        ],
    ],
];

// Modrinth: version list containing a non-object entry must be rejected.
$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, $project),
    new ProviderHttpResponse(200, [['version_number' => '1.0.0'], 'garbage']),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

assertRejected('modrinth rejects malformed version list', function () use (
    $provider,
): void {
    $provider->getMetadata('modrinth://prominence-2-rpg');
});

// Modrinth: a missing project_type is reported as a controlled error rather
// than a crash.
$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, ['id' => 'AANobbMI']),
]);
$provider = new ModrinthProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $temporaryRoot,
);

assertRejected('modrinth missing project_type is controlled', function () use (
    $provider,
): void {
    try {
        $provider->getMetadata('modrinth://prominence-2-rpg');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'not a modpack')) {
            throw new RuntimeException('Unexpected error message.');
        }

        throw $exception;
    }
});

// CurseForge: a non-array data payload is rejected cleanly.
$apiKey = 'secret-key-7f3a9c';
$http = new FakeProviderHttpClient([
    new ProviderHttpResponse(200, ['data' => 'not-an-array']),
]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $apiKey,
);

assertRejected('curseforge rejects malformed project payload', function () use (
    $provider,
): void {
    $provider->getMetadata('curseforge://314768');
});

// CurseForge: the API key is sent on requests but never appears in URLs or
// error messages.
$http = new FakeProviderHttpClient([
    new ProviderHttpException('The provider returned an HTTP 500 response.', 500),
]);
$provider = new CurseForgeProvider(
    $http,
    new FakeDownloader('/does/not/matter'),
    $apiKey,
);

try {
    $provider->getMetadata('curseforge://314768');
    throw new RuntimeException('Expected a CurseForge failure.');
} catch (InvalidArgumentException $exception) {
    if (str_contains($exception->getMessage(), $apiKey)) {
        throw new RuntimeException(
            'The CurseForge API key leaked into an error message.',
        );
    }

    if (str_contains($exception->getMessage(), 'secret-key')) {
        throw new RuntimeException(
            'The CurseForge API key leaked into an error message.',
        );
    }
}

pass('curseforge key absent from error messages');

$request = $http->requests[0] ?? null;

if ($request === null) {
    throw new RuntimeException('No CurseForge request was recorded.');
}

$headers = implode("\n", $request['headers']);

if (str_contains($headers, 'secret-key-7f3a9c')) {
    if (str_contains($request['url'], $apiKey)) {
        throw new RuntimeException(
            'The CurseForge API key leaked into the request URL.',
        );
    }

    echo "PASS: curseforge key sent only inside request headers\n";
} else {
    throw new RuntimeException(
        'The CurseForge API key was not sent with the request.',
    );
}

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

rmdir($temporaryRoot);

echo "\nAll provider security tests passed.\n";