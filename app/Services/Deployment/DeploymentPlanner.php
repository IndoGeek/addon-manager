<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use InvalidArgumentException;

final class DeploymentPlanner
{
    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    public function plan(
        string $workspace,
    ): DeploymentPlan {
        $workspace = $this->normalizeWorkspace($workspace);

        if (!is_dir($workspace)) {
            throw new InvalidArgumentException(
                'The installation workspace does not exist.'
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

            if ($relativePath === '') {
                throw new InvalidArgumentException(
                    'The workspace contains an invalid empty path.'
                );
            }

            if (!str_starts_with($sourcePath, $workspace . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException(
                    "Workspace file escapes the extraction root: {$relativePath}"
                );
            }

            if ($this->serverFileTarget->exists($relativePath)) {
                if ($this->serverFileTarget->isDirectory($relativePath)) {
                    throw new InvalidArgumentException(
                        "Target path is a directory: {$relativePath}"
                    );
                }

                $operations[] = new DeploymentOperation(
                    relativePath: $relativePath,
                    source: $sourcePath,
                    destination: $relativePath,
                    overwrite: true,
                );

                continue;
            }

            $operations[] = new DeploymentOperation(
                relativePath: $relativePath,
                source: $sourcePath,
                destination: $relativePath,
                overwrite: false,
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

    private function normalizeWorkspace(string $workspace): string
    {
        $realPath = realpath($workspace);

        if ($realPath === false) {
            throw new InvalidArgumentException(
                "Unable to resolve workspace: {$workspace}"
            );
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }
}
