<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

/**
 * A deliberately thin HTTP pipe used by the Wings client so that the
 * transport can be swapped for a fake in tests.
 */
interface WingsTransport
{
    /**
     * @param array<string, mixed> $options Guzzle-compatible request options
     *
     * @throws WingsConnectionException on network/timeout failures
     */
    public function request(string $method, string $url, array $options = []): WingsTransportResponse;
}