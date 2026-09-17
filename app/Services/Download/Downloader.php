<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

interface Downloader
{
    // Downloads a remote archive into a secure temporary file.
    public function download(string $url): string;

    // Anchors progress reporting to a running total shared across several downloads.
    public function setProgressOffset(int $completedBytes, ?int $totalBytes): void;

    // Returns whether the configured cancel predicate currently fires, so callers can abort long-running composition steps...
    public function isCancelled(): bool;

    // Reports an absolute, monotonic progress value for composition steps that do not stream a transfer themselves...
    public function reportProgress(int $downloadedBytes, ?int $totalBytes): void;
}