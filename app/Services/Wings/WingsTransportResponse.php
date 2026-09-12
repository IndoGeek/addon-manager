<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

final readonly class WingsTransportResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }
}