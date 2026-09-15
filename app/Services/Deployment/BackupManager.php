<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;

final class BackupManager
{
    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    public function backup(
        string $relativePath,
        string $backupDirectory,
    ): string {
        $this->validateRelativePath($relativePath);

        if (
            !$this->serverFileTarget->exists($relativePath)
            || $this->serverFileTarget->isDirectory($relativePath)
        ) {
            throw new InvalidArgumentException(
                "The server file does not exist: {$relativePath}"
            );
        }

        if (
            !is_dir($backupDirectory)
            && !mkdir($backupDirectory, 0750, true)
            && !is_dir($backupDirectory)
        ) {
            throw new RuntimeException(
                'Unable to create the backup directory.'
            );
        }

        $backupPath = rtrim(
            $backupDirectory,
            DIRECTORY_SEPARATOR,
        ) . '/' . $relativePath;

        $parent = dirname($backupPath);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create backup directory: {$parent}"
            );
        }

        $backupReal = realpath($backupDirectory);
        $parentReal = realpath($parent) ?: $parent;

        if (
            $backupReal === false
            || (
                $parentReal !== $backupReal
                && !str_starts_with(
                    $parentReal,
                    $backupReal . DIRECTORY_SEPARATOR,
                )
            )
        ) {
            throw new RuntimeException(
                'Backup target escapes the backup directory.'
            );
        }

        $this->serverFileTarget->getFile(
            $relativePath,
            $backupPath,
        );

        return $backupPath;
    }

    private function validateRelativePath(string $relativePath): void
    {
        if ($relativePath === '') {
            throw new InvalidArgumentException(
                'A relative file path is required.'
            );
        }

        if (str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException(
                'The backup path cannot contain null bytes.'
            );
        }

        if (
            str_starts_with($relativePath, '/')
            || str_starts_with($relativePath, '\\')
        ) {
            throw new InvalidArgumentException(
                'The backup path must be relative.'
            );
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath) === 1) {
            throw new InvalidArgumentException(
                'Windows drive paths are not allowed.'
            );
        }

        $segments = preg_split(
            '#[\\\\\/]+#',
            $relativePath,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if ($segments === false || in_array('..', $segments, true)) {
            throw new InvalidArgumentException(
                'The backup path must not contain parent traversal.'
            );
        }
    }
}
