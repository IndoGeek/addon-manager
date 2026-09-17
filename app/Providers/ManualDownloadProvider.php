<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use InvalidArgumentException;

// Providers that may return files which cannot be downloaded automatically (for example a CurseForge file without a...
interface ManualDownloadProvider
{
    // Returns normalized manual-download guidance for a source, or null when the given file can be downloaded automatically.
    public function manualDownloadInfoFor(string $source): ?array;
}