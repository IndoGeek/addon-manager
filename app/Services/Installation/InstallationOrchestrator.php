<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use RuntimeException;
use Throwable;

final class InstallationOrchestrator
{
    public function __construct(
        private readonly InstallationWorkspace $workspaceManager,
        private readonly DeploymentPlanner $planner,
        private readonly BackupManager $backupManager,
        private readonly DeploymentExecutor $executor,
        private readonly string $temporaryRoot,
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    public function install(
        string $archivePath,
    ): InstallationResult {
        $workspace = null;
        $backupDirectory = null;
        $backups = [];
        $created = [];

        try {
            $workspace = $this->workspaceManager->prepare($archivePath);

            $plan = $this->planner->plan($workspace);

            $backupDirectory = $this->createBackupDirectory();

            foreach ($plan->operations as $operation) {
                if (!$operation->overwrite) {
                    continue;
                }

                $backups[$operation->relativePath] = $this->backupManager->backup(
                    $operation->relativePath,
                    $backupDirectory,
                );
            }

            $created = $this->executor->execute($plan);

            $createdPaths = [];
            $overwrittenPaths = [];

            foreach ($plan->operations as $operation) {
                if ($operation->overwrite) {
                    $overwrittenPaths[] = $operation->relativePath;
                    continue;
                }

                $createdPaths[] = $operation->relativePath;
            }

            return new InstallationResult(
                created: $createdPaths,
                overwritten: $overwrittenPaths,
                backedUp: array_keys($backups),
            );
        } catch (Throwable $exception) {
            if ($exception instanceof DeploymentException) {
                $created = array_merge(
                    $created,
                    $exception->deployed(),
                );
            }

            $rollbackErrors = $this->rollback(
                $backups,
                $created,
            );

            if ($rollbackErrors !== []) {
                throw new RuntimeException(
                    'Installation failed and rollback was incomplete: '
                    . implode('; ', $rollbackErrors),
                    0,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if ($workspace !== null) {
                $this->workspaceManager->cleanup($workspace);
            }

            if ($backupDirectory !== null) {
                $this->removeDirectory($backupDirectory);
            }
        }
    }

    private function createBackupDirectory(): string
    {
        $root = rtrim($this->temporaryRoot, DIRECTORY_SEPARATOR)
            . '/backups';

        if (
            !is_dir($root)
            && !mkdir($root, 0750, true)
            && !is_dir($root)
        ) {
            throw new RuntimeException(
                'Unable to create the backup root.'
            );
        }

        $directory = $root . '/' . bin2hex(random_bytes(16));

        if (!mkdir($directory, 0750, true)) {
            throw new RuntimeException(
                'Unable to create the backup directory.'
            );
        }

        return $directory;
    }

    private function rollback(
        array $backups,
        array $created,
    ): array {
        $errors = [];

        foreach (array_reverse($created) as $relativePath) {
            try {
                if (
                    $this->serverFileTarget->exists($relativePath)
                    && !$this->serverFileTarget->isDirectory($relativePath)
                ) {
                    $this->serverFileTarget->delete($relativePath);
                }
            } catch (Throwable $exception) {
                $errors[] = "Unable to remove created file: {$relativePath}";
            }
        }

        foreach ($backups as $relativePath => $backupPath) {
            try {
                $contents = file_get_contents($backupPath);

                if ($contents === false) {
                    throw new RuntimeException(
                        "Unable to read backup: {$relativePath}"
                    );
                }

                $parent = dirname($relativePath);

                if ($parent !== '.') {
                    $this->serverFileTarget->ensureDirectory($parent);
                }

                $this->serverFileTarget->write(
                    $relativePath,
                    $contents,
                );
            } catch (Throwable $exception) {
                $errors[] = "Unable to restore backup: {$relativePath}";
            }
        }

        return $errors;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
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

            @rmdir($directory);
        } catch (\Throwable) {
            // Backup cleanup is best-effort and must never mask the
            // installation outcome.
        }
    }
}
