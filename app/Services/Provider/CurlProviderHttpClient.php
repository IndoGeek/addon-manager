<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

use InvalidArgumentException;

final class CurlProviderHttpClient implements ProviderHttpClient
{
    private const CONNECT_TIMEOUT = 15;

    private const REQUEST_TIMEOUT = 30;

    private const MAX_RESPONSE_BYTES = 8_388_608;

    public function __construct(
        private readonly int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
    ) {
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->assertHttpUrl($url);

        return $this->execute(
            $this->appendQuery($url, $query),
            $headers,
        );
    }

    public function post(
        string $url,
        array $body = [],
        array $headers = [],
    ): ProviderHttpResponse {
        $this->assertHttpUrl($url);

        $headers[] = 'Content-Type: application/json';

        return $this->execute($url, $headers, $body);
    }

    private function execute(
        string $target,
        array $headers,
        array $body = [],
    ): ProviderHttpResponse {
        $curl = curl_init();

        if ($curl === false) {
            throw new ProviderHttpException(
                'Unable to initialize the HTTP client.',
            );
        }

        $headers = $this->normalizeHeaders($headers);

        $options = [
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
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function (
                $curl,
                float $downloadSize,
                float $downloaded,
                float $uploadSize,
                float $uploaded,
            ): int {
                return $downloaded > $this->maxResponseBytes ? 1 : 0;
            },
        ];

        if ($body !== []) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);

        if ($raw === false) {
            if (curl_errno($curl) === CURLE_ABORTED_BY_CALLBACK) {
                throw new ProviderHttpException(
                    'The provider response was too large.',
                );
            }

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
}

    // CURLOPT_HTTPHEADER takes raw header LINES, and curl uses the array's
    // VALUES: a "Name => value" map is therefore sent as a bare value with no
    // header name, which silently drops the header entirely (an API key that
    // never leaves the panel, answered with a 403). Both shapes are accepted
    // here so that mistake can never produce an unauthenticated request.
    // @param array<mixed> $headers @return array<int, string>
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_string($name)) {
                $normalized[] = $name
                    . ': '
                    . (is_scalar($value) ? (string) $value : '');

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
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