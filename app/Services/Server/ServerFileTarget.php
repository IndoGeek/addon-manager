<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

interface ServerFileTarget
{
    public function exists(string $relativePath): bool;

    public function isDirectory(string $relativePath): bool;

    public function read(string $relativePath): string;

    public function write(string $relativePath, string $contents): void;

    public function delete(string $relativePath): void;

    /** Returns true only when the directory exists and contains no entries. */
    public function isEmptyDirectory(string $relativePath): bool;

    /**
     * Removes a directory that is currently empty. Implementations MUST NOT
     * recurse or tolerate a non-empty directory: callers check emptiness first.
     */
    public function removeDirectory(string $relativePath): void;

    public function ensureDirectory(string $relativePath): void;
}
