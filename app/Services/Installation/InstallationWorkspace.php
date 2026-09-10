<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveExtractor;

final class InstallationWorkspace
{
    public function __construct(
        private readonly string $temporaryRoot,
    ) {
    }

    public function prepare(string $archivePath): string
    {
        if ($archivePath === '') {
            throw new InvalidArgumentException(
                'An archive path is required.'
            );
        }

        $workspaceRoot = $this->temporaryRoot . '/workspaces';

        if (
            !is_dir($workspaceRoot)
            && !mkdir($workspaceRoot, 0750, true)
            && !is_dir($workspaceRoot)
        ) {
            throw new \RuntimeException(
                'Unable to create the installation workspace root.'
            );
        }

        $extractor = new ArchiveExtractor($workspaceRoot);

        return $extractor->extract($archivePath);
    }
}
