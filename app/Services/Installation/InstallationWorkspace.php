<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveExtractor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;

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

        // Providers that materialize a ready-to-deploy directory (client-pack normalizers) pass a folder instead of an ...
        if (is_dir($archivePath)) {
            return $this->prepareDirectory($archivePath, $cancelChecker);
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

    private function prepareDirectory(
        string $archivePath,
        ?callable $cancelChecker = null,
    ): string {
        $resolved = realpath($archivePath);

        if ($resolved === false) {
            throw new InvalidArgumentException(
                'The package workspace could not be resolved.'
            );
        }

        $root = rtrim(
            (string) realpath($this->temporaryRoot),
            DIRECTORY_SEPARATOR,
        );

        if (
            $root === ''
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException(
                'The package workspace is outside the temporary root.'
            );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $resolved,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $checker = $cancelChecker;

            if ($checker !== null && $checker()) {
                throw new InstallationCancelledException(
                    'Installation cancelled.'
                );
            }

            if ($item->isLink()) {
                throw new InvalidArgumentException(
                    'The package workspace contains a symlink entry.'
                );
            }
        }

        return $resolved;
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
            // Cleanup is best-effort and must never mask the installation outcome, even when a workspace file is unreadable.
        }
    }
}
