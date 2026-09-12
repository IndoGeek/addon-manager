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

    private const MODPACK_CLASS_ID = 4471;

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
        return $this->parseSource($source) !== null;
    }

    public function getMetadata(string $source): ModpackMetadata
    {
        $parsed = $this->parseSource($source);

        if ($parsed === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $this->assertConfigured();

        $project = $this->fetchProject($parsed['projectId']);

        $this->assertModpackProject($project);

        $file = $this->resolveFile($parsed['projectId'], $parsed['fileId']);

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

        $logo = is_array($project['logo'] ?? null)
            ? $project['logo']
            : [];

        $iconUrl = $this->nullableString($logo['thumbnailUrl'] ?? null)
            ?? $this->nullableString($logo['url'] ?? null)
            ?? $this->nullableString($project['links']['iconUrl'] ?? null);

        return new ModpackMetadata(
            id: (string) ($project['id'] ?? $parsed['projectId']),
            name: (string) ($project['name'] ?? ''),
            version: (string) $version,
            minecraftVersion: $minecraftVersion,
            loader: $loader,
            description: $this->nullableString($project['summary'] ?? null),
            iconUrl: $this->validImageUrl($iconUrl),
            source: $this->canonicalSource($parsed['projectId'], $parsed['fileId']),
        );
    }

    public function getPackage(string $source): ModpackPackage
    {
        $parsed = $this->parseSource($source);

        if ($parsed === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $this->assertConfigured();

        $projectId = $parsed['projectId'];

        $project = $this->fetchProject($projectId);

        $this->assertModpackProject($project);

        $file = $this->resolveFile($projectId, $parsed['fileId']);

        if ($file === null) {
            throw new InvalidArgumentException(
                'The CurseForge project has no files.',
            );
        }

        $file = $this->resolvePackageFile($file, $projectId);

        if (!$this->isPubliclyDownloadable($file)) {
            throw new InvalidArgumentException(
                'The CurseForge modpack does not provide a public download URL.',
            );
        }

        $downloadUrl = (string) ($file['downloadUrl'] ?? '');

        $archivePath = $this->downloader->download($downloadUrl);

        $this->temporaryPackages[$archivePath] = true;

        try {
            $this->assertServerPack($archivePath);

            return new ModpackPackage(
                archivePath: $archivePath,
                source: $this->canonicalSource($projectId, $parsed['fileId']),
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

    /**
     * Resolves a source string to a CurseForge project id and an optional
     * exact-file pin (curseforge://projectId@fileId). Returns null when the
     * syntax is unsupported.
     *
     * @return array{projectId: string, fileId: string|null}|null
     */
    private function parseSource(string $source): ?array
    {
        $source = trim($source);

        if (!str_starts_with($source, 'curseforge://')) {
            return null;
        }

        $rest = substr($source, strlen('curseforge://'));

        $pieces = explode('@', $rest, 2);

        $projectId = (string) ($pieces[0] ?? '');

        if (preg_match('/^\d+$/', $projectId) !== 1) {
            return null;
        }

        $fileId = null;

        if (isset($pieces[1])) {
            $fileId = trim((string) $pieces[1]);

            if ($fileId === '' || preg_match('/^\d+$/', $fileId) !== 1) {
                return null;
            }
        }

        return [
            'projectId' => $projectId,
            'fileId' => $fileId,
        ];
    }

    private function canonicalSource(string $projectId, ?string $fileId): string
    {
        $source = 'curseforge://' . $projectId;

        if ($fileId !== null) {
            return $source . '@' . $fileId;
        }

        return $source;
    }

    /**
     * Selects a file for the project: the exact pinned file when a pin is
     * present, otherwise the newest Release file (falling back to the newest
     * file).
     *
     * @return array<string, mixed>
     */
    private function resolveFile(string $projectId, ?string $fileId): array
    {
        if ($fileId !== null) {
            $file = $this->fetchFileById($projectId, $fileId);

            $this->assertFileBelongsToProject($file, $projectId);

            return $file;
        }

        $files = $this->fetchFiles($projectId);

        $file = $this->selectFile($files);

        if ($file === null) {
            throw new InvalidArgumentException(
                'The CurseForge project has no files.',
            );
        }

        return $file;
    }

    /**
     * Prefers a dedicated server pack when the selected file references one,
     * falling back to the selected file when the server pack cannot be
     * resolved to a public download.
     *
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private function resolvePackageFile(array $file, string $projectId): array
    {
        $serverPackFileId = $this->intOrNull(
            $file['serverPackFileId'] ?? null,
        );

        if ($serverPackFileId === null || $serverPackFileId <= 0) {
            return $file;
        }

        $serverPack = $this->fetchServerPackFile(
            $projectId,
            (string) $serverPackFileId,
        );

        if ($serverPack === null) {
            return $file;
        }

        return $serverPack;
    }

    /**
     * Whether a file is installable: public status (or unspecified), marked
     * available (or unspecified), with a public download URL.
     *
     * @param array<string, mixed> $file
     */
    private function isPubliclyDownloadable(array $file): bool
    {
        $status = $this->intOrNull($file['fileStatus'] ?? null);

        if ($status !== null && !in_array($status, [4, 10], true)) {
            return false;
        }

        if (($file['isAvailable'] ?? true) === false) {
            return false;
        }

        return $this->nullableString($file['downloadUrl'] ?? null) !== null;
    }

    /**
     * Fetches the dedicated server pack file referenced by a file. Returns
     * null when it no longer exists (404) or has no public download URL so the
     * caller can fall back to the selected file.
     *
     * @return array<string, mixed>|null
     */
    private function fetchServerPackFile(
        string $projectId,
        string $fileId,
    ): ?array {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $projectId . '/files/' . $fileId,
                headers: $this->headers(),
            );

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'The CurseForge file was invalid.',
                );
            }

            $file = $response->body['data'];
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                return null;
            }

            throw $this->requestFailure($exception);
        }

        $this->assertFileBelongsToProject($file, $projectId);

        if (!$this->isPubliclyDownloadable($file)) {
            return null;
        }

        return $file;
    }

    /**
     * Prevents a client-supplied file id from referencing a file that belongs
     * to a different project. A file payload without a mod id is tolerated so
     * providers without the field keep working, but any id that is present
     * must match the requested project.
     *
     * @param array<string, mixed> $file
     */
    private function assertFileBelongsToProject(
        array $file,
        string $projectId,
    ): void {
        $modId = $this->intOrNull($file['modId'] ?? null);

        if ($modId === null) {
            return;
        }

        if ((string) $modId !== $projectId) {
            throw new InvalidArgumentException(
                'The requested file does not belong to the selected project.',
            );
        }
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
     * CurseForge does not mark modpacks with a project type; the Modpacks
     * class (classId 4471) is the equivalent. A mod without a class id is
     * tolerated, but any class id that is present must be the modpacks class.
     *
     * @param array<string, mixed> $project
     */
    private function assertModpackProject(array $project): void
    {
        $classId = $this->intOrNull($project['classId'] ?? null);

        if ($classId === null) {
            return;
        }

        if ($classId !== self::MODPACK_CLASS_ID) {
            throw new InvalidArgumentException(
                'The CurseForge project is not a modpack.',
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

            if (!is_array($response->body) || !is_array($response->body['data'] ?? null)) {
                throw new InvalidArgumentException(
                    'The CurseForge project response was invalid.',
                );
            }

            return $response->body['data'];
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
                    'pageSize' => 50,
                    'index' => 0,
                ],
                headers: $this->headers(),
            );

            if (!is_array($response->body) || !is_array($response->body['data'] ?? null)) {
                throw new InvalidArgumentException(
                    'The CurseForge file list was invalid.',
                );
            }

            $files = $response->body['data'];

            foreach ($files as $file) {
                if (!is_array($file)) {
                    throw new InvalidArgumentException(
                        'The CurseForge file list was invalid.',
                    );
                }
            }

            return $files;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFileById(string $projectId, string $fileId): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/mods/' . $projectId . '/files/' . $fileId,
                headers: $this->headers(),
            );

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'The CurseForge file was invalid.',
                );
            }

            return $response->body['data'];
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                throw new InvalidArgumentException(
                    'The requested CurseForge file was not found.',
                );
            }

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
                && preg_match('/^\d+\.\d+(\.\d+)*$/', $entry) === 1
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

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
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