<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CurseForgeProvider implements ModpackProvider, ManualDownloadProvider
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const CDN_BASE = 'https://edge.forgecdn.net/files';

    private const API_KEY_HEADER = 'X-Api-Key';

    private const MODPACK_CLASS_ID = 4471;

    private const MANIFEST_FILE = 'manifest.json';

    /**
     * Share of the download progress band reserved for the modpack archive
     * itself. Client packs are re-anchored on the full network footprint once
     * the manifest is parsed, so the archive phase should only ever occupy a
     * fraction of the bar instead of spiking and dropping on that re-anchor.
     */
    private const PROGRESS_ARCHIVE_SLICE = 0.12;

    /**
     * Winning source for a given relative server path: overrides win over
     * manifest mod files.
     */
    private const ENTRY_PRIORITIES = [
        'mods' => 0,
        'overrides' => 1,
    ];

    /**
     * @var array<string, true> Paths of temporary archives awaiting cleanup.
     */
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

    /**
     * Returns normalized manual-download info when a CurseForge file cannot be
     * downloaded automatically. The caller (frontend or controller) should
     * display this as a "manual download required" state rather than a generic
     * installation failure.
     *
     * @return array{provider: string, project_name: string, project_url: string,
     *   file_name: string, version: string, download_url: string|null, reason: string}
     */
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

    /**
     * Resolves the file a source would install and, when that file cannot be
     * downloaded automatically, returns normalized manual-download guidance.
     * Returns null when the file can be installed automatically.
     *
     * @return array{
     *   provider: string,
     *   project_name: string,
     *   project_url: string|null,
     *   file_name: string,
     *   version: string,
     *   download_url: string|null,
     *   reason: string,
     * }|null
     */
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

    /**
     * Returns a normalized server archive for the downloaded CurseForge file.
     * Dedicated server packs do not carry manifest.json and pass through
     * untouched (null). Client packs are rebuilt from their manifest: every
     * required, server-compatible mod file is downloaded into mods/ and the
     * overrides directory is applied at the archive root.
     *
     * Malformed or unresolvable manifests fail loudly (never silently skip) so
     * a partial install is never reported as complete.
     */
    private function manifestResolvedArchive(string $archivePath): ?string
    {
        $source = new ZipArchive();

        if ($source->open($archivePath) !== true) {
            throw new InvalidArgumentException(
                'The downloaded CurseForge file is not a valid archive.',
            );
        }

        try {
            if ($source->statName(self::MANIFEST_FILE) === false) {
                return null;
            }

            $rawManifest = $source->getFromName(self::MANIFEST_FILE);

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

            return $this->buildServerArchive($source, $archivePath, $manifest);
        } finally {
            $source->close();
        }
    }

    /**
     * Rebuilds a CurseForge client pack into a normalized server archive.
     *
     * @param array<string, array{archiveEntry: string|null, downloadUrls: array<int, string>, priority: string, bytes: int}> $content
     */
    private function buildServerArchive(
        ZipArchive $source,
        string $archivePath,
        array $manifest,
    ): string {
        $content = $this->collectArchiveContent(
            $source,
            $this->manifestOverridesPath($manifest),
        );

        [$installedMods, $wantedMods] = $this->mergeManifestFiles(
            $manifest,
            $content,
        );

        if ($content === []) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge modpack contains no server content to install (no mods or overrides).',
            );
        }

        if ($wantedMods > 0 && $installedMods === 0) {
            throw new UnsupportedModpackPackageException(
                'The CurseForge modpack references mod files with no usable download source, so no server mods could be installed.',
            );
        }

        // Re-anchor cumulative progress on the whole network footprint: the
        // downloaded client-pack bytes plus every manifest mod file still to
        // fetch. Offsets only ever grow, so the bar moves forward without
        // resetting between the per-mod downloads.
        $archiveBytes = max(0, (int) @filesize($archivePath));

        foreach ($content as $payload) {
            if ($payload['archiveEntry'] === null) {
                $archiveBytes += max(0, (int) $payload['bytes']);
            }
        }

        $networkBytesDone = max(0, (int) @filesize($archivePath));

        $this->downloader->setProgressOffset(
            $networkBytesDone,
            max($networkBytesDone, $archiveBytes),
        );

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

        $outputPath = $root . '/' . bin2hex(random_bytes(16)) . '.zip';

        $output = new ZipArchive();

        if ($output->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(
                'Unable to create the normalized package archive.',
            );
        }

        $scratchPaths = [];
        $downloadedTemps = [];

        $downloadedMods = 0;

        try {
            foreach ($content as $relative => $payload) {
                if ($this->downloader->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $stream = null;
                $scratch = null;

                try {
                    if ($payload['archiveEntry'] !== null) {
                        $stream = $source->getStream(
                            $payload['archiveEntry'],
                        );

                        if ($stream === false) {
                            throw new RuntimeException(
                                'Unable to read an entry from the modpack archive.',
                            );
                        }
                    } else {
                        $downloadPath = $this->downloadBestUrl(
                            $payload['downloadUrls'],
                            $networkBytesDone,
                            $archiveBytes,
                        );

                        if ($downloadPath === null) {
                            continue;
                        }

                        $downloadedMods++;

                        $downloadedTemps[] = $downloadPath;

                        $networkBytesDone += max(
                            0,
                            (int) $payload['bytes'],
                        );

                        $stream = @fopen($downloadPath, 'rb');

                        if ($stream === false) {
                            throw new RuntimeException(
                                'Unable to read a downloaded modpack file.',
                            );
                        }
                    }

                    $scratchPath = $root
                        . '/'
                        . bin2hex(random_bytes(16))
                        . '.bin';

                    $scratch = @fopen($scratchPath, 'wb');

                    if ($scratch === false) {
                        throw new RuntimeException(
                            'Unable to create a scratch file for the normalized archive.',
                        );
                    }

                    if (stream_copy_to_stream($stream, $scratch) === false) {
                        throw new RuntimeException(
                            'Unable to copy a modpack file into the normalized archive.',
                        );
                    }

                    $scratchPaths[] = $scratchPath;

                    if ($output->addFile($scratchPath, $relative) === false) {
                        throw new RuntimeException(
                            'Unable to add a modpack file to the normalized archive.',
                        );
                    }
                } finally {
                    if ($scratch !== null) {
                        fclose($scratch);
                    }

                    if ($stream !== null) {
                        fclose($stream);
                    }
                }
            }

            if ($wantedMods > 0 && $downloadedMods === 0) {
                throw new UnsupportedModpackPackageException(
                    'None of the CurseForge mod files referenced by the modpack could be downloaded, so no server mods could be installed.',
                );
            }
        } finally {
            $output->close();

            foreach ($scratchPaths as $scratchPath) {
                @unlink($scratchPath);
            }

            foreach ($downloadedTemps as $downloadPath) {
                @unlink($downloadPath);
            }
        }

        return $outputPath;
    }

    /**
     * Collects the overrides payload embedded in the client-pack archive
     * itself, dropping the configured overrides directory prefix so it lands
     * at the server root.
     *
     * @return array<string, array{archiveEntry: string, downloadUrls: array<int, string>, priority: string, bytes: int}>
     */
    private function collectArchiveContent(
        ZipArchive $source,
        string $overridesPath,
    ): array {
        $content = [];
        $prefix = $overridesPath . '/';

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

            $content[$relative] = [
                'archiveEntry' => $name,
                'downloadUrls' => [],
                'priority' => 'overrides',
                'bytes' => 0,
            ];
        }

        return $content;
    }

    /**
     * Resolves manifest mod files through the CurseForge API and merges the
     * resolvable ones into the normalized archive under mods/. Each mod file
     * is attached to an ordered list of candidate download URLs so a file the
     * bulk API reports without a download URL can still be pulled from the
     * CurseForge CDN layout. Files that individually fail every candidate at
     * download time are skipped with a diagnostic (never silently): the
     * official CurseForge flow keeps installing the rest of a pack when a few
     * mod files have died upstream. Only when no manifest mod file can be
     * installed does the caller abort.
     *
     * @return array{0: int, 1: int} [installed count, wanted count]
     */
    private function mergeManifestFiles(array $manifest, array &$content): array
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

            if ($this->shouldReplace($content[$relative] ?? null, 'mods')) {
                $content[$relative] = [
                    'archiveEntry' => null,
                    'downloadUrls' => $candidates,
                    'priority' => 'mods',
                    'bytes' => max(0, (int) ($file['fileLength'] ?? 0)),
                ];
            }

            $installed++;
        }

        if ($skipped > 0) {
            @error_log(
                'modpackinstaller manifest resolution skipped '
                . $skipped
                . ' of '
                . count($wanted)
                . ' mod files without any usable download source.'
            );
        }

        return [$installed, count($wanted)];
    }

    private function recordManifestSkip(int $fileId): void
    {
        @error_log(
            'modpackinstaller manifest resolution skipped mod file '
            . $fileId
            . ' (no usable download source on CurseForge).'
        );
    }

    /**
     * Resolves a list of CurseForge file ids through the bulk files endpoint
     * (chunked to the API's 50-per-request cap).
     *
     * @param array<int> $fileIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchModFiles(array $fileIds): array
    {
        $resolved = [];

        foreach (array_chunk(array_values(array_unique($fileIds)), 50) as $chunk) {
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

    /**
     * Derives the mods/ file name for a manifest file, falling back to the
     * file id when the API reports no usable file name.
     *
     * @param array<string, mixed>|null $file
     */
    private function manifestFileName(?array $file, int $fileId): string
    {
        $name = str_replace('\\', '/', (string) ($file['fileName'] ?? ''));

        $name = basename($name);

        if ($name === '' || !$this->isSafeRelativePath($name)) {
            $name = (string) $fileId;
        }

        return $name;
    }

    /**
     * Builds an ordered list of candidate download URLs for a manifest mod
     * file. The metadata download URL is preferred, then the dedicated
     * download-url endpoint is consulted for files that serve none, and the
     * URL reconstructed from the CurseForge CDN layout
     * (edge.forgecdn.net/files/{id/1000}/{id%1000}/{name}) is always kept as
     * a last resort so files the API reports without a download URL can still
     * be fetched.
     *
     * @param array<string, mixed>|null $file
     *
     * @return list<string>
     */
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
        } elseif ($projectId !== '') {
            $resolvedUrl = $this->resolveDownloadUrl($projectId, (string) $fileId);

            if ($resolvedUrl !== null) {
                $candidates[] = $resolvedUrl;
            }
        }

        $candidates[] = $this->cdnDownloadUrl($fileId, $fileName);

        return array_values(array_unique($candidates));
    }

    /**
     * Asks the CurseForge download-url endpoint for a live download URL for a
     * specific file. Returns null when the API rejects the request or serves
     * no URL so the caller can fall back to the reconstructed CDN URL.
     */
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

    /**
     * Reconstructs the direct CurseForge CDN URL for a file id and name. The
     * CDN can serve files even when the API metadata omits its download URL.
     */
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

    /**
     * Attempts each candidate download URL in order until one succeeds and
     * returns its destination path, or null when every candidate fails.
     *
     * @param non-empty-list<string> $candidates
     */
    private function downloadBestUrl(
        array $candidates,
        int &$networkBytesDone,
        int $archiveBytes,
    ): ?string {
        foreach ($candidates as $candidate) {
            $this->downloader->setProgressOffset(
                $networkBytesDone,
                max($networkBytesDone, $archiveBytes),
            );

            try {
                return $this->downloader->download($candidate);
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

    /**
     * @param array{priority: string}|null $existing
     */
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