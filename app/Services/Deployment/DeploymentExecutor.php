<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use RuntimeException;
use Throwable;

final class DeploymentExecutor
{
    public function execute(
        string $workspace,
        string $serverDirectory,
        DeploymentPlan $plan,
    ): array {
        $workspace = $this->normalizeDirectory($workspace);
        $serverDirectory = $this->normalizeDirectory($serverDirectory);

        $deployed = [];

        try {
            foreach ($plan->create as $relativePath) {
                $this->copyFile(
                    $workspace,
                    $serverDirectory,
                    $relativePath,
                );

                $deployed[] = $relativePath;
            }

            foreach ($plan->overwrite as $relativePath) {
                $this->copyFile(
                    $workspace,
                    $serverDirectory,
                    $relativePath,
                );

                $deployed[] = $relativePath;
            }
        } catch (Throwable $exception) {
            throw new DeploymentException(
                $exception->getMessage(),
                $deployed,
                $exception,
            );
        }

        return $deployed;
    }

    private function copyFile(
        string $workspace,
        string $serverDirectory,
        string $relativePath,
    ): void {
        $relativePath = $this->validateRelativePath($relativePath);

        $source = $workspace . '/' . $relativePath;
        $target = $serverDirectory . '/' . $relativePath;

        if (!is_file($source)) {
            throw new RuntimeException(
                "Workspace file does not exist: {$relativePath}"
            );
        }

        $parent = dirname($target);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create target directory: {$parent}"
            );
        }

        if (is_dir($target)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        if (!copy($source, $target)) {
            throw new RuntimeException(
                "Unable to deploy file: {$relativePath}"
            );
        }
    }

    private function validateRelativePath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new RuntimeException(
                'Deployment path must not be empty or contain NUL bytes.'
            );
        }

        $normalized = str_replace('\\', '/', $relativePath);

        if (
            str_starts_with($normalized, '/') ||
            preg_match('/^[A-Za-z]:\//', $normalized) === 1
        ) {
            throw new RuntimeException(
                "Deployment path must be relative: {$relativePath}"
            );
        }

        $parts = explode('/', $normalized);

        foreach ($parts as $part) {
            if ($part === '..') {
                throw new RuntimeException(
                    "Deployment path contains parent traversal: {$relativePath}"
                );
            }
        }

        return $normalized;
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
