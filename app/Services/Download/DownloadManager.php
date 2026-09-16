<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use RuntimeException;

final class DownloadManager implements ConcurrentDownloader
{
    private const REDIRECT_CAP_BYTES = 1_048_576;

    private const MAX_REDIRECTS = 5;

    /**
     * How many independent transfers downloadBatch() keeps in flight at once.
     * The ethics of higher numbers trade memory/descriptors for wall-clock
     * speed; eight is a safe balance for hundreds of mod files.
     */
    private const BATCH_CONCURRENCY = 8;

    /**
     * Total transfer attempts per hop, including the first. A mirror or
     * transport glitch can kill a large download mid-body, so a bounded
     * retry is worth more than surface diagnostic-only errors.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * A transfer that cannot push even one byte for this many seconds is
     * treated as dead. libcurl only invokes the transfer/cancel callback while
     * bytes are moving, so a server that accepts the connection but then stalls
     * leaves curl blocked in a read and the cancel checker is never reached.
     * This bounded stall detection turns such a hang into a normal transfer
     * error, after which the retry loop consults the cancel checker and aborts
     * promptly when a cancellation has been requested.
     */
    private const LOW_SPEED_LIMIT_BYTES = 1;

    private const LOW_SPEED_TIME_SECONDS = 8;

    /**
     * @var null|callable(int|null $downloadedBytes, int|null $totalBytes): void
     */
    private $progressCallback = null;

    /**
     * @var null|callable(): bool
     */
    private $cancelChecker = null;

    /**
     * Bytes already accounted for by earlier downloads in the same logical
     * operation, plus the operation-wide total when one has been anchored with
     * setProgressOffset(). While set, progress callbacks receive cumulative
     * values so a multi-part download (modpack archive plus its mod files)
     * renders as one smooth, monotonic progress bar instead of resetting.
     */
    private int $progressOffsetBytes = 0;

    private ?int $progressOffsetTotal = null;

    /**
     * Timestamp of the last progress write so manual reportProgress() calls
     * share the same 0.4s throttle as the transfer callback, keeping hot
     * packaging loops from flooding the progress store.
     */
    private float $lastProgressReport = 0.0;

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

    /**
     * Anchors progress reporting to a running total shared across several
     * downloads. See the Downloader interface for semantics.
     */
    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void
    {
        $this->progressOffsetBytes = max(0, $completedBytes);

        $this->progressOffsetTotal = $totalBytes === null
            ? null
            : max(0, $totalBytes);
    }

    public function isCancelled(): bool
    {
        $checker = $this->cancelChecker;

        return $checker !== null && $checker();
    }

    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void
    {
        if ($downloadedBytes < 0) {
            return;
        }

        $callback = $this->progressCallback;

        if ($callback === null) {
            return;
        }

        $now = microtime(true);

        if (($now - $this->lastProgressReport) < 0.4) {
            return;
        }

        $this->lastProgressReport = $now;

        $safeTotal = $totalBytes !== null && $totalBytes > 0
            ? $totalBytes
            : $downloadedBytes;

        try {
            $callback(
                min($downloadedBytes, $safeTotal),
                $safeTotal,
            );
        } catch (\Throwable) {
            // Progress reporting must never abort a composition step.
        }
    }

    private function ensureNotCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new InstallationCancelledException(
                'Installation cancelled.'
            );
        }
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
            $attemptsLeft = self::MAX_ATTEMPTS;

            while (true) {
                $this->ensureNotCancelled();

                // Give every hop its own freshly truncated slot so a single
                // hop body is exactly what ends up in the destination file.
                rewind($handle);
                ftruncate($handle, 0);

                try {
                    [$status, $headers] = $this->performRequest($current, $handle);
                } catch (RuntimeException $exception) {
                    if (
                        $exception instanceof InstallationCancelledException
                        || $attemptsLeft <= 1
                    ) {
                        throw $exception;
                    }

                    $attemptsLeft--;

                    // Consult the cancel flag before sleeping so a cancel
                    // requested during the backoff aborts promptly instead of
                    // after the pause.
                    $this->ensureNotCancelled();

                    usleep(1_000_000);

                    continue;
                }

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

    public function downloadBatch(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $this->ensureTemporaryRoot();

        $results = [];
        $pending = [];

        foreach ($tasks as $task) {
            $id = $task['id'] ?? '';
            $urls = $task['urls'] ?? null;
            $destination = $task['destination'] ?? '';

            if ($id === '' || !is_array($urls) || $destination === '') {
                throw new InvalidArgumentException(
                    'A download batch task is malformed.'
                );
            }

            $urls = array_values($urls);

            if ($urls === [] || $destination === '') {
                $results[$id] = null;
                continue;
            }

            $directory = dirname($destination);

            if (
                $directory !== ''
                && !is_dir($directory)
                && !@mkdir($directory, 0750, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create the download batch directory.'
                );
            }

            $handle = @fopen($destination, 'wb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Unable to create a download batch file.'
                );
            }

            $pending[$id] = [
                'id' => $id,
                'urls' => $urls,
                'index' => 0,
                'attempts' => self::MAX_ATTEMPTS,
                'hops' => 0,
                'url' => null,
                'destination' => $destination,
                'handle' => $handle,
                'headers' => '',
                'transferred' => 0,
                'active' => 0.0,
                'done' => false,
                'failed' => false,
                'size' => 0,
                'curl' => null,
            ];

            $results[$id] = null;
        }

        $multi = null;

        try {
            if ($pending === []) {
                return $results;
            }

            $multi = curl_multi_init();

            if ($multi === false) {
                throw new RuntimeException(
                    'Unable to initialize the concurrent HTTP client.'
                );
            }

            $active = []; // task ids with a live handle in the multi engine
            $running = 0;

            foreach ($pending as $id => &$state) {
                $this->batchReopen($state, $active, $multi);

                if (count($active) >= self::BATCH_CONCURRENCY) {
                    break;
                }
            }
            unset($state);

            $peak = 0;

            do {
                if ($this->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                $exec = curl_multi_exec($multi, $running);

                while ($exec === CURLM_CALL_MULTI_PERFORM) {
                    $exec = curl_multi_exec($multi, $running);
                }

                if ($exec !== CURLM_OK) {
                    throw new RuntimeException(
                        'The concurrent download engine failed.'
                    );
                }

                $this->processBatchMessages($multi, $pending, $active);

                if (count($active) < self::BATCH_CONCURRENCY) {
                    foreach ($pending as $id => &$state) {
                        if (
                            isset($active[$id])
                            || $state['done']
                            || $state['failed']
                        ) {
                            continue;
                        }

                        if (count($active) >= self::BATCH_CONCURRENCY) {
                            break;
                        }

                        $this->batchReopen($state, $active, $multi);
                    }
                    unset($state);
                }

                $peak = $this->aggregateBatchProgress($pending, $peak);

                if ($running > 0) {
                    curl_multi_select($multi, 0.2);
                }
            } while ($running > 0);

            foreach ($pending as $id => $state) {
                if ($state['done']) {
                    $results[$id] = $state['destination'];
                }
            }

            return $results;
        } finally {
            if ($multi !== null) {
                foreach ($pending as &$state) {
                    if ($state['curl'] !== null) {
                        curl_multi_remove_handle($multi, $state['curl']);
                        curl_close($state['curl']);
                        $state['curl'] = null;
                    }
                }
                unset($state);

                curl_multi_close($multi);
            }

            foreach ($pending as $id => $state) {
                if (is_resource($state['handle'])) {
                    fclose($state['handle']);
                }

                if (!$state['done'] && is_file($state['destination'])) {
                    @unlink($state['destination']);
                }
            }
        }
    }

    /**
     * Opens a curl handle for a task's current URL, or advances the task
     * through its remaining candidates when the current one cannot be used.
     * A task with no usable candidate is marked failed.
     *
     * @param array<string, mixed>       $state
     * @param array<string, true>        $active
     * @param \CurlMultiHandle           $multi
     */
    private function batchReopen(
        array &$state,
        array &$active,
        $multi,
    ): void {
        while (true) {
            if ($state['index'] >= count($state['urls'])) {
                if ($state['curl'] !== null) {
                    curl_multi_remove_handle($multi, $state['curl']);
                    curl_close($state['curl']);
                    $state['curl'] = null;
                }

                unset($active[$state['id']]);

                $state['failed'] = true;

                return;
            }

            // A retried or redirected task keeps its last-used URL; a task
            // that has not started yet takes the first candidate in its list.
            $url = $state['url'] !== null
                ? (string) $state['url']
                : (string) $state['urls'][$state['index']];

            try {
                $parts = $this->validateUrl($url);
                $resolveEntries = $this->resolveEntries($parts);
            } catch (InvalidArgumentException) {
                $state['index']++;
                $state['attempts'] = self::MAX_ATTEMPTS;
                $state['hops'] = 0;
                $state['url'] = null;

                continue;
            }

            $state['url'] = $url;

            $curl = $this->batchConfigureHandle($state, $resolveEntries);

            $state['curl'] = $curl;
            $active[$state['id']] = true;

            curl_multi_add_handle($multi, $curl);

            return;
        }
    }

    /**
     * Configures a ready-to-run curl handle for the given task, mirroring the
     * single-download option set (public-address pinning, manual redirects,
     * bounded low-speed and reference-counted size/cancel checks).
     *
     * @param array<string, mixed> $state
     * @param list<string>         $resolveEntries
     */
    private function batchConfigureHandle(array &$state, array $resolveEntries): \CurlHandle
    {
        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize the HTTP client.'
            );
        }

        $maxBytes = $this->maxDownloadBytes;
        $state['headers'] = '';
        $state['active'] = 0.0;

        curl_setopt_array($curl, [
            CURLOPT_URL => $state['url'],
            CURLOPT_FILE => $state['handle'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BYTES,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ModpackInstaller/1.0',
            CURLOPT_RESOLVE => $resolveEntries,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function (
                $curl,
                float $downloadSize,
                float $downloaded,
                float $uploadSize,
                float $uploaded,
            ) use ($maxBytes, &$state): int {
                $state['active'] = $downloaded;

                if ($downloaded > $maxBytes) {
                    return 1;
                }

                $checker = $this->cancelChecker;

                if ($checker !== null && $checker()) {
                    return 1;
                }

                return 0;
            },
            CURLOPT_HEADERFUNCTION => function (
                $curl,
                string $line,
            ) use (&$state): int {
                $state['headers'] .= $line;

                return strlen($line);
            },
        ]);

        return $curl;
    }

    /**
     * Drains every completed handle from the multi engine, applying the same
     * hop/retry/candidate state machine as download(): 2xx settles the task,
     * 3xx is redirected manually (re-validated and re-pinned per hop), hard
     * transfer errors retry up to MAX_ATTEMPTS per candidate, and any other
     * terminal state moves the task to its next candidate.
     *
     * @param \CurlMultiHandle    $multi
     * @param array<string, array<string, mixed>> $pending
     * @param array<string, true> $active
     */
    private function processBatchMessages(
        $multi,
        array &$pending,
        array &$active,
    ): void {
        while (true) {
            $info = curl_multi_info_read($multi, $queued);

            if ($info === false) {
                break;
            }

            $curl = $info['handle'];
            $result = (int) $info['result'];

            $id = null;

            foreach ($pending as $taskId => &$state) {
                if ($state['curl'] === $curl) {
                    $id = $taskId;
                    break;
                }
            }
            unset($state);

            if ($id === null) {
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);

                continue;
            }

            $state = &$pending[$id];

            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $transferBytes = (int) floor(
                curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD),
            );

            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);

            $state['curl'] = null;

            $state['transferred'] += $transferBytes;
            $state['active'] = 0.0;

            if ($result !== CURLE_OK) {
                if ($result === CURLE_ABORTED_BY_CALLBACK && $this->isCancelled()) {
                    throw new InstallationCancelledException(
                        'Installation cancelled.'
                    );
                }

                if ($result !== CURLE_ABORTED_BY_CALLBACK) {
                    $parts = $this->validateUrl((string) $state['url']);
                    $this->recordTransferFailure(
                        $curl,
                        $parts['host'],
                        (string) $state['url'],
                    );
                }

                $this->batchFailAttempt($state);

                if ($state['done'] || $state['failed']) {
                    unset($active[$id]);
                    continue;
                }

                $this->ensureNotCancelled();

                usleep(1_000_000);

                $this->batchResetFile($state);

                $this->batchReopen($state, $active, $multi);

                continue;
            }

            if ($status >= 200 && $status <= 299) {
                $state['done'] = true;
                $state['size'] = max(0, (int) @filesize($state['destination']));

                unset($active[$id]);

                continue;
            }

            if ($status >= 300 && $status <= 399) {
                if ($state['hops'] >= self::MAX_REDIRECTS) {
                    $this->batchToNextCandidate($state);
                } else {
                    try {
                        $hopBytes = $this->hopBytes($state['handle']);

                        if ($hopBytes > self::REDIRECT_CAP_BYTES) {
                            throw new InvalidArgumentException(
                                'The download server sent an excessive redirect response.',
                            );
                        }

                        $location = self::locationFromHeaders($state['headers']);

                        $newUrl = RedirectResolver::resolve(
                            (string) $state['url'],
                            $location,
                        );

                        $this->validateUrl($newUrl);

                        $state['url'] = $newUrl;
                        $state['hops']++;

                        $this->batchResetFile($state);

                        $this->batchReopen($state, $active, $multi);
                    } catch (InvalidArgumentException) {
                        $this->batchToNextCandidate($state);
                    }
                }

                if ($state['done'] || $state['failed']) {
                    unset($active[$id]);
                    continue;
                }

                continue;
            }

            $this->batchToNextCandidate($state);

            if ($state['done'] || $state['failed']) {
                unset($active[$id]);
            }
        }
    }

    /**
     * Handles a failed transfer attempt: retries the same URL when attempts
     * remain, otherwise falls through to the next candidate.
     *
     * @param array<string, mixed> $state
     */
    private function batchFailAttempt(array &$state): void
    {
        if ($state['attempts'] > 1) {
            $state['attempts']--;

            return;
        }

        $this->batchToNextCandidate($state);
    }

    /**
     * Moves a task to its next candidate (resetting hop/attempt state) or
     * marks it failed once every candidate has been tried.
     *
     * @param array<string, mixed> $state
     */
    private function batchToNextCandidate(array &$state): void
    {
        $state['index']++;
        $state['attempts'] = self::MAX_ATTEMPTS;
        $state['hops'] = 0;
        $state['url'] = null;

        if ($state['index'] >= count($state['urls'])) {
            $state['failed'] = true;

            return;
        }

        $this->batchResetFile($state);
    }

    /**
     * Truncates a task's destination slot for the next hop or attempt so the
     * file only ever holds the winning body.
     *
     * @param array<string, mixed> $state
     */
    private function batchResetFile(array &$state): void
    {
        rewind($state['handle']);
        ftruncate($state['handle'], 0);
        $state['headers'] = '';
    }

    /**
     * Reports an aggregated, monotonic progress value across every active and
     * settled task, anchored on the previously configured offset. Failed tasks
     * drop out of the running sum, so the reported value is clamped to the
     * highest value seen to keep the bar moving strictly forward.
     *
     * @param array<string, array<string, mixed>> $pending
     */
    private function aggregateBatchProgress(array &$pending, int $peak): int
    {
        $callback = $this->progressCallback;

        if ($callback === null) {
            return $peak;
        }

        $now = microtime(true);

        if (($now - $this->lastProgressReport) < 0.4) {
            return $peak;
        }

        $this->lastProgressReport = $now;

        $sum = 0;

        foreach ($pending as $state) {
            if ($state['failed']) {
                continue;
            }

            if ($state['done']) {
                $sum += $state['size'];

                continue;
            }

            $sum += $state['transferred'] + (int) floor($state['active']);
        }

        $done = max($peak, $this->progressOffsetBytes + $sum);

        $safeTotal = $this->progressOffsetTotal !== null
            && $this->progressOffsetTotal > 0
            ? $this->progressOffsetTotal
            : $done;

        try {
            $callback(min($done, $safeTotal), $safeTotal);
        } catch (\Throwable) {
            // Progress reporting must never abort a composition step.
        }

        return max($peak, $done);
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
        $resolveEntries = $this->resolveEntries($parts);

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
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BYTES,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ModpackInstaller/1.0',
            CURLOPT_RESOLVE => $resolveEntries,
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

                        $globalTotal = $this->progressOffsetTotal;

                        try {
                            if ($globalTotal !== null && $globalTotal > 0) {
                                $done = $this->progressOffsetBytes
                                    + (int) floor($downloaded);

                                $callback(
                                    min($done, $globalTotal),
                                    $globalTotal,
                                );
                            } else {
                                $callback(
                                    (int) floor($downloaded),
                                    $downloadSize > 0
                                        ? (int) floor($downloadSize)
                                        : null,
                                );
                            }
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
            $errno = curl_errno($curl);

            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
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

            $this->recordTransferFailure($curl, $parts['host'], $url);

            throw new RuntimeException(
                'Modpack download failed (curl error ' . $errno . '): '
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

    /**
     * Builds CURLOPT_RESOLVE entries for every verified public IPv4 address of
     * the download host. Pinning several addresses (instead of a single
     * resolved IP) lets libcurl fail over between CDN edges, which prevents a
     * single throttled or flaky edge from aborting a large transfer.
     *
     * IPv6 (AAAA) addresses are deliberately not pinned. When libcurl is given
     * a bond for an address family the host cannot route, it aborts the whole
     * connect instead of falling back to the pinned IPv4 address, so dual
     * stack hosts are pinned by their IPv4 addresses only.
     *
     * @param array{scheme: string, host: string, port?: int} $parts
     *
     * @return list<string>
     */
    private function resolveEntries(array $parts): array
    {
        $entries = [];

        foreach ($this->resolvePublicAddresses($parts['host']) as $ip) {
            $entries[] = $parts['host']
                . ':' . self::portFor($parts)
                . ':' . $ip;
        }

        return $entries;
    }

    /**
     * Resolves a host to its verified public addresses, preferring IPv4.
     *
     * @return list<string>
     */
    private function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicAddress($host);

            return [$host];
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

        $ipv4 = [];
        $ipv6 = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($ip === null) {
                continue;
            }

            try {
                $this->assertPublicAddress($ip);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (str_contains($ip, ':')) {
                $ipv6[$ip] = true;
            } else {
                $ipv4[$ip] = true;
            }
        }

        if ($ipv4 !== []) {
            return array_keys($ipv4);
        }

        if ($ipv6 !== []) {
            return array_keys($ipv6);
        }

        throw new InvalidArgumentException(
            'The download host resolves only to private or reserved addresses.'
        );
    }

    /**
     * Writes a structured diagnostic line before a transfer failure is
     * rethrown so production panels can see exactly which curl error killed
     * the download instead of only a generic "Unable to install the modpack".
     */
    private function recordTransferFailure(
        $curl,
        string $host,
        string $url,
    ): void {
        $expected = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        $details = [
            'event' => 'download_failure',
            'errno' => curl_errno($curl),
            'error' => trim((string) curl_error($curl)),
            'host' => $host,
            'has_query' => parse_url($url, PHP_URL_QUERY) !== false,
            'http_status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'downloaded_bytes' => (int) curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD),
            'expected_bytes' => $expected >= 0 ? (int) $expected : null,
            'speed_bytes_per_second' => (int) curl_getinfo($curl, CURLINFO_SPEED_DOWNLOAD),
            'elapsed_seconds' => round((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME), 2),
            'disk_free_bytes' => $this->temporaryDiskFree(),
            'memory_used_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'php_version' => PHP_VERSION,
        ];

        @error_log(
            'modpackinstaller download failure: '
            . (string) json_encode($details)
        );

        @error_log(
            'modpackinstaller download failure (human readable): '
            . 'errno=' . (string) $details['errno']
            . ' host=' . $host
            . ' status=' . (string) $details['http_status']
            . ' downloaded=' . (string) $details['downloaded_bytes']
            . ' expected=' . (string) ($details['expected_bytes'] ?? -1)
            . ' disk_free=' . (string) ($details['disk_free_bytes'] ?? -1)
            . ' memory_limit=' . (string) $details['memory_limit']
            . ' memory_used=' . (string) $details['memory_used_bytes']
            . ' error_message=' . (string) $details['error']
        );
    }

    private function temporaryDiskFree(): ?int
    {
        $free = @disk_free_space($this->temporaryRoot);

        return $free === false ? null : (int) $free;
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