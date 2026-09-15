<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

require_once __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;

final class FakeServerFileTarget implements ServerFileTarget
{
    private array $files = [];

    private array $directories = [];

    public function exists(string $relativePath): bool
    {
        return isset($this->files[$relativePath])
            || isset($this->directories[$relativePath]);
    }

    public function isDirectory(string $relativePath): bool
    {
        return isset($this->directories[$relativePath]);
    }

    public function read(string $relativePath): string
    {
        if (!isset($this->files[$relativePath])) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}"
            );
        }

        return $this->files[$relativePath];
    }

    public function write(string $relativePath, string $contents): void
    {
        $this->files[$relativePath] = $contents;
    }

    public function putFile(string $relativePath, string $sourcePath): void
    {
        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            throw new RuntimeException(
                "Unable to read source file: {$sourcePath}"
            );
        }

        $this->files[$relativePath] = $contents;
    }

    public function getFile(string $relativePath, string $destinationPath): void
    {
        if (!isset($this->files[$relativePath])) {
            throw new RuntimeException(
                "File does not exist: {$relativePath}"
            );
        }

        if (file_put_contents($destinationPath, $this->files[$relativePath]) === false) {
            throw new RuntimeException(
                "Unable to write destination file: {$destinationPath}"
            );
        }
    }

    public function delete(string $relativePath): void
    {
        unset($this->files[$relativePath]);
        unset($this->directories[$relativePath]);
    }

    public function isEmptyDirectory(string $relativePath): bool
    {
        return isset($this->directories[$relativePath]);
    }

    public function removeDirectory(string $relativePath): void
    {
        if (!isset($this->directories[$relativePath])) {
            return;
        }

        foreach ($this->files as $path => $contents) {
            if (str_starts_with($path, rtrim($relativePath, '/') . '/')) {
                unset($this->files[$path]);
            }
        }

        unset($this->directories[$relativePath]);
    }

    public function ensureDirectory(string $relativePath): void
    {
        $this->directories[$relativePath] = true;
    }
}

$target = new FakeServerFileTarget();

$target->ensureDirectory('mods');

if (!$target->isDirectory('mods')) {
    throw new RuntimeException('Directory was not created.');
}

$target->write('mods/example.jar', 'test-content');

if (!$target->exists('mods/example.jar')) {
    throw new RuntimeException('File was not written.');
}

if ($target->read('mods/example.jar') !== 'test-content') {
    throw new RuntimeException('File contents are incorrect.');
}

$target->delete('mods/example.jar');

if ($target->exists('mods/example.jar')) {
    throw new RuntimeException('File was not deleted.');
}

echo "Server file target test passed.\n";
