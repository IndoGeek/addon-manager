<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

use InvalidArgumentException;
use RuntimeException;

final class DownloadManager implements Downloader
{
    public function __construct(
        private readonly string $temporaryRoot,
        private readonly int $maxDownloadBytes = 2_147_483_648,
    ) {
    }

    public function download(string $url): string
    {
        $parts = $this->validateUrl($url);
        $ip = $this->resolveSafeAddress($parts['host']);

        $this->ensureTemporaryRoot();

        $destination = $this->temporaryRoot . '/' . bin2hex(random_bytes(16)) . '.zip';

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create the temporary download file.'
            );
        }

        $curl = curl_init();

        if ($curl === false) {
            fclose($handle);
            @unlink($destination);

            throw new RuntimeException(
                'Unable to initialize the HTTP client.'
            );
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'ModpackInstaller/1.0',
                CURLOPT_RESOLVE => [
                    $parts['host'] . ':' . $port . ':' . $ip,
                ],
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function (
                    $curl,
                    float $downloadSize,
                    float $downloaded,
                    float $uploadSize,
                    float $uploaded,
                ): int {
                    if ($downloaded > $this->maxDownloadBytes) {
                        return 1;
                    }

                    return 0;
                },
            ]);

            $success = curl_exec($curl);

            if ($success !== true) {
                $error = curl_error($curl);

                throw new RuntimeException(
                    'Modpack download failed: ' . $error
                );
            }

            $downloadedBytes = filesize($destination);

            if ($downloadedBytes === false) {
                throw new RuntimeException(
                    'Unable to determine the downloaded file size.'
                );
            }

            if ($downloadedBytes > $this->maxDownloadBytes) {
                throw new InvalidArgumentException(
                    'The modpack download exceeds the maximum allowed size.'
                );
            }
        } catch (\Throwable $exception) {
            @unlink($destination);

            throw $exception;
        } finally {
            curl_close($curl);
            fclose($handle);
        }

        return $destination;
    }

    /**
     * @return array{
     *     scheme: string,
     *     host: string,
     *     port?: int
     * }
     */
    private function validateUrl(string $url): array
    {
        if ($url === '') {
            throw new InvalidArgumentException(
                'A download URL is required.'
            );
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'The download URL is invalid.'
            );
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'Only HTTP and HTTPS download URLs are allowed.'
            );
        }

        $host = $parts['host'] ?? '';

	if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
	    $host = substr($host, 1, -1);
	}

        if ($host === '') {
            throw new InvalidArgumentException(
                'The download URL must contain a host.'
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'Download URLs cannot contain credentials.'
            );
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];

            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException(
                    'The download URL contains an invalid port.'
                );
            }

            return [
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
            ];
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
        ];
    }

    private function resolveSafeAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicAddress($host);

            return $host;
        }

        $records = dns_get_record(
            $host,
            DNS_A | DNS_AAAA,
        );

        if ($records === false || $records === []) {
            throw new InvalidArgumentException(
                'Unable to resolve the download host.'
            );
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($ip === null) {
                continue;
            }

            try {
                $this->assertPublicAddress($ip);

                return $ip;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        throw new InvalidArgumentException(
            'The download host resolves only to private or reserved addresses.'
        );
    }

    private function assertPublicAddress(string $ip): void
    {
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false
        ) {
            throw new InvalidArgumentException(
                'The download host resolves to a private or reserved address.'
            );
        }
    }

    private function ensureTemporaryRoot(): void
    {
        if (
            !is_dir($this->temporaryRoot)
            && !mkdir($this->temporaryRoot, 0750, true)
            && !is_dir($this->temporaryRoot)
        ) {
            throw new RuntimeException(
                'Unable to create the temporary download directory.'
            );
        }
    }
}
