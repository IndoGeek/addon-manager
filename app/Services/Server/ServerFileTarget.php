<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

interface ServerFileTarget
{
    public function exists(string $relativePath): bool;

    public function isDirectory(string $relativePath): bool;

    public function read(string $relativePath): string;

    public function write(string $relativePath, string $contents): void;

    public function delete(string $relativePath): void;

    public function ensureDirectory(string $relativePath): void;
}
