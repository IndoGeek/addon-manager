<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

final readonly class ProviderHttpResponse
{
    /**
     * @param array<mixed> $body Decoded JSON payload.
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {
    }
}