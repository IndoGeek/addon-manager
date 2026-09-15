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

    public function prepare(
        string $archivePath,
        ?callable $cancelChecker = null,
    ): string {
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

        if ($cancelChecker !== null) {
            $extractor->setCancelChecker($cancelChecker);
        }

        return $extractor->extract($archivePath);
    }

    public function cleanup(string $workspace): void
    {
        if (!is_dir($workspace)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $workspace,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($workspace);
        } catch (\Throwable) {
            // Cleanup is best-effort and must never mask the installation
            // outcome, even when a workspace file is unreadable.
        }
    }
}
