<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use InvalidArgumentException;
use RuntimeException;

final class BackupManager
{
    public function backup(
        string $serverDirectory,
        string $relativePath,
        string $backupDirectory,
    ): string {
        $serverDirectory = $this->normalizeDirectory($serverDirectory);

        if ($relativePath === '') {
            throw new InvalidArgumentException(
                'A relative file path is required.'
            );
        }

	if (
	    str_starts_with($relativePath, '/')
	    || str_contains($relativePath, "\0")
	) {
	    throw new InvalidArgumentException(
		'The backup path must be relative and contain no null bytes.'
	    );
	}

	$segments = preg_split(
	    '#[\\\\/]+#',
	    $relativePath,
	    -1,
	    PREG_SPLIT_NO_EMPTY,
	);

	if ($segments === false || in_array('..', $segments, true)) {
	    throw new InvalidArgumentException(
		'The backup path must not contain parent traversal.'
	    );
	}

        $source = $serverDirectory . '/' . $relativePath;

        if (!is_file($source)) {
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

        if (!copy($source, $backupPath)) {
            throw new RuntimeException(
                "Unable to back up: {$relativePath}"
            );
        }

        return $backupPath;
    }

    private function normalizeDirectory(string $directory): string
    {
        $realPath = realpath($directory);

        if ($realPath === false) {
            throw new RuntimeException(
                "Unable to resolve directory: {$directory}"
            );
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }
}
