<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\ConcurrentDownloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\StageReporter;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CurseForgeProvider implements ModpackProvider, ManualDownloadProvider, PartialPackageProvider
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const CDN_BASE = 'https://edge.forgecdn.net/files';

    // The host the edge CDN 302-redirects file downloads onto.
    private const MEDIA_MIRROR_BASE = 'https://mediafilez.forgecdn.net/files';

    private const API_KEY_HEADER = 'X-Api-Key';

    private const MODPACK_CLASS_ID = 4471;

    private const MANIFEST_FILE = 'manifest.json';

    // Winning source for a given relative server path: overrides win over manifest mod files.
    private const ENTRY_PRIORITIES = [
        'mods' => 0,
        'overrides' => 1,
    ];

    // @var array<string, true> Paths of temporary archives awaiting cleanup.
    private array $temporaryPackages = [];

    private readonly string $temporaryRoot;

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly Downloader $downloader,
        private readonly ?string $apiKey,
        string $temporaryRoot = '',
    ) {
        $this->temporaryRoot = $temporaryRoot !== ''
            ? $temporaryRoot
            : sys_get_temp_dir();
    }

    public function supports(string $source): bool
    {
        return $this->parseSource($source) !== null;
    }

    // Names the install step now in progress, when the downloader can report stages.
    private function announceStage(
        string $key,
        ?int $current = null,
        ?int $total = null,
    ): void {
        if ($this->downloader instanceof StageReporter) {
            $this->downloader->stage($key, $current, $total);
        }
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

        $this->announceStage('archive');

        $archivePath = $this->downloader->download($downloadUrl);

        $this->temporaryPackages[$archivePath] = true;

        $resolvedFileId = $this->intOrNull($file['id'] ?? null);

        $resolvedFileId = $resolvedFileId === null
            ? $parsed['fileId']
            : (string) $resolvedFileId;

        try {
            $normalized = $this->manifestResolvedArchive($archivePath);

            if ($normalized !== null) {
                $this->removeTracked($archivePath);

                $this->temporaryPackages[$normalized] = true;

                $archivePath = $normalized;
            }

            return new ModpackPackage(
                archivePath: $archivePath,
                source: $this->canonicalSource($projectId, $resolvedFileId),
            );
        } catch (Throwable $exception) {
            $this->removeTracked($archivePath);

            throw $exception;
        }
    }

    // Returns normalized manual-download info when a CurseForge file cannot be downloaded automatically.
    public function manualDownloadInfo(array $file, array $project): array
    {
        $logo = is_array($project['logo'] ?? null)
            ? $project['logo']
            : [];

        $icon = $this->nullableString($logo['thumbnailUrl'] ?? null)
            ?? $this->nullableString($logo['url'] ?? null);

        $projectUrl = $this->nullableString($project['links']['websiteUrl'] ?? null);

        $downloadUrl = $this->nullableString($file['downloadUrl'] ?? null);

        $version = $this->nullableString($file['displayName'] ?? null)
            ?? $this->nullableString($file['fileName'] ?? null);

        $reason = '';

        if ($downloadUrl === null) {
            $reason = 'This CurseForge modpack does not provide a public download URL. '
                . 'Please download the file manually from '
                . ($projectUrl ? $projectUrl : 'CurseForge.');
        } elseif (($file['isAvailable'] ?? true) === false) {
            $reason = 'This file is not available for download at this time.';
        } elseif (
            $this->intOrNull($file['fileStatus'] ?? null) !== null
            && !in_array($this->intOrNull($file['fileStatus'] ?? null), [4, 10], true)
        ) {
            $reason = 'This file is not publicly available for download.';
        } else {
            $reason = 'Unable to download this file automatically. '
                . 'Please download manually from CurseForge.';
        }

        return [
            'provider' => 'curseforge',
            'project_name' => $this->nullableString($project['name'] ?? null) ?? '',
            'project_url' => $projectUrl,
            'file_name' => $this->nullableString($file['displayName'] ?? null)
                ?? $this->nullableString($file['fileName'] ?? null),
            'version' => $version,
            'download_url' => $downloadUrl,
            'reason' => $reason,
        ];
    }

    public function cleanup(ModpackPackage $package): void
    {
        $this->removeTracked($package->archivePath);
    }

    // Builds a package holding only the requested server-relative paths.
    public function getPackageForPaths(
        string $source,
        array $paths,
    ): ModpackPackage {
        $parsed = $this->parseSource($source);

        if ($parsed === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $this->assertConfigured();

        $wanted = [];

        foreach ($paths as $path) {
            $normalized = str_replace('\\', '/', $path);
            $normalized = ltrim($normalized, '/');

            if (
                $normalized === ''
                || !$this->isSafeRelativePath($normalized)
            ) {
                throw new InvalidArgumentException(
                    'The modpack restore contains an invalid path.',
                );
            }

            $wanted[$normalized] = true;
        }

        if ($wanted === []) {
            throw new InvalidArgumentException(
                'A modpack restore requires at least one path.',
            );
        }

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

        $this->announceStage('archive');

        $archivePath = $this->downloader->download($downloadUrl);

        $this->temporaryPackages[$archivePath] = true;

        $resolvedFileId = $this->intOrNull($file['id'] ?? null);

        $resolvedFileId = $resolvedFileId === null
            ? $parsed['fileId']
            : (string) $resolvedFileId;

        try {
            $normalized = $this->manifestResolvedArchive(
                $archivePath,
                array_keys($wanted),
            );

            if ($normalized !== null) {
                $this->removeTracked($archivePath);

                $this->temporaryPackages[$normalized] = true;

                $archivePath = $normalized;
            }

            return new ModpackPackage(
                archivePath: $archivePath,
                source: $this->canonicalSource($projectId, $resolvedFileId),
            );
        } catch (Throwable $exception) {
            $this->removeTracked($archivePath);

            throw $exception;
        }
    }

    // Resolves the file a source would install and, when that file cannot be downloaded automatically, returns normalized...
    public function manualDownloadInfoFor(string $source): ?array
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

        if ($file === null) {
            throw new InvalidArgumentException(
                'The CurseForge project has no files.',
            );
        }

        $file = $this->resolvePackageFile($file, $parsed['projectId']);

        if ($this->isPubliclyDownloadable($file)) {
            return null;
        }

        return $this->manualDownloadInfo($file, $project);
    }

    private function removeTracked(string $path): void
    {
        if (!isset($this->temporaryPackages[$path])) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            $this->deleteDirectoryTree($path);
        }

        unset($this->temporaryPackages[$path]);
    }

    // Resolves a source string to a CurseForge project id and an optional exact-file pin (curseforge://projectId@fileId).
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

    // Selects a file for the project: the exact pinned file when a pin is present, otherwise the newest Release file (falling...
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

    // Prefers a dedicated server pack when the selected file references one, falling back to the selected file when the...
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

        return $this->inheritMissingGameVersions($serverPack, $file);
    }

    // CurseForge server-pack files frequently ship with an empty gameVersions array even though the client pack that...
    private function inheritMissingGameVersions(
        array $serverPack,
        array $referencingFile,
    ): array {
        $serverVersions = $serverPack['gameVersions'] ?? null;

        if (is_array($serverVersions) && $serverVersions !== []) {
            return $serverPack;
        }

        $referenceVersions = $referencingFile['gameVersions'] ?? null;

        if (!is_array($referenceVersions) || $referenceVersions === []) {
            return $serverPack;
        }

        $serverPack['gameVersions'] = $referenceVersions;

        return $serverPack;
    }

    // Whether a file is installable: public status (or unspecified), marked available (or unspecified), with a public...
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

    // Extracts the SHA-1 (algorithm 1) digest CurseForge reports for a file, which the pack references download verification...
    private function sha1FromHashes(array $hashes): string
    {
        foreach ($hashes as $hash) {
            if (($hash['algo'] ?? null) === 1) {
                $value = (string) ($hash['value'] ?? '');

                if ($value !== '') {
                    return strtolower($value);
                }
            }
        }

        return '';
    }

    // Whether a downloaded file's SHA-1 digest matches the expected digest reported by the provider metadata.
    private function matchesSha1(string $path, string $expectedSha1): bool
    {
        if ($expectedSha1 === '') {
            return true;
        }

        $actual = @sha1_file($path);

        if ($actual === false || $actual === '') {
            return false;
        }

        return hash_equals(strtolower($expectedSha1), strtolower($actual));
    }

    // Fetches the dedicated server pack file referenced by a file.
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

    // Prevents a client-supplied file id from referencing a file that belongs to a different project.
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

    // CurseForge does not mark modpacks with a project type; the Modpacks class (classId 4471) is the equivalent.
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

    // @return array<string, mixed>
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

    // @return array<int, array<string, mixed>>
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

    // @return array<string, mixed>
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

    // @return array<string>
    private function headers(): array
    {
        return [
            self::API_KEY_HEADER . ': ' . $this->apiKey,
        ];
    }

    // @param array<int, array<string, mixed>> $files @return array<string, mixed>|null
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

    // @param array<mixed> $gameVersions @return array{0: string|null, 1: string|null} [minecraft, loader]
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

    // Returns a normalized server archive for the downloaded CurseForge file.
    private function manifestResolvedArchive(
        string $archivePath,
        ?array $wantedPaths = null,
    ): ?string
    {
        $source = new ZipArchive();

        if ($source->open($archivePath) !== true) {
            throw new InvalidArgumentException(
                'The downloaded CurseForge file is not a valid archive.',
            );
        }

        try {
            $prefix = '';

            if ($source->statName(self::MANIFEST_FILE) === false) {
                // The manifest may sit inside a single wrapper folder (some packs zip their whole tree one level deep).
                $wrapper = $this->singleRootPrefix($source);

                if (
                    $wrapper !== ''
                    && $source->statName($wrapper . self::MANIFEST_FILE) !== false
                ) {
                    $prefix = $wrapper;
                } elseif ($wrapper !== '') {
                    // Dedicated server pack wrapped in one folder: stream it out with the wrapper stripped so its content deploys a...
                    return $this->materializeStrippedArchive(
                        $source,
                        $wrapper,
                        $wantedPaths,
                    );
                } else {
                    return null;
                }
            }

            $this->announceStage('manifest');

            $rawManifest = $source->getFromName($prefix . self::MANIFEST_FILE);

            if ($rawManifest === false) {
                throw new UnsupportedModpackPackageException(
                    'The CurseForge modpack manifest could not be read.',
                );
            }

            $manifest = json_decode($rawManifest, true);

            if (!is_array($manifest)) {
                throw new UnsupportedModpackPackageException(
                    'The CurseForge modpack contains a malformed manifest.json client-pack manifest.',
                );
            }

            return $this->buildServerArchive(
                $source,
                $archivePath,
                $manifest,
                $wantedPaths,
                $prefix,
            );
        } finally {
            $source->close();
        }
    }

    // Detects whether every entry in the archive lives inside one shared top-level folder.
    private function singleRootPrefix(ZipArchive $source): string
    {
        $root = null;

        for ($index = 0; $index < $source->numFiles; $index++) {
            $entry = $source->statIndex($index);

            if ($entry === false) {
                return '';
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $slash = strpos($name, '/');

            // A file at the archive root means no wrapper folder at all.
            if ($slash === false) {
                return '';
            }

            $top = substr($name, 0, $slash + 1);

            if ($root === null) {
                $root = $top;
            } elseif ($root !== $top) {
                return '';
            }
        }

        if ($root === null || !$this->isSafeRelativePath(rtrim($root, '/'))) {
            return '';
        }

        return $root;
    }

    // Materializes a dedicated server pack into a package directory, stripping the given wrapper prefix from every entry so...
    private function materializeStrippedArchive(
        ZipArchive $source,
        string $prefix,
        ?array $wantedPaths = null,
    ): string {
        $wanted = $wantedPaths === null
            ? null
            : array_fill_keys($wantedPaths, true);

        $totalBytes = 0;

        $entries = [];

        for ($index = 0; $index < $source->numFiles; $index++) {
            $entry = $source->statIndex($index);

            if ($entry === false) {
                throw new UnsupportedModpackPackageException(
                    'The modpack archive contains an unreadable entry.',
                );
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $relative = substr($name, strlen($prefix));

            if ($relative === '' || !$this->isSafeRelativePath($relative)) {
                throw new InvalidArgumentException(
                    'The modpack archive contains an invalid entry path.',
                );
            }

            if ($wanted !== null && !isset($wanted[$relative])) {
                continue;
            }

            $entries[$relative] = $name;

            $totalBytes += max(0, (int) ($entry['size'] ?? 0));
        }

        if ($wanted !== null) {
            $missing = [];

            foreach ($wantedPaths ?? [] as $wantedPath) {
                if (!isset($entries[$wantedPath])) {
                    $missing[] = $wantedPath;
                }
            }

            if ($missing !== []) {
                throw new UnsupportedModpackPackageException(
                    'The modpack restore could not source these files from the pack: '
                        . implode(', ', $missing),
                );
            }
        }

        if ($entries === []) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge server pack contains no files to install.',
            );
        }

        // Dedicated server pack: this archive IS the payload, so the extract step streams it straight out with no manif...
        $this->announceStage('extract');

        $this->downloader->setProgressOffset(0, max(1, $totalBytes));

        $outputPath = $this->createPackageDirectory();

        $written = 0;

        try {
            foreach ($entries as $relative => $name) {
                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $written += $this->streamZipEntry(
                    $source,
                    $name,
                    $this->packageOutputPath($outputPath, $relative),
                );

                $this->downloader->reportProgress($written, max(1, $totalBytes));
            }
        } catch (\Throwable $exception) {
            $this->deleteDirectoryTree($outputPath);

            throw $exception;
        }

        $this->downloader->reportProgress(max(1, $totalBytes), max(1, $totalBytes));

        return $outputPath;
    }

    // Streams one zip entry to an absolute destination path. Returns the number of bytes written.
    private function streamZipEntry(
        ZipArchive $source,
        string $entry,
        string $destination,
    ): int {
        $stream = $source->getStream($entry);

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to read an entry from the modpack archive.',
            );
        }

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            fclose($stream);

            throw new RuntimeException(
                'Unable to write a modpack file into the package directory.',
            );
        }

        $written = 0;

        try {
            while (!feof($stream)) {
                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $chunk = fread($stream, 262144);

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Unable to read an entry from the modpack archive.',
                    );
                }

                if ($chunk === '') {
                    break;
                }

                $bytes = fwrite($handle, $chunk);

                if ($bytes === false || $bytes !== strlen($chunk)) {
                    throw new RuntimeException(
                        'Unable to write a modpack file into the package directory.',
                    );
                }

                $written += $bytes;
            }
        } finally {
            fclose($handle);
            fclose($stream);
        }

        return $written;
    }

    // Rebuilds a CurseForge client pack into a normalized server package.
    private function buildServerArchive(
        ZipArchive $source,
        string $archivePath,
        array $manifest,
        ?array $wantedPaths = null,
        string $prefix = '',
    ): string {
        $wanted = $wantedPaths === null
            ? null
            : array_fill_keys($wantedPaths, true);

        $content = $this->collectArchiveContent(
            $source,
            $prefix . $this->manifestOverridesPath($manifest),
            $wanted,
            $prefix,
        );

        [$installedMods, $wantedMods] = $this->mergeManifestFiles(
            $manifest,
            $content,
            $wanted,
        );

        if ($content === []) {
            // Restore flow: an all-mods wanted set legitimately produces no overrides content, so only a full build must fa...
            if ($wantedPaths === null) {
                throw new UnsupportedModpackPackageException(
                    'The CurseForge modpack contains no server content to install (no mods or overrides).',
                );
            }
        } elseif ($wantedMods > 0 && $installedMods === 0) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge modpack references mod files with no usable download source, so no server mods could be installed.',
            );
        }

        // Every step of this install owns its own progress window.
        $overridesBytes = 0;
        $modsBytes = 0;

        foreach ($content as $payload) {
            $bytes = max(0, (int) $payload['bytes']);

            if ($payload['archiveEntry'] === null) {
                $modsBytes += $bytes;

                continue;
            }

            $overridesBytes += $bytes;
        }

        // Extract step: the overrides (and any mods shipped inside the pack) are streamed out of the archive onto disk.
        $this->announceStage('extract');

        $this->downloader->setProgressOffset(0, max(1, $overridesBytes));

        $outputPath = $this->createPackageDirectory();

        // Materialize every overrides entry directly into the package directory, keeping the progress bar alive with th...
        $overridesWritten = 0;

        try {
            foreach ($content as $relative => $payload) {
                if ($payload['archiveEntry'] === null) {
                    continue;
                }

                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $this->writePackageEntry(
                    $source,
                    $payload['archiveEntry'],
                    $relative,
                    $outputPath,
                    0,
                    max(1, $overridesBytes),
                    $overridesWritten,
                );
            }

            $failedMods = [];
            $succeededMods = [];

            $downloadedMods = $this->fetchPackageMods(
                $content,
                $outputPath,
                $modsBytes,
                $failedMods,
                $succeededMods,
            );

            if ($wantedMods > 0 && $downloadedMods === 0) {
                throw new UnsupportedModpackPackageException(
                    'None of the CurseForge mod files referenced by the modpack could be downloaded, so no server mods could be installed.',
                );
            }

            if ($wantedMods > 0 && $downloadedMods < $wantedMods) {
                $failedCount = count($failedMods);

                // Restore flow: every requested path must come back.
                if ($wantedPaths !== null) {
                    throw new UnsupportedModpackPackageException(
                        'The modpack restore could not fetch: '
                            . implode(', ', $failedMods),
                    );
                }

                if ($failedCount > $this->toleratedModFailures($wantedMods)) {
                    throw new UnsupportedModpackPackageException(
                        'Only '
                        . $downloadedMods
                        . ' of the '
                        . $wantedMods
                        . ' required CurseForge mod files could be downloaded. Installing the pack anyway would leave the server broken, so the installation was stopped. This usually means the CurseForge API key lacks the download entitlement, some mods were removed from CurseForge, or the API is rate-limiting requests.',
                    );
                }

                @error_log(
                    'addonmanager manifest download failed for '
                    . $failedCount
                    . ' of '
                    . $wantedMods
                    . ' mod files; continuing with the rest. Failed: '
                    . implode(', ', $failedMods)
                );
            }
        } catch (\Throwable $exception) {
            $this->deleteDirectoryTree($outputPath);

            throw $exception;
        }

        // Restore flow: any wanted path that neither matched an overrides entry nor a manifest mod (renamed upstream, a...
        if ($wantedPaths !== null) {
            $provided = [];

            foreach ($content as $relative => $payload) {
                if ($payload['archiveEntry'] !== null) {
                    $provided[$relative] = true;
                }
            }

            foreach ($succeededMods as $id) {
                $provided[$id] = true;
            }

            $unfulfilled = [];

            foreach ($wantedPaths as $wantedPath) {
                if (!isset($provided[$wantedPath])) {
                    $unfulfilled[] = $wantedPath;
                }
            }

            if ($unfulfilled !== []) {
                $this->deleteDirectoryTree($outputPath);

                throw new UnsupportedModpackPackageException(
                    'The modpack restore could not source these files from the pack: '
                        . implode(', ', $unfulfilled),
                );
            }
        }

        return $outputPath;
    }

    // Downloads every manifest mod file into the package's mods/ directory, either through the parallel batch engine when the...
    private function fetchPackageMods(
        array $content,
        string $outputPath,
        int $modsBytes,
        array &$failedMods = [],
        array &$succeededMods = [],
    ): int {
        $modTasks = [];

        foreach ($content as $relative => $payload) {
            if ($payload['archiveEntry'] !== null) {
                continue;
            }

            $modTasks[] = [
                'id' => $relative,
                'sha1' => (string) ($payload['sha1'] ?? ''),
                'urls' => $payload['downloadUrls'],
                'bytes' => max(0, (int) $payload['bytes']),
                'destination' => $this->packageOutputPath($outputPath, $relative),
            ];
        }

        if ($modTasks === []) {
            return 0;
        }

        $sha1ById = [];

        foreach ($modTasks as $task) {
            $sha1ById[$task['id']] = $task['sha1'];
        }

        $totalMods = count($modTasks);

        // Mods step: the manifest's own count drives the label ("12 / 34"), and its own byte footprint drives the bar.
        $this->announceStage('mods', 0, $totalMods);

        $bytesDone = 0;

        if ($this->downloader instanceof ConcurrentDownloader) {
            $this->downloader->setProgressOffset(0, max(1, $modsBytes));

            if (method_exists($this->downloader, 'setBatchProgressCallback')) {
                $this->downloader->setBatchProgressCallback(
                    function (array $files) use ($totalMods): void {
                        $resolved = 0;

                        foreach ($files as $state) {
                            if (
                                ($state['done'] ?? false) === true
                                || ($state['failed'] ?? false) === true
                            ) {
                                $resolved++;
                            }
                        }

                        $this->announceStage('mods', $resolved, $totalMods);
                    },
                );
            }

            $results = $this->downloader->downloadBatch($modTasks);

            $downloadedMods = 0;

            foreach ($results as $id => $destination) {
                if ($destination === null) {
                    continue;
                }

                // The parallel engine cannot hash-verify mid-flight, so every successful transfer is checked here.
                $expectedSha1 = $sha1ById[$id] ?? '';

                if ($expectedSha1 !== '' && !$this->matchesSha1($destination, $expectedSha1)) {
                    @unlink($destination);

                    $failedMods[] = (string) $id;

                    continue;
                }

                $downloadedMods++;
                $succeededMods[] = (string) $id;
            }

            // Closing count for the step: the batch's last progress tick can land before the final transfers settle, so the...
            $this->announceStage('mods', $downloadedMods, $totalMods);

            return $downloadedMods;
        }

        $downloadedMods = 0;
        $downloadedTemps = [];

        // A dead mod's declared bytes must be deflated out of the window's total as well as skipped in the running coun...
        $failedBytes = 0;

        try {
            foreach ($modTasks as $task) {
                $downloadPath = $this->downloadBestUrl(
                    $task['urls'],
                    $bytesDone,
                    max(1, $modsBytes),
                    $task['sha1'],
                );

                if ($downloadPath === null) {
                    $failedBytes += $task['bytes'];
                    $failedMods[] = $task['id'];

                    // A dead mod must never freeze the progress store: keep pinging so the card stays alive through long stretches ...
                    $this->downloader->reportProgress(
                        $bytesDone,
                        max(1, $modsBytes - $failedBytes),
                    );

                    continue;
                }

                // The source is a shared or disposable temp file; copy it into the package and let the shared cleanup unlink th...
                if (!@copy($downloadPath, $task['destination'])) {
                    throw new RuntimeException(
                        'Unable to place a downloaded mod file.',
                    );
                }

                $downloadedMods++;
                $succeededMods[] = $task['id'];
                $downloadedTemps[] = $downloadPath;
                $bytesDone += $task['bytes'];
            }

            // Closing report: land the window on exactly 100% (unthrottled) so the frontend flips to the deploy phase inste...
            $this->downloader->reportProgress(
                max(1, $modsBytes - $failedBytes),
                max(1, $modsBytes - $failedBytes),
            );

            return $downloadedMods;
        } finally {
            foreach ($downloadedTemps as $downloadPath) {
                @unlink($downloadPath);
            }
        }
    }

    // Streams a single client-pack entry into the package directory, reporting cumulative progress and honouring cancellation...
    private function writePackageEntry(
        ZipArchive $source,
        string $entry,
        string $relative,
        string $outputPath,
        int $baseDone,
        int $totalBytes,
        int &$overridesWritten,
    ): void {
        $stream = $source->getStream($entry);

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to read an entry from the modpack archive.',
            );
        }

        $destination = $this->packageOutputPath($outputPath, $relative);

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            fclose($stream);

            throw new RuntimeException(
                'Unable to write a modpack file into the package directory.',
            );
        }

        try {
            while (!feof($stream)) {
                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $chunk = fread($stream, 262144);

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Unable to read an entry from the modpack archive.',
                    );
                }

                if ($chunk === '') {
                    break;
                }

                $written = fwrite($handle, $chunk);

                if ($written === false || $written !== strlen($chunk)) {
                    throw new RuntimeException(
                        'Unable to write a modpack file into the package directory.',
                    );
                }

                $overridesWritten += strlen($chunk);

                $this->downloader->reportProgress(
                    $baseDone + $overridesWritten,
                    $totalBytes,
                );
            }
        } finally {
            fclose($handle);
            fclose($stream);
        }
    }

    // Resolves a safe relative path inside the package directory, creating any leading directories as needed.
    private function packageOutputPath(string $outputPath, string $relative): string
    {
        $path = $outputPath . '/' . $relative;

        $directory = dirname($path);

        if (
            !is_dir($directory)
            && !@mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create a package directory.',
            );
        }

        return $path;
    }

    private function createPackageDirectory(): string
    {
        $root = rtrim($this->temporaryRoot, DIRECTORY_SEPARATOR);

        if (
            !is_dir($root)
            && !mkdir($root, 0750, true)
            && !is_dir($root)
        ) {
            throw new RuntimeException(
                'Unable to create the temporary package directory.',
            );
        }

        $outputPath = $root . '/' . bin2hex(random_bytes(16)) . '.dir';

        if (!mkdir($outputPath, 0750, true) && !is_dir($outputPath)) {
            throw new RuntimeException(
                'Unable to create the normalized package directory.',
            );
        }

        return $outputPath;
    }

    private function deleteDirectoryTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($directory);
        } catch (\Throwable) {
            // Cleanup is best-effort and must never mask the outcome.
        }
    }

    // Collects the overrides payload embedded in the client-pack archive itself, dropping the configured overrides directory...
    private function collectArchiveContent(
        ZipArchive $source,
        string $overridesPath,
        ?array $wantedPaths = null,
        string $prefix = '',
    ): array {
        $content = [];
        $prefix = $prefix . $overridesPath . '/';

        for ($index = 0; $index < $source->numFiles; $index++) {
            if ($this->downloader->isCancelled()) {
                throw new InstallationCancelledException(
                    'Installation cancelled.'
                );
            }

            $entry = $source->statIndex($index);

            if ($entry === false) {
                throw new UnsupportedModpackPackageException(
                    'The modpack archive contains an unreadable entry.',
                );
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $relative = substr($name, strlen($prefix));

            if ($relative === '' || !$this->isSafeRelativePath($relative)) {
                throw new InvalidArgumentException(
                    'The modpack archive contains an invalid entry path.',
                );
            }

            if ($wantedPaths !== null && !isset($wantedPaths[$relative])) {
                continue;
            }

            $content[$relative] = [
                'archiveEntry' => $name,
                'downloadUrls' => [],
                'priority' => 'overrides',
                'bytes' => max(0, (int) ($entry['size'] ?? 0)),
            ];
        }

        return $content;
    }

    // Resolves manifest mod files through the CurseForge API and merges the resolvable ones into the normalized archive under...
    private function mergeManifestFiles(
        array $manifest,
        array &$content,
        ?array $wantedPaths = null,
    ): array
    {
        $entries = $manifest['files'] ?? [];

        if (!is_array($entries)) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge modpack contains an invalid manifest.json files list.',
            );
        }

        $wanted = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new UnsupportedModpackPackageException(
                    'The CurseForge modpack contains an invalid manifest.json file entry.',
                );
            }

            if (($entry['required'] ?? true) === false) {
                continue;
            }

            $env = is_array($entry['env'] ?? null) ? $entry['env'] : [];

            if (($env['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $projectId = $this->intOrNull($entry['projectID'] ?? null);
            $fileId = $this->intOrNull($entry['fileID'] ?? null);

            if ($projectId === null || $fileId === null) {
                throw new UnsupportedModpackPackageException(
                    'The CurseForge modpack manifest references a file without a project and file id.',
                );
            }

            $wanted[$fileId] = [
                'projectId' => $projectId,
            ];
        }

        $resolved = $this->fetchModFiles(array_keys($wanted));

        $installed = 0;
        $skipped = 0;

        foreach ($wanted as $fileId => $meta) {
            if ($this->downloader->isCancelled()) {
                throw new InstallationCancelledException(
                    'Installation cancelled.'
                );
            }

            $file = $resolved[$fileId] ?? null;
            $fileName = $this->manifestFileName($file, (int) $fileId);

            $candidates = $this->candidateDownloadUrls(
                (string) $meta['projectId'],
                (int) $fileId,
                $file,
                $fileName,
            );

            if ($candidates === []) {
                $skipped++;
                $this->recordManifestSkip((int) $fileId);

                continue;
            }

            $relative = 'mods/' . $fileName;

            // Restore flow: skip every manifest mod outside the wanted set before resolution work (candidate building) begi...
            if ($wantedPaths !== null && !isset($wantedPaths[$relative])) {
                continue;
            }

            if ($this->shouldReplace($content[$relative] ?? null, 'mods')) {
                $content[$relative] = [
                    'archiveEntry' => null,
                    'downloadUrls' => $candidates,
                    'priority' => 'mods',
                    'bytes' => max(0, (int) ($file['fileLength'] ?? 0)),
                    'sha1' => $this->sha1FromHashes((array) ($file['hashes'] ?? [])),
                ];
            }

            $installed++;
        }

        if ($skipped > 0) {
            @error_log(
                'addonmanager manifest resolution skipped '
                . $skipped
                . ' of '
                . count($wanted)
                . ' mod files without any usable download source.'
            );
        }

        // Restore flow reports its own scope: the wanted count is the number of manifest mods actually in the wanted pa...
        if ($wantedPaths !== null) {
            $wantedInScope = 0;

            foreach ($wanted as $fileId => $meta) {
                $fileName = $this->manifestFileName(
                    $resolved[$fileId] ?? null,
                    (int) $fileId,
                );

                if (isset($wantedPaths['mods/' . $fileName])) {
                    $wantedInScope++;
                }
            }

            return [$installed, $wantedInScope];
        }

        return [$installed, count($wanted)];
    }

    private function recordManifestSkip(int $fileId): void
    {
        @error_log(
            'addonmanager manifest resolution skipped mod file '
            . $fileId
            . ' (no usable download source on CurseForge).'
        );
    }

    // Resolves a list of CurseForge file ids through the bulk files endpoint (chunked to the API's 50-per-request cap).
    private function fetchModFiles(array $fileIds): array
    {
        $resolved = [];

        foreach (array_chunk(array_values(array_unique($fileIds)), 50) as $chunk) {
            if ($this->downloader->isCancelled()) {
                throw new InstallationCancelledException(
                    'Installation cancelled.'
                );
            }

            try {
                $response = $this->http->post(
                    self::API_BASE . '/mods/files',
                    body: ['fileIds' => $chunk],
                    headers: $this->headers(),
                );
            } catch (ProviderHttpException $exception) {
                throw $this->requestFailure($exception);
            }

            if (
                !is_array($response->body)
                || !is_array($response->body['data'] ?? null)
            ) {
                throw new RuntimeException(
                    'The CurseForge mod file resolution response was invalid.',
                );
            }

            foreach ($response->body['data'] as $file) {
                if (!is_array($file)) {
                    throw new RuntimeException(
                        'The CurseForge mod file resolution response was invalid.',
                    );
                }

                $id = $this->intOrNull($file['id'] ?? null);

                if ($id === null) {
                    continue;
                }

                $resolved[$id] = $file;
            }
        }

        return $resolved;
    }

    // Derives the mods/ file name for a manifest file, falling back to the file id when the API reports no usable file name.
    private function manifestFileName(?array $file, int $fileId): string
    {
        $name = str_replace('\\', '/', (string) ($file['fileName'] ?? ''));

        $name = basename($name);

        if ($name === '' || !$this->isSafeRelativePath($name)) {
            $name = (string) $fileId;
        }

        return $name;
    }

    // Builds an ordered list of candidate download URLs for a manifest mod file.
    private function candidateDownloadUrls(
        string $projectId,
        int $fileId,
        ?array $file,
        string $fileName,
    ): array {
        $candidates = [];

        $downloadUrl = $this->nullableString($file['downloadUrl'] ?? null);

        if ($downloadUrl !== null) {
            $candidates[] = $downloadUrl;
        }

        $candidates[] = $this->cdnDownloadUrl($fileId, $fileName);

        $candidates[] = $this->mirrorDownloadUrl($fileId, $fileName);

        if ($projectId !== '') {
            $resolvedUrl = $this->resolveDownloadUrl($projectId, (string) $fileId);

            if ($resolvedUrl !== null) {
                $candidates[] = $resolvedUrl;
            }

            $candidates[] = $this->websiteDownloadUrl($projectId, $fileId);
        }

        return array_values(array_unique($candidates));
    }

    // CurseForge's public website download page.
    private function websiteDownloadUrl(string $projectId, int $fileId): string
    {
        return 'https://www.curseforge.com/minecraft/mc-mods/'
            . $projectId
            . '/files/'
            . $fileId
            . '/download';
    }

    // Asks the CurseForge download-url endpoint for a live download URL for a specific file.
    private function resolveDownloadUrl(string $projectId, string $fileId): ?string
    {
        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/mods/'
                    . $projectId
                    . '/files/'
                    . $fileId
                    . '/download-url',
                headers: $this->headers(),
            );
        } catch (Throwable $exception) {
            if ($exception instanceof InstallationCancelledException) {
                throw $exception;
            }

            return null;
        }

        return $this->nullableString($response->body['data'] ?? null);
    }

    // Reconstructs the direct CurseForge CDN URL for a file id and name.
    private function cdnDownloadUrl(int $fileId, string $fileName): string
    {
        return self::CDN_BASE
            . '/'
            . intdiv($fileId, 1000)
            . '/'
            . ($fileId % 1000)
            . '/'
            . rawurlencode($fileName);
    }

    // Reconstructs the direct CurseForge media mirror URL for a file id and name.
    private function mirrorDownloadUrl(int $fileId, string $fileName): string
    {
        return self::MEDIA_MIRROR_BASE
            . '/'
            . intdiv($fileId, 1000)
            . '/'
            . ($fileId % 1000)
            . '/'
            . rawurlencode($fileName);
    }

    // Attempts each candidate download URL in order until one succeeds and returns its destination path, or null when every...
    private function downloadBestUrl(
        array $candidates,
        int &$networkBytesDone,
        int $archiveBytes,
        string $expectedSha1 = '',
    ): ?string {
        foreach ($candidates as $candidate) {
            $this->downloader->setProgressOffset(
                $networkBytesDone,
                max($networkBytesDone, $archiveBytes),
            );

            try {
                $downloadPath = $this->downloader->download($candidate);

                if ($expectedSha1 !== '') {
                    if ($this->matchesSha1($downloadPath, $expectedSha1)) {
                        return $downloadPath;
                    }

                    continue;
                }

                return $downloadPath;
            } catch (InvalidArgumentException | RuntimeException $exception) {
                if ($exception instanceof InstallationCancelledException) {
                    throw $exception;
                }

                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }
            }
        }

        return null;
    }

    private function manifestOverridesPath(array $manifest): string
    {
        $overrides = (string) ($manifest['overrides'] ?? '');
        $overrides = $overrides === ''
            ? 'overrides'
            : rtrim($overrides, '/');

        if ($overrides === '' || !$this->isSafeRelativePath($overrides)) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge modpack manifest contains an invalid overrides path.',
            );
        }

        return $overrides;
    }

    // @param array{priority: string}|null $existing
    private function shouldReplace(?array $existing, string $priority): bool
    {
        if ($existing === null) {
            return true;
        }

        return self::ENTRY_PRIORITIES[$priority]
            > self::ENTRY_PRIORITIES[$existing['priority']];
    }

    private function isSafeRelativePath(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    // Number of required mod files that may fail to download before an otherwise installable pack is rejected.
    private function toleratedModFailures(int $wantedMods): int
    {
        return max(5, (int) floor($wantedMods * 0.05));
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