<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

final class ServerFileTargetFactory
{
    public function __construct(
        private readonly string $volumesRoot,
    ) {
    }

    public function forServer(ServerIdentity $server): ServerFileTarget
    {
        return new LocalFilesystemServerFileTarget(
            $this->volumesRoot . '/' . $server->uuid,
        );
    }
}
