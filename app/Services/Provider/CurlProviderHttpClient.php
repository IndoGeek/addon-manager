<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

use InvalidArgumentException;

final class CurlProviderHttpClient implements ProviderHttpClient
{
    private const CONNECT_TIMEOUT = 15;

    private const REQUEST_TIMEOUT = 30;

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->assertHttpUrl($url);

        $target = $this->appendQuery($url, $query);

        $curl = curl_init();

        if ($curl === false) {
            throw new ProviderHttpException(
                'Unable to initialize the HTTP client.',
            );
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $target,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'ModpackInstaller/1.0',
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $raw = curl_exec($curl);

            if ($raw === false) {
                throw new ProviderHttpException(
                    'Unable to reach the provider.',
                );
            }

            $status = (int) curl_getinfo(
                $curl,
                CURLINFO_RESPONSE_CODE,
            );

            if ($status < 200 || $status > 299) {
                throw new ProviderHttpException(
                    "The provider returned an HTTP {$status} response.",
                    $status,
                );
            }

            $payload = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ProviderHttpException(
                    'The provider returned a malformed JSON response.',
                    $status,
                );
            }

            if (!is_array($payload)) {
                throw new ProviderHttpException(
                    'The provider returned an unexpected response format.',
                    $status,
                );
            }

            return new ProviderHttpResponse($status, $payload);
        } finally {
            curl_close($curl);
        }
    }

    private function assertHttpUrl(string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException(
                'A provider URL is required.',
            );
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'The provider URL is invalid.',
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'Only HTTP and HTTPS provider URLs are allowed.',
            );
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new InvalidArgumentException(
                'The provider URL must contain a host.',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'Provider URLs cannot contain credentials.',
            );
        }
    }

    private function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }
}