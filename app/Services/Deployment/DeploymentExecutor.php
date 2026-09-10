<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;
use Throwable;

final class DeploymentExecutor
{
    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    public function execute(
        DeploymentPlan $plan,
    ): array {
        $deployed = [];

        try {
            foreach ($plan->operations as $operation) {
                $this->deployFile($operation);

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

    private function deployFile(
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

        $contents = file_get_contents($operation->source);

        if ($contents === false) {
            throw new RuntimeException(
                "Unable to read workspace file: {$relativePath}"
            );
        }

        if ($this->serverFileTarget->isDirectory($relativePath)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        $this->serverFileTarget->write(
            $relativePath,
            $contents,
        );
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
