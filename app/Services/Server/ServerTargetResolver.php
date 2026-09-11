<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

final class ServerTargetResolver
{
    public function __construct(
        private readonly ServerFileTargetFactory $factory,
    ) {
    }

    public function resolve(ServerIdentity $server): ServerFileTarget
    {
        return $this->factory->forServer($server);
    }
}
