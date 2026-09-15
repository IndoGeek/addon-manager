<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use RuntimeException;

final class DownloadManager implements Downloader
{
    private const REDIRECT_CAP_BYTES = 1_048_576;

    private const MAX_REDIRECTS = 5;

    /**
     * @var null|callable(int|null $downloadedBytes, int|null $totalBytes): void
     */
    private $progressCallback = null;

    /**
     * @var null|callable(): bool
     */
    private $cancelChecker = null;

    /**
     * Additional reserved/private networks that PHP's filter_var IP flags do
     * not classify as non-public. They must never be reachable from the
     * downloader (metadata services, multicast, NAT gateways, benchmarks and
     * documentation ranges).
     *
     * @var array<int, non-empty-string>
     */
    private const BLOCKED_CIDRS = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        'fe80::/10',
        'ff00::/8',
        '64:ff9b::/96',
        '2001::/23',
        '::ffff:0:0/96',
    ];

    public function __construct(
        private readonly string $temporaryRoot,
        private readonly int $maxDownloadBytes = 10_737_418_240,
    ) {
    }

    /**
     * Registers a callback invoked while the payload body is being streamed
     * to disk so callers can surface download progress.
     *
     * @param null|callable(int|null $downloadedBytes, int|null $totalBytes): void $callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Registers a predicate consulted mid-transfer. When it returns true the
     * in-flight request aborts and the download is treated as cancelled.
     *
     * @param null|callable(): bool $checker
     */
    public function setCancelChecker(?callable $checker): void
    {
        $this->cancelChecker = $checker;
    }

    public function download(string $url): string
    {
        $this->ensureTemporaryRoot();

        $destination = $this->temporaryRoot . '/' . bin2hex(random_bytes(16)) . '.zip';

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create the temporary download file.'
            );
        }

        try {
            $current = $url;
            $hops = 0;

            while (true) {
                // Give every hop its own freshly truncated slot so a single
                // hop body is exactly what ends up in the destination file.
                rewind($handle);
                ftruncate($handle, 0);

                [$status, $headers] = $this->performRequest($current, $handle);

                if ($status >= 200 && $status <= 299) {
                    break;
                }

                if ($status < 300 || $status > 399) {
                    throw new InvalidArgumentException(
                        'The download server rejected the request.',
                    );
                }

                if ($hops >= self::MAX_REDIRECTS) {
                    throw new InvalidArgumentException(
                        'The download URL redirected too many times.',
                    );
                }

                $hopBytes = $this->hopBytes($handle);

                if ($hopBytes > self::REDIRECT_CAP_BYTES) {
                    throw new InvalidArgumentException(
                        'The download server sent an excessive redirect response.',
                    );
                }

                $location = self::locationFromHeaders($headers);

                // The resolved URL is re-validated and its address re-checked
                // on the next loop iteration, before any connection is made.
                $current = RedirectResolver::resolve($current, $location);
                $this->validateUrl($current);

                $hops++;
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

            return $destination;
        } catch (\Throwable $exception) {
            @unlink($destination);

            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Performs a single HTTP request, streaming any body into the provided
     * handle, and returns [status code, raw response headers].
     *
     * @return array{0: int, 1: string}
     */
    private function performRequest(
        string $url,
        $handle,
    ): array {
        $parts = $this->validateUrl($url);
        $ip = $this->resolveSafeAddress($parts['host']);

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize the HTTP client.'
            );
        }

        $maxBytes = $this->maxDownloadBytes;
        $headers = '';
        $lastProgressReport = 0.0;

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ModpackInstaller/1.0',
            CURLOPT_RESOLVE => [
                $parts['host'] . ':' . self::portFor($parts) . ':' . $ip,
            ],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function (
                $curl,
                float $downloadSize,
                float $downloaded,
                float $uploadSize,
                float $uploaded,
            ) use ($maxBytes, &$lastProgressReport): int {
                if ($downloaded > $maxBytes) {
                    return 1;
                }

                $checker = $this->cancelChecker;

                if ($checker !== null && $checker()) {
                    return 1;
                }

                $callback = $this->progressCallback;

                if ($callback !== null) {
                    $now = microtime(true);

                    if (($now - $lastProgressReport) >= 0.4) {
                        $lastProgressReport = $now;

                        try {
                            $callback(
                                (int) floor($downloaded),
                                $downloadSize > 0
                                    ? (int) floor($downloadSize)
                                    : null,
                            );
                        } catch (\Throwable) {
                            // Progress reporting must never abort a download.
                        }
                    }
                }

                return 0;
            },
            CURLOPT_HEADERFUNCTION => function (
                $curl,
                string $line,
            ) use (&$headers): int {
                $headers .= $line;

                return strlen($line);
            },
        ]);

        $success = curl_exec($curl);

        $status = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE,
        );

        if ($success !== true) {
            if (curl_errno($curl) === CURLE_ABORTED_BY_CALLBACK) {
                $checker = $this->cancelChecker;

                if ($checker !== null && $checker()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                throw new InvalidArgumentException(
                    'The modpack download exceeds the maximum allowed size.'
                );
            }

            throw new RuntimeException(
                'Modpack download failed: '
                . $this->sanitizedCurlError($curl, $url)
            );
        }

        return [$status, $headers];
    }

    private function hopBytes($handle): int
    {
        $stat = fstat($handle);

        if ($stat === false) {
            return PHP_INT_MAX;
        }

        return (int) $stat['size'];
    }

    private function sanitizedCurlError($curl, string $url): string
    {
        $error = trim((string) curl_error($curl));

        if ($error === '') {
            return 'The download request failed.';
        }

        return str_replace($url, '<url>', $error);
    }

    private static function locationFromHeaders(string $headers): string
    {
        foreach (preg_split('/\r?\n/', $headers) as $line) {
            $line = trim($line);

            if (preg_match('/^location:\s*(.*)$/i', $line, $matches) === 1) {
                $location = trim($matches[1]);

                if ($location !== '') {
                    return $location;
                }
            }
        }

        throw new InvalidArgumentException(
            'The download server redirected without indicating a location.',
        );
    }

    /**
     * @param array{scheme: string, host: string, port?: int} $parts
     */
    private static function portFor(array $parts): int
    {
        return $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
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

        if (strlen($url) > 8192) {
            throw new InvalidArgumentException(
                'The download URL is too long.'
            );
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'The download URL is invalid.'
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

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

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                throw new InvalidArgumentException(
                    'The download host resolves to a private or reserved address.'
                );
            }
        }
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr), 2, null);

        if ($prefix === null || !is_numeric($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;

        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);

        if ($packedIp === false || $packedNetwork === false) {
            return false;
        }

        $bytes = strlen($packedNetwork);

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        for ($index = 0; $index < $bytes; $index++) {
            if ($index < $fullBytes) {
                if ($packedIp[$index] !== $packedNetwork[$index]) {
                    return false;
                }

                continue;
            }

            if ($remainingBits === 0) {
                continue;
            }

            $mask = chr(0xFF << (8 - $remainingBits));

            if (
                ($packedIp[$index] & $mask) !== ($packedNetwork[$index] & $mask)
            ) {
                return false;
            }

            break;
        }

        return true;
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