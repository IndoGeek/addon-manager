<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;
use Throwable;

final class DeploymentExecutor
{
    /**
     * @var null|callable(int $deployedFiles, int $totalFiles): void
     */
    private $progressCallback = null;

    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
        private readonly int $maxFileBytes = 10_737_418_240,
    ) {
    }

    /**
     * Registers a callback invoked after each deployed file reports its
     * running count against the plan's total.
     *
     * @param null|callable(int $deployedFiles, int $totalFiles): void $callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    public function execute(
        DeploymentPlan $plan,
    ): array {
        $deployed = [];
        $total = $plan->totalFiles();
        $done = 0;

        try {
            foreach ($plan->operations as $operation) {
                $this->deployFile($operation);

                $done++;

                $deployed[] = $operation->relativePath;

                $callback = $this->progressCallback;

                if ($callback !== null) {
                    try {
                        $callback($done, $total);
                    } catch (Throwable) {
                        // Progress reporting must never mask a deployment.
                    }
                }
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

        $sourceBytes = filesize($operation->source);

        if (
            $sourceBytes === false
            || $sourceBytes > $this->maxFileBytes
        ) {
            throw new RuntimeException(
                "Workspace file is too large: {$relativePath}"
            );
        }

        if ($this->serverFileTarget->isDirectory($relativePath)) {
            throw new RuntimeException(
                "Target path is a directory: {$relativePath}"
            );
        }

        $this->serverFileTarget->putFile(
            $relativePath,
            $operation->source,
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
