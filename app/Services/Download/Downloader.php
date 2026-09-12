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
}