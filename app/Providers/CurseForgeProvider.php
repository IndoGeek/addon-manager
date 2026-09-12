<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CurseForgeProvider implements ModpackProvider
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const API_KEY_HEADER = 'X-Api-Key';

    /**
     * @var array<string, true> Paths of temporary archives awaiting cleanup.
     */
    private array $temporaryPackages = [];

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly Downloader $downloader,
        private readonly ?string $apiKey,
    ) {
    }

    public function supports(string $source): bool
    {
        return preg_match('/^curseforge:\/\/\d+$/', trim($source)) === 1;
    }

    public function getMetadata(string $source): ModpackMetadata
    {
        $projectId = $this->resolveProjectId($source);

        $this->assertConfigured();

        $project = $this->fetchProject($projectId);

        $files = $this->fetchFiles($projectId);

        $file = $this->selectFile($files);

        $version = $this->nullableString($file['displayName'] ?? null)
            ?? $this->nullableString($file['fileName'] ?? null);

        if ($version === null) {
            throw new InvalidArgumentException(
                'The CurseForge project has no version information.',
            );
        }

        [$minecraftVersion, $loader] = $this->parseGameVersions(
            $file['gameVersions'] ?? [],
        );

        if ($minecraftVersion === null) {
            throw new InvalidArgumentException(
                'The CurseForge file does not declare a supported Minecraft version.',
            );
        }

        return new ModpackMetadata(
            id: (string) ($project['id'] ?? $projectId),
            name: (string) ($project['name'] ?? ''),
            version: (string) $version,
            minecraftVersion: $minecraftVersion,
            loader: $loader,
            description: $this->nullableString($project['summary'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->nullableString(
                    $project['links']['iconUrl'] ?? null,
                ),
            ),
            source: 'curseforge://' . $projectId,
        );
    }

    public function getPackage(string $source): ModpackPackage
    {
        $projectId = $this->resolveProjectId($source);

        $this->assertConfigured();

        $project = $this->fetchProject($projectId);

        $files = $this->fetchFiles($projectId);

        $file = $this->selectFile($files);

        if ($file === null) {
            throw new InvalidArgumentException(
                'The CurseForge project has no files.',
            );
        }

        $downloadUrl = (string) ($file['downloadUrl'] ?? '');

        if ($downloadUrl === '') {
            throw new InvalidArgumentException(
                'The CurseForge modpack does not provide a public download URL.',
            );
        }

        $archivePath = $this->downloader->download($downloadUrl);

        $this->temporaryPackages[$archivePath] = true;

        try {
            $this->assertServerPack($archivePath);

            return new ModpackPackage(
                archivePath: $archivePath,
                source: 'curseforge://' . $projectId,
            );
        } catch (Throwable $exception) {
            $this->removeTracked($archivePath);

            throw $exception;
        }
    }

    public function cleanup(ModpackPackage $package): void
    {
        $this->removeTracked($package->archivePath);
    }

    private function removeTracked(string $path): void
    {
        if (!isset($this->temporaryPackages[$path])) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }

        unset($this->temporaryPackages[$path]);
    }

    private function resolveProjectId(string $source): string
    {
        if (!$this->supports($source)) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        return substr(trim($source), strlen('curseforge://'));
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new InvalidArgumentException(
                'CurseForge is not configured. Set the CURSEFORGE_API_KEY server-side environment variable.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProject(string $projectId): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $projectId,
                headers: $this->headers(),
            );

            return $response->body['data'] ?? [];
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFiles(string $projectId): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $projectId . '/files',
                query: [
                    'pageSize' => 20,
                    'pageIndex' => 0,
                ],
                headers: $this->headers(),
            );

            $files = $response->body['data'] ?? [];

            return is_array($files) ? $files : [];
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            self::API_KEY_HEADER . ': ' . $this->apiKey,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     *
     * @return array<string, mixed>|null
     */
    private function selectFile(array $files): ?array
    {
        if ($files === []) {
            return null;
        }

        $sorted = $files;

        usort($sorted, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['fileDate'] ?? ''));
            $bTime = strtotime((string) ($b['fileDate'] ?? ''));

            return $bTime <=> $aTime;
        });

        foreach ($sorted as $file) {
            if ((int) ($file['releaseType'] ?? 0) === 1) {
                return $file;
            }
        }

        return $sorted[0];
    }

    /**
     * @param array<mixed> $gameVersions
     *
     * @return array{0: string|null, 1: string|null} [minecraft, loader]
     */
    private function parseGameVersions(array $gameVersions): array
    {
        $loaderNames = [
            'Forge',
            'Fabric',
            'Quilt',
            'NeoForge',
            'LiteLoader',
            'Rift',
        ];

        $minecraftVersion = null;
        $loader = null;

        foreach ($gameVersions as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            if (in_array($entry, $loaderNames, true)) {
                if ($loader === null) {
                    $loader = $entry;
                }

                continue;
            }

            if (
                $minecraftVersion === null
                && preg_match('/^\d+\.\d+(\.\d+)+$/', $entry) === 1
            ) {
                $minecraftVersion = $entry;
            }
        }

        return [$minecraftVersion, $loader];
    }

    private function assertServerPack(string $archivePath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new InvalidArgumentException(
                'The downloaded CurseForge file is not a valid archive.',
            );
        }

        try {
            if ($zip->statName('manifest.json') !== false) {
                throw new UnsupportedModpackPackageException(
                    'This CurseForge modpack is a client pack that requires mod file resolution, which is not supported yet. Choose a CurseForge server pack instead.',
                );
            }
        } finally {
            $zip->close();
        }
    }

    private function requestFailure(
        ProviderHttpException $exception,
    ): InvalidArgumentException {
        if ($exception->status() === 404) {
            return new InvalidArgumentException(
                'The CurseForge project was not found.',
            );
        }

        if (
            $exception->status() !== null
            && $exception->status() >= 400
            && $exception->status() < 500
        ) {
            return new InvalidArgumentException(
                'CurseForge rejected the request.',
            );
        }

        return new InvalidArgumentException(
            'Unable to load the modpack from CurseForge.',
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function validImageUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (($parts['host'] ?? '') === '') {
            return null;
        }

        return $url;
    }
}