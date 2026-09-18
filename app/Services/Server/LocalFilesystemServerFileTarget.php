<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use RuntimeException;

final class LocalFilesystemServerFileTarget implements ServerFileTarget
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException(
                'Server file target root does not exist or is not a directory.'
            );
        }

        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->resolve($relativePath));
    }

    public function isDirectory(string $relativePath): bool
    {
        return is_dir($this->resolve($relativePath));
    }

    public function read(string $relativePath): string
    {
        $path = $this->resolve($relativePath);

        if (!is_file($path)) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}"
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                "Unable to read file: {$relativePath}"
            );
        }

        return $contents;
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->resolve($relativePath);

        if (is_dir($path)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        $parent = dirname($path);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create target directory: {$relativePath}"
            );
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(
                "Unable to write file: {$relativePath}"
            );
        }
    }

    public function putFile(string $relativePath, string $sourcePath): void
    {
        $path = $this->resolve($relativePath);

        if (is_dir($path)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException(
                "Source file does not exist: {$sourcePath}"
            );
        }

        $parent = dirname($path);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create target directory: {$relativePath}"
            );
        }

        // Write to a sibling temporary file and atomically rename it into place so a crash mid-copy never leaves a trun...
        $temporary = $parent
            . '/.'
            . basename($path)
            . '.'
            . bin2hex(random_bytes(4))
            . '.tmp';

        if (!@copy($sourcePath, $temporary)) {
            @unlink($temporary);

            throw new RuntimeException(
                "Unable to write file: {$relativePath}"
            );
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                "Unable to write file: {$relativePath}"
            );
        }
    }

    public function getFile(string $relativePath, string $destinationPath): void
    {
        $path = $this->resolve($relativePath);

        if (!is_file($path)) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}"
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

        if (!copy($path, $destinationPath)) {
            throw new RuntimeException(
                "Unable to copy file: {$relativePath}"
            );
        }
    }

    public function delete(string $relativePath): void
    {
        $path = $this->resolve($relativePath);

        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            throw new RuntimeException(
                "Cannot delete directory through file target: {$relativePath}"
            );
        }

        if (!unlink($path)) {
            throw new RuntimeException(
                "Unable to delete file: {$relativePath}"
            );
        }
    }

    public function isEmptyDirectory(string $relativePath): bool
    {
        $path = $this->resolve($relativePath);

        if (!is_dir($path)) {
            return false;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return false;
        }

        return count($entries) <= 2;
    }

    public function removeDirectory(string $relativePath): void
    {
        $path = $this->resolve($relativePath);

        if (!is_dir($path)) {
            return;
        }

        if (!@rmdir($path)) {
            throw new RuntimeException(
                "Unable to remove directory: {$relativePath}"
            );
        }
    }

    public function ensureDirectory(string $relativePath): void
    {
        $path = $this->resolve($relativePath);

        if (is_dir($path)) {
            return;
        }

        if (file_exists($path)) {
            throw new RuntimeException(
                "Target path exists and is not a directory: {$relativePath}"
            );
        }

        if (!mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException(
                "Unable to create directory: {$relativePath}"
            );
        }
    }

    private function resolve(string $relativePath): string
    {
        $this->validateRelativePath($relativePath);

        return $this->root . DIRECTORY_SEPARATOR . $relativePath;
    }

    private function validateRelativePath(string $relativePath): void
    {
        if ($relativePath === '') {
            throw new RuntimeException(
                'Relative path cannot be empty.'
            );
        }

        if (str_contains($relativePath, "\0")) {
            throw new RuntimeException(
                'Relative path cannot contain NUL bytes.'
            );
        }

        if (
            str_starts_with($relativePath, '/')
            || str_starts_with($relativePath, '\\')
        ) {
            throw new RuntimeException(
                "Absolute paths are not allowed: {$relativePath}"
            );
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath) === 1) {
            throw new RuntimeException(
                "Windows drive paths are not allowed: {$relativePath}"
            );
        }

        $segments = preg_split('/[\\\\\/]+/', $relativePath);

        if ($segments === false) {
            throw new RuntimeException(
                "Unable to validate relative path: {$relativePath}"
            );
        }

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new RuntimeException(
                    "Parent traversal is not allowed: {$relativePath}"
                );
            }
        }
    }
}
