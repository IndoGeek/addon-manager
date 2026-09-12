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

final class ModrinthProvider implements ModpackProvider
{
    private const API_BASE = 'https://api.modrinth.com/v2';

    /**
     * @var array<string, true> Paths of temporary archives awaiting cleanup.
     */
    private array $temporaryPackages = [];

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly Downloader $downloader,
        private readonly string $temporaryRoot,
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

        $project = $this->fetchProject($parsed['project']);

        $this->assertModpackProject($project);

        $version = $this->selectedVersion(
            $parsed['versionId'],
            $project,
            $parsed['project'],
        );

        $minecraftVersion =
            $this->firstNonEmpty($version['game_versions'] ?? [])
            ?? $this->firstNonEmpty($project['game_versions'] ?? []);

        if ($minecraftVersion === null) {
            throw new InvalidArgumentException(
                'The Modrinth version does not declare a supported Minecraft version.',
            );
        }

        return new ModpackMetadata(
            id: (string) ($project['id'] ?? ''),
            name: (string) ($project['title'] ?? ''),
            version: (string) ($version['version_number'] ?? ''),
            minecraftVersion: $minecraftVersion,
            loader: $this->firstNonEmpty($version['loaders'] ?? []),
            description: $this->nullableString($project['description'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->nullableString($project['icon_url'] ?? null),
            ),
            source: $this->canonicalSource($project, $parsed['versionId']),
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

        $project = $this->fetchProject($parsed['project']);

        $this->assertModpackProject($project);

        $version = $this->selectedVersion(
            $parsed['versionId'],
            $project,
            $parsed['project'],
        );

        $file = $this->selectPrimaryFile($version['files'] ?? []);

        if ($file === null) {
            throw new InvalidArgumentException(
                'The Modrinth version does not provide a package file.',
            );
        }

        $filename = (string) ($file['filename'] ?? '');
        $extension = strtolower(
            (string) pathinfo($filename, PATHINFO_EXTENSION),
        );

        if (!in_array($extension, ['mrpack', 'zip'], true)) {
            throw new InvalidArgumentException(
                'The Modrinth version does not provide a supported modpack archive.',
            );
        }

        $url = (string) ($file['url'] ?? '');

        if ($url === '') {
            throw new InvalidArgumentException(
                'The Modrinth version file does not have a download URL.',
            );
        }

        $archivePath = $this->downloader->download($url);

        $this->temporaryPackages[$archivePath] = true;

        try {
            if ($extension !== 'mrpack') {
                return new ModpackPackage(
                    archivePath: $archivePath,
                    source: $this->canonicalSource($project, $parsed['versionId']),
                );
            }

            $normalized = $this->buildServerArchive($archivePath);

            $this->removeTracked($archivePath);

            $this->temporaryPackages[$normalized] = true;

            return new ModpackPackage(
                archivePath: $normalized,
                source: $this->canonicalSource($project, $parsed['versionId']),
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
     * @return array<string, mixed>
     */
    private function fetchProject(string $identifier): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/project/' . rawurlencode($identifier),
            );

            if (!is_array($response->body)) {
                throw new InvalidArgumentException(
                    'The Modrinth project response was invalid.',
                );
            }

            return $response->body;
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                throw new InvalidArgumentException(
                    'The Modrinth project was not found.',
                );
            }

            if (
                $exception->status() !== null
                && $exception->status() >= 400
                && $exception->status() < 500
            ) {
                throw new InvalidArgumentException(
                    'Modrinth rejected the request.',
                );
            }

            throw new InvalidArgumentException(
                'Unable to load the modpack from Modrinth.',
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchVersions(string $identifier): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/project/' . rawurlencode($identifier) . '/version',
            );

            if (!is_array($response->body)) {
                throw new InvalidArgumentException(
                    'The Modrinth version list was invalid.',
                );
            }

            foreach ($response->body as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException(
                        'The Modrinth version list was invalid.',
                    );
                }
            }

            return $response->body;
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                throw new InvalidArgumentException(
                    'The Modrinth project was not found.',
                );
            }

            if (
                $exception->status() !== null
                && $exception->status() >= 400
                && $exception->status() < 500
            ) {
                throw new InvalidArgumentException(
                    'Modrinth rejected the request.',
                );
            }

            throw new InvalidArgumentException(
                'Unable to load the modpack versions from Modrinth.',
            );
        }
    }

    /**
     * @param array<string, mixed> $project
     *
     * @return array<string, mixed>
     */
    private function selectedVersion(
        ?string $versionId,
        array $project,
        string $projectIdentifier,
    ): array {
        if ($versionId !== null) {
            $version = $this->fetchVersionById($versionId);

            $this->assertVersionBelongsToProject($version, $project);

            return $version;
        }

        return $this->selectVersion(
            $this->fetchVersions($projectIdentifier),
        );
    }

    /**
     * Fetches a single Modrinth version by its exact id.
     *
     * @return array<string, mixed>
     */
    private function fetchVersionById(string $versionId): array
    {
        try {
            $response = $this->http->get(
                self::API_BASE . '/version/' . rawurlencode($versionId),
            );

            if (!is_array($response->body)) {
                throw new InvalidArgumentException(
                    'The Modrinth version response was invalid.',
                );
            }

            return $response->body;
        } catch (ProviderHttpException $exception) {
            if ($exception->status() === 404) {
                throw new InvalidArgumentException(
                    'The requested Modrinth version was not found.',
                );
            }

            if (
                $exception->status() !== null
                && $exception->status() >= 400
                && $exception->status() < 500
            ) {
                throw new InvalidArgumentException(
                    'Modrinth rejected the request.',
                );
            }

            throw new InvalidArgumentException(
                'Unable to load the Modrinth version.',
            );
        }
    }

    /**
     * Prevents a client-supplied version id from referencing a version that
     * belongs to a different project. A version payload without a project id
     * is tolerated so providers without the field keep working, but any id
     * that is present must match the resolved project.
     *
     * @param array<string, mixed> $version
     * @param array<string, mixed> $project
     */
    private function assertVersionBelongsToProject(
        array $version,
        array $project,
    ): void {
        $versionProjectId = (string) ($version['project_id'] ?? '');

        if ($versionProjectId === '') {
            return;
        }

        if ($versionProjectId !== (string) ($project['id'] ?? '')) {
            throw new InvalidArgumentException(
                'The requested version does not belong to the selected project.',
            );
        }
    }

    /**
     * @param array<string, mixed> $project
     */
    private function assertModpackProject(array $project): void
    {
        if (($project['project_type'] ?? '') !== 'modpack') {
            throw new InvalidArgumentException(
                'The Modrinth project is not a modpack.',
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $versions
     *
     * @return array<string, mixed>
     */
    private function selectVersion(array $versions): array
    {
        if ($versions === []) {
            throw new InvalidArgumentException(
                'The Modrinth project has no published versions.',
            );
        }

        $sorted = $versions;

        usort($sorted, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['date_published'] ?? ''));
            $bTime = strtotime((string) ($b['date_published'] ?? ''));

            return $bTime <=> $aTime;
        });

        foreach ($sorted as $version) {
            if (($version['version_type'] ?? '') === 'release') {
                return $version;
            }
        }

        return $sorted[0];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     *
     * @return array<string, mixed>|null
     */
    private function selectPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (($file['primary'] ?? false) === true) {
                return $file;
            }
        }

        return $files[0] ?? null;
    }

    private function buildServerArchive(string $archivePath): string
    {
        $source = new ZipArchive();

        if ($source->open($archivePath) !== true) {
            throw new RuntimeException(
                'The downloaded modpack archive could not be opened.',
            );
        }

        try {
            $content = [];

            for ($index = 0; $index < $source->numFiles; $index++) {
                $entry = $source->statIndex($index);

                if ($entry === false) {
                    continue;
                }

                $name = (string) ($entry['name'] ?? '');

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $payload = $this->serverEntryName($name);

                if ($payload === null) {
                    continue;
                }

                if (
                    $payload['priority'] === 'server-overrides'
                    || !isset($content[$payload['relative']])
                ) {
                    $content[$payload['relative']] = [
                        'archiveEntry' => $name,
                        'priority' => $payload['priority'],
                    ];
                }
            }

            if ($content === []) {
                throw new UnsupportedModpackPackageException(
                    'The Modrinth modpack contains no server content to install (no overrides or server-overrides directory).',
                );
            }

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

            try {
                foreach ($content as $relative => $payload) {
                    $contents = $source->getFromName($payload['archiveEntry']);

                    if ($contents === false) {
                        continue;
                    }

                    $output->addFromString($relative, $contents);
                }
            } finally {
                $output->close();
            }

            return $outputPath;
        } finally {
            $source->close();
        }
    }

    /**
     * Resolves an mrpack entry to a server-deployable relative path.
     *
     * @return array{relative: string, priority: string}|null
     */
    private function serverEntryName(string $name): ?array
    {
        $priority = null;
        $relative = null;

        if (str_starts_with($name, 'overrides/')) {
            $priority = 'overrides';
            $relative = substr($name, strlen('overrides/'));
        } elseif (str_starts_with($name, 'server-overrides/')) {
            $priority = 'server-overrides';
            $relative = substr($name, strlen('server-overrides/'));
        }

        if ($priority === null || $relative === null || $relative === '') {
            return null;
        }

        if (!$this->isSafeRelativePath($relative)) {
            throw new InvalidArgumentException(
                'The modpack archive contains an invalid entry path.',
            );
        }

        return [
            'relative' => $relative,
            'priority' => $priority,
        ];
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

    private function canonicalSource(
        array $project,
        ?string $versionId = null,
    ): string {
        $slug = $this->nullableString($project['slug'] ?? null);

        $base = 'modrinth://'
            . ($slug ?? (string) ($project['id'] ?? ''));

        if ($versionId !== null && $versionId !== '') {
            return $base . '@' . $versionId;
        }

        return $base;
    }

    /**
     * Resolves a source string to a Modrinth project id/slug and an optional
     * exact-version pin (modrinth://slug@versionId). Returns null when the
     * syntax is unsupported.
     *
     * @return array{project: string, versionId: string|null}|null
     */
    private function parseSource(string $source): ?array
    {
        $trimmed = trim($source);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'modrinth://')) {
            $rest = substr($trimmed, strlen('modrinth://'));

            $pieces = explode('@', $rest, 2);

            $identifier = (string) ($pieces[0] ?? '');

            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $identifier) !== 1) {
                return null;
            }

            $versionId = null;

            if (isset($pieces[1])) {
                $versionId = trim((string) $pieces[1]);

                if (
                    $versionId === ''
                    || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $versionId) !== 1
                ) {
                    return null;
                }
            }

            return [
                'project' => $identifier,
                'versionId' => $versionId,
            ];
        }

        $parts = parse_url($trimmed);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($host, ['modrinth.com', 'www.modrinth.com'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if (preg_match('#^/modpack/([A-Za-z0-9_-]{1,64})$#', $path, $matches) !== 1) {
            return null;
        }

        return [
            'project' => $matches[1],
            'versionId' => null,
        ];
    }

    /**
     * @param array<mixed> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
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
