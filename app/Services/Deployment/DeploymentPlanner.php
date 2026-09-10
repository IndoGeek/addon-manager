<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use InvalidArgumentException;
use RuntimeException;

final class DeploymentPlanner
{
    public function plan(
        string $workspace,
        string $serverDirectory,
    ): DeploymentPlan {
        $workspace = $this->normalizeDirectory($workspace);
        $serverDirectory = $this->normalizeDirectory($serverDirectory);

        if (!is_dir($workspace)) {
            throw new InvalidArgumentException(
                'The installation workspace does not exist.'
            );
        }

        if (!is_dir($serverDirectory)) {
            throw new InvalidArgumentException(
                'The target server directory does not exist.'
            );
        }

        $create = [];
        $overwrite = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $workspace,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $sourcePath = $file->getPathname();

            $relativePath = ltrim(
                substr($sourcePath, strlen($workspace)),
                DIRECTORY_SEPARATOR,
            );

            $targetPath = $serverDirectory . '/' . $relativePath;

            if (is_file($targetPath)) {
                $overwrite[] = $relativePath;
            } else {
                $create[] = $relativePath;
            }
        }

        sort($create);
        sort($overwrite);

        return new DeploymentPlan(
            create: $create,
            overwrite: $overwrite,
        );
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
