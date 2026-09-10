<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

final class ServerFileTargetFactory
{
    public function __construct(
        private readonly string $localRoot,
    ) {}

    public function forServer(ServerIdentity $server): ServerFileTarget
    {
        return new LocalFilesystemServerFileTarget($this->localRoot);
    }
}
