<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
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
    ) {
    }

    public function preview(
        string $archivePath,
        string $serverDirectory,
        DeploymentPolicy $policy = DeploymentPolicy::OVERWRITE,
    ): InstallationPreview {
        $workspace = null;

        try {
            $workspace = $this->workspaceManager->prepare($archivePath);

            $plan = $this->planner->plan(
                $workspace,
                $serverDirectory,
                $policy,
            );

            return new InstallationPreview($plan);
        } finally {
            if ($workspace !== null) {
                $this->workspaceManager->cleanup($workspace);
            }
        }
    }

    public function install(
        string $archivePath,
        string $serverDirectory,
        DeploymentPolicy $policy = DeploymentPolicy::OVERWRITE,
    ): InstallationResult {
        $workspace = null;
        $backupDirectory = null;
        $backups = [];
        $created = [];

        try {
            $workspace = $this->workspaceManager->prepare($archivePath);

            $plan = $this->planner->plan(
                $workspace,
                $serverDirectory,
                $policy,
            );

            $backupDirectory = $this->createBackupDirectory();

            foreach ($plan->overwrite as $relativePath) {
                $backups[$relativePath] = $this->backupManager->backup(
                    $serverDirectory,
                    $relativePath,
                    $backupDirectory,
                );
            }

            $created = $this->executor->execute(
                $workspace,
                $serverDirectory,
                $plan,
            );

            return new InstallationResult(
                created: $plan->create,
                overwritten: $plan->overwrite,
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
                $serverDirectory,
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
        string $serverDirectory,
        array $backups,
        array $created,
    ): array {
        $errors = [];

        foreach (array_reverse($created) as $relativePath) {
            $target = rtrim(
                realpath($serverDirectory),
                DIRECTORY_SEPARATOR,
            ) . '/' . $relativePath;

            if (is_file($target) && !unlink($target)) {
                $errors[] = "Unable to remove created file: {$relativePath}";
            }
        }

        foreach ($backups as $relativePath => $backupPath) {
            $serverRoot = realpath($serverDirectory);

            if ($serverRoot === false) {
                $errors[] = 'Unable to resolve the server directory during rollback.';
                break;
            }

            $target = rtrim(
                $serverRoot,
                DIRECTORY_SEPARATOR,
            ) . '/' . $relativePath;

            $parent = dirname($target);

            if (
                !is_dir($parent)
                && !mkdir($parent, 0750, true)
                && !is_dir($parent)
            ) {
                $errors[] = "Unable to recreate target directory: {$relativePath}";
                continue;
            }

            if (!copy($backupPath, $target)) {
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
