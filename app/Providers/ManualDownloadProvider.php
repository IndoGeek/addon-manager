<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;

/**
 * Providers that may return files which cannot be downloaded automatically
 * (for example a CurseForge file without a public download URL). The catalog
 * and installation layers stay generic: only a provider that explicitly
 * implements this interface exposes normalized manual-download information.
 */
interface ManualDownloadProvider
{
    /**
     * Returns normalized manual-download guidance for a source, or null when
     * the given file can be downloaded automatically. Never exposes API keys,
     * internal paths or raw upstream payloads.
     *
     * @return array{
     *   provider: string,
     *   project_name: string,
     *   project_url: string|null,
     *   file_name: string,
     *   version: string,
     *   download_url: string|null,
     *   reason: string,
     * }|null
     *
     * @throws InvalidArgumentException when the source is unsupported or the
     *   provider is not configured.
     */
    public function manualDownloadInfoFor(string $source): ?array;
}