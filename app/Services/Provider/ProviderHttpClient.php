<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

interface ProviderHttpClient
{
    // Performs a GET request and decodes the JSON response. status, or contains malformed JSON.
    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse;

    // Performs a POST request with a JSON body and decodes the response. status, or contains malformed JSON.
    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse;
}