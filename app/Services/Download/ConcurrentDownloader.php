<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

interface ConcurrentDownloader extends Downloader
{
    /**
     * Downloads a set of unrelated files concurrently, returning one result
     * per task so callers can apply their own per-file failure policy.
     *
     * Each task carries an ordered list of candidate URLs (tried in order, the
     * same fallback behaviour a single download() call would exhibit) and the
     * absolute destination the winning body is written to. Transfers keep every
     * single-download guarantee: public-address pinning, per-hop SSRF checks,
     * manual redirect handling with caps, low-speed dead-transfer detection,
     * bounded per-candidate retries and a hard size ceiling.
     *
     * The progress offsets previously anchored with setProgressOffset() stay
     * valid: the engine aggregates in-flight bytes on top of the cumulative
     * completed count and never lets the reported value move backwards.
     *
     * @param array<int, array{id: string, urls: array<int, string>, destination: string}> $tasks
     *
     * @return array<string, string|null> Destination path per task id, or null
     *                                    when every candidate for that task
     *                                    failed. Successful destinations contain
     *                                    the downloaded body; failed destinations
     *                                    are removed before returning.
     *
     * @throws \Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException
     *         When the cancel predicate fires while a transfer is in flight.
     * @throws \RuntimeException          When the concurrent engine itself fails.
     */
    public function downloadBatch(array $tasks): array;
}