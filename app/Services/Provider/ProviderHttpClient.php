<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

interface ProviderHttpClient
{
    /**
     * Performs a GET request and decodes the JSON response.
     *
     * @param array<string, mixed> $query   Query parameters to append.
     * @param array<string>        $headers Raw header lines, e.g. "Name: value".
     *
     * @throws ProviderHttpException When the request fails, returns a non-2xx
     *                               status, or contains malformed JSON.
     */
    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse;
}