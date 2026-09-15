<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

interface Downloader
{
    /**
     * Downloads a remote archive into a secure temporary file.
     *
     * @throws \InvalidArgumentException When the URL is unsafe or invalid.
     * @throws \RuntimeException          When the download fails.
     */
    public function download(string $url): string;

    /**
     * Anchors progress reporting to a running total shared across several
     * downloads. The completed count must keep rising across every download
     * in the batch so callers observe a monotonic, cumulative progress bar.
     *
     * @param int      $completedBytes Bytes completed by *earlier* downloads.
     * @param int|null $totalBytes     Grand total for the whole batch, or null
     *                                 to fall back to per-download reporting.
     */
    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void;

    /**
     * Returns whether the configured cancel predicate currently fires, so
     * callers can abort long-running composition steps (packaging, scrubbing)
     * even when no transfer is actively in flight.
     */
    public function isCancelled(): bool;
}