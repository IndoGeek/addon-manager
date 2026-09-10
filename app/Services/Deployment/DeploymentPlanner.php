<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use InvalidArgumentException;
use RuntimeException;

final class DeploymentPlanner
{
    public function plan(
        string $workspace,
        string $serverDirectory,
        DeploymentPolicy $policy = DeploymentPolicy::CREATE_ONLY,
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

        $operations = [];

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
                if ($policy !== DeploymentPolicy::OVERWRITE) {
                    continue;
                }

                $operations[] = new DeploymentOperation(
                    relativePath: $relativePath,
                    source: $sourcePath,
                    destination: $targetPath,
                    policy: DeploymentPolicy::OVERWRITE,
                );

                continue;
            }

            $operations[] = new DeploymentOperation(
                relativePath: $relativePath,
                source: $sourcePath,
                destination: $targetPath,
                policy: DeploymentPolicy::CREATE_ONLY,
            );
        }

        usort(
            $operations,
            static fn (
                DeploymentOperation $a,
                DeploymentOperation $b,
            ): int => strcmp($a->relativePath, $b->relativePath),
        );

        return new DeploymentPlan($operations);
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
