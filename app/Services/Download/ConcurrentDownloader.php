<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

interface ConcurrentDownloader extends Downloader
{
    // Downloads a set of unrelated files concurrently, returning one result per task so callers can apply their own per-file...
    public function downloadBatch(array $tasks): array;
}