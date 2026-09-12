<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

/**
 * Default Wings transport built on Guzzle (a Pterodactyl Panel dependency).
 *
 * Auth header construction and the node connection address are handled by
 * WingsFileClient/WingsServerFileTargetFactory; this transport only performs
 * the raw HTTP round trip. HTTP error statuses are returned to the caller for
 * mapping (http_errors disabled) so status handling lives in one place.
 */
final class GuzzleWingsTransport implements WingsTransport
{
    public function __construct(
        private readonly int $timeout = 30,
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