<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use RuntimeException;
use Throwable;

final class DeploymentExecutor
{
    public function execute(
        DeploymentPlan $plan,
    ): array {
        $deployed = [];

        try {
            foreach ($plan->operations as $operation) {
                $this->copyFile($operation);

                $deployed[] = $operation->relativePath;
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
        DeploymentOperation $operation,
    ): void {
        $relativePath = $this->validateRelativePath(
            $operation->relativePath,
        );

        if (!is_file($operation->source)) {
            throw new RuntimeException(
                "Workspace file does not exist: {$relativePath}"
            );
        }

        $parent = dirname($operation->destination);

        if (
            !is_dir($parent)
            && !mkdir($parent, 0750, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException(
                "Unable to create target directory: {$parent}"
            );
        }

        if (is_dir($operation->destination)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        if (!copy($operation->source, $operation->destination)) {
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
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
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
}
