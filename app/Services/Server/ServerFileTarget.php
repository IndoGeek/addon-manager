<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

interface ServerFileTarget
{
    public function exists(string $relativePath): bool;

    public function isDirectory(string $relativePath): bool;

    public function read(string $relativePath): string;

    public function write(string $relativePath, string $contents): void;

    /**
     * Copies a local source file into the target, streaming the bytes so that
     * arbitrarily large files can be moved without buffering them in memory.
     */
    public function putFile(string $relativePath, string $sourcePath): void;

    /**
     * Copies a target file out to a local destination path, streaming the
     * bytes so that arbitrarily large files never have to fit in memory.
     */
    public function getFile(string $relativePath, string $destinationPath): void;

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
