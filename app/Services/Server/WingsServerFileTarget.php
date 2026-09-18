<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsFileClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsFileNotFoundException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsHttpException;
use RuntimeException;

// ServerFileTarget backed by the authenticated Pterodactyl->Wings file API.
final class WingsServerFileTarget implements ServerFileTarget
{
    private const ROOT = '/';

    /** @var array<string, array<string, string>> directory => (name => 'dir'|'file') */
    private array $listings = [];

    public function __construct(
        private readonly WingsFileClient $client,
    ) {
    }

    public function exists(string $relativePath): bool
    {
        return $this->entryType($relativePath) !== null;
    }

    public function isDirectory(string $relativePath): bool
    {
        return $this->entryType($relativePath) === 'dir';
    }

    public function read(string $relativePath): string
    {
        try {
            return $this->client->getContents($relativePath);
        } catch (WingsFileNotFoundException $exception) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}",
                0,
                $exception,
            );
        }
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        if ($this->entryType($path) === 'dir') {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        $parent = $this->parentOf($path);

        if ($parent !== self::ROOT) {
            $this->ensureDirectoryInternal($parent);
        }

        try {
            $this->client->putContents($path, $contents);
        } catch (WingsFileNotFoundException $exception) {
            // The parent directory disappeared between the ensure and the write; rebuild the tree once and retry before fai...
            if ($parent !== self::ROOT) {
                $this->ensureDirectoryInternal($parent);
            }

            try {
                $this->client->putContents($path, $contents);
            } catch (WingsFileNotFoundException $retryException) {
                throw new RuntimeException(
                    "Unable to write file: {$relativePath}",
                    0,
                    $retryException,
                );
            }
        }

        $this->forgetListingsFrom($path);
    }

    public function putFile(string $relativePath, string $sourcePath): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        if ($this->entryType($path) === 'dir') {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        $parent = $this->parentOf($path);

        if ($parent !== self::ROOT) {
            $this->ensureDirectoryInternal($parent);
        }

        try {
            $this->client->putFile($path, $sourcePath);
        } catch (WingsFileNotFoundException $exception) {
            // The parent directory disappeared between the ensure and the write; rebuild the tree once and retry before fai...
            if ($parent !== self::ROOT) {
                $this->ensureDirectoryInternal($parent);
            }

            try {
                $this->client->putFile($path, $sourcePath);
            } catch (WingsFileNotFoundException $retryException) {
                throw new RuntimeException(
                    "Unable to write file: {$relativePath}",
                    0,
                    $retryException,
                );
            }
        }

        $this->forgetListingsFrom($path);
    }

    public function getFile(string $relativePath, string $destinationPath): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        try {
            $contents = $this->client->getContents($path);
        } catch (WingsFileNotFoundException $exception) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}",
                0,
                $exception,
            );
        }

        $parent = dirname($destinationPath);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create destination directory: {$destinationPath}"
            );
        }

        if (file_put_contents($destinationPath, $contents) === false) {
            throw new RuntimeException(
                "Unable to copy file: {$relativePath}"
            );
        }
    }

    public function delete(string $relativePath): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        $type = $this->entryType($path);

        if ($type === 'dir') {
            throw new RuntimeException(
                "Cannot delete directory through file target: {$relativePath}"
            );
        }

        if ($type === null) {
            return;
        }

        $this->client->deleteFiles(
            $this->parentOf($path),
            [basename($path)],
        );

        $this->forgetListingsFrom($path);
    }

    public function isEmptyDirectory(string $relativePath): bool
    {
        $path = ServerRelativePath::normalize($relativePath);

        return $this->entryType($path) === 'dir'
            && $this->listing($path) === [];
    }

    public function removeDirectory(string $relativePath): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        if ($this->entryType($path) !== 'dir') {
            return;
        }

        if ($this->listing($path) !== []) {
            throw new RuntimeException(
                "Cannot remove non-empty directory through file target: {$relativePath}"
            );
        }

        $this->client->deleteFiles(
            $this->parentOf($path),
            [basename($path)],
        );

        $this->forgetListingsFrom($path);
    }

    public function ensureDirectory(string $relativePath): void
    {
        $this->ensureDirectoryInternal(
            ServerRelativePath::normalize($relativePath),
        );
    }

    private function ensureDirectoryInternal(string $path): void
    {
        $segments = explode('/', $path);
        $current = '';

        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            $type = $this->entryType($current);

            if ($type === 'dir') {
                continue;
            }

            if ($type === 'file') {
                throw new RuntimeException(
                    "Target path exists and is not a directory: {$current}"
                );
            }

            try {
                $this->client->createDirectory(
                    $segment,
                    $this->parentOf($current),
                );
            } catch (WingsFileNotFoundException $exception) {
                throw new RuntimeException(
                    "Unable to create directory: {$current}",
                    0,
                    $exception,
                );
            } catch (WingsHttpException $exception) {
                // Wings reports an already-existing directory with a non-2xx status.
                if (
                    $exception->getStatusCode() >= 500
                    && $this->freshEntryType($current) === 'dir'
                ) {
                    continue;
                }

                throw $exception;
            }

            $this->forgetListingsFrom($current);
        }
    }

    private function entryType(string $relativePath): ?string
    {
        $path = ServerRelativePath::normalize($relativePath);

        $listing = $this->listing($this->parentOf($path));

        return $listing[basename($path)] ?? null;
    }

    private function freshEntryType(string $relativePath): ?string
    {
        $path = ServerRelativePath::normalize($relativePath);

        $basename = basename($path);

        $entries = [];

        try {
            foreach ($this->client->listDirectory($this->parentOf($path)) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;

                if ($name === null || $name === '') {
                    continue;
                }

                $entries[$name] = $this->entryKind($entry);
            }
        } catch (WingsFileNotFoundException $exception) {
            // Absent directory implies an absent entry.
        }

        return $entries[$basename] ?? null;
    }

    // @return array<string, string> name => ('dir'|'file')
    private function listing(string $directory): array
    {
        if (array_key_exists($directory, $this->listings)) {
            return $this->listings[$directory];
        }

        $entries = [];

        try {
            foreach ($this->client->listDirectory($directory) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;

                if ($name === null || $name === '') {
                    continue;
                }

                $entries[$name] = $this->entryKind($entry);
            }
        } catch (WingsFileNotFoundException $exception) {
            // An absent directory simply has no entries.
        }

        return $this->listings[$directory] = $entries;
    }

    private function entryKind(array $entry): string
    {
        return (isset($entry['file']) && $entry['file'] === false)
            ? 'dir'
            : 'file';
    }

    private function parentOf(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? self::ROOT : $parent;
    }

    private function forgetListingsFrom(string $path): void
    {
        $current = ServerRelativePath::normalize($path);

        while (true) {
            unset($this->listings[$current]);

            if ($current === self::ROOT) {
                break;
            }

            $parent = $this->parentOf($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }
    }
}