<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

// Default Wings transport built on Guzzle (a Pterodactyl Panel dependency).
final class GuzzleWingsTransport implements WingsTransport
{
    public function __construct(
        private readonly int $timeout = 600,
        private readonly int $connectTimeout = 10,
        private readonly bool $verifySsl = true,
    ) {
    }

    public function request(string $method, string $url, array $options = []): WingsTransportResponse
    {
        try {
            $client = new Client([
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'verify' => $this->verifySsl,
                'http_errors' => false,
            ]);

            $response = $client->request($method, $url, $options);

            return new WingsTransportResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
            );
        } catch (TransferException $exception) {
            throw new WingsConnectionException(
                'Unable to reach the Wings node for this server.',
                0,
                $exception,
            );
        }
    }
}