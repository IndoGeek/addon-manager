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
        $identifier = $this->parseSource($source);

        if ($identifier === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $project = $this->fetchProject($identifier);

        $this->assertModpackProject($project);

        $versions = $this->fetchVersions($identifier);

        $version = $this->selectVersion($versions);

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
            source: $this->canonicalSource($project),
        );
    }

    public function getPackage(string $source): ModpackPackage
    {
        $identifier = $this->parseSource($source);

        if ($identifier === null) {
            throw new InvalidArgumentException(
                'Unsupported modpack source.',
            );
        }

        $project = $this->fetchProject($identifier);

        $this->assertModpackProject($project);

        $versions = $this->fetchVersions($identifier);

        $version = $this->selectVersion($versions);

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
                    source: $this->canonicalSource($project),
                );
            }

            $normalized = $this->buildServerArchive($archivePath);

            $this->removeTracked($archivePath);

            $this->temporaryPackages[$normalized] = true;

            return new ModpackPackage(
                archivePath: $normalized,
                source: $this->canonicalSource($project),
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

    private function canonicalSource(array $project): string
    {
        $slug = $this->nullableString($project['slug'] ?? null);

        return 'modrinth://' . ($slug ?? (string) ($project['id'] ?? ''));
    }

    /**
     * Resolves a source string to a Modrinth project id or slug.
     */
    private function parseSource(string $source): ?string
    {
        $trimmed = trim($source);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'modrinth://')) {
            $identifier = substr($trimmed, strlen('modrinth://'));

            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $identifier) === 1) {
                return $identifier;
            }

            return null;
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

        return $matches[1];
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