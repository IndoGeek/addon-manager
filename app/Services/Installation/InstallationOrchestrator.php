<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;
use Throwable;

final class InstallationOrchestrator
{
    // @var null|callable(): bool
    private $cancelChecker = null;

    public function __construct(
        private readonly InstallationWorkspace $workspaceManager,
        private readonly DeploymentPlanner $planner,
        private readonly BackupManager $backupManager,
        private readonly DeploymentExecutor $executor,
        private readonly string $temporaryRoot,
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    // Registers a predicate consulted at checkpoints through the installation flow.
    public function setCancelChecker(?callable $checker): void
    {
        $this->cancelChecker = $checker;

        $this->executor->setCancelChecker($checker);
    }

    public function install(
        string $archivePath,
    ): InstallationResult {
        $workspace = null;
        $backupDirectory = null;
        $backups = [];
        $created = [];

        try {
            $this->ensureNotCancelled();

            $workspace = $this->workspaceManager->prepare(
                $archivePath,
                $this->cancelChecker,
            );

            $this->ensureNotCancelled();

            $plan = $this->planner->plan($workspace);

            $backupDirectory = $this->createBackupDirectory();

            foreach ($plan->operations as $operation) {
                if (!$operation->overwrite) {
                    continue;
                }

                $this->ensureNotCancelled();

                $backups[$operation->relativePath] = $this->backupManager->backup(
                    $operation->relativePath,
                    $backupDirectory,
                );
            }

            $this->ensureNotCancelled();

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

    // Re-deploys only the given relative paths from an archive.
    public function restore(
        string $archivePath,
        array $paths,
    ): InstallationResult {
        $workspace = null;
        $deployed = [];

        try {
            $this->ensureNotCancelled();

            $workspace = $this->workspaceManager->prepare(
                $archivePath,
                $this->cancelChecker,
            );

            $this->ensureNotCancelled();

            $plan = $this->planner->plan($workspace);

            $wanted = array_fill_keys(
                array_values(array_unique($paths)),
                true,
            );

            $operations = array_values(array_filter(
                $plan->operations,
                static fn (DeploymentOperation $operation): bool =>
                    isset($wanted[$operation->relativePath]),
            ));

            if ($operations === []) {
                return new InstallationResult(
                    created: [],
                    overwritten: [],
                    backedUp: [],
                );
            }

            $this->ensureNotCancelled();

            $deployed = $this->executor->execute(
                new DeploymentPlan($operations),
            );

            return new InstallationResult(
                created: $deployed,
                overwritten: [],
                backedUp: [],
            );
        } catch (Throwable $exception) {
            if ($exception instanceof DeploymentException) {
                $deployed = array_merge(
                    $deployed,
                    $exception->deployed(),
                );
            }

            $rollbackErrors = $this->rollback([], $deployed);

            if ($rollbackErrors !== []) {
                throw new RuntimeException(
                    'Restore failed and rollback was incomplete: '
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
        }
    }

    private function ensureNotCancelled(): void
    {
        $checker = $this->cancelChecker;

        if ($checker !== null && $checker()) {
            throw new InstallationCancelledException(
                'Installation cancelled.'
            );
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
                if (!is_file($backupPath)) {
                    throw new RuntimeException(
                        "Unable to read backup: {$relativePath}"
                    );
                }

                $parent = dirname($relativePath);

                if ($parent !== '.') {
                    $this->serverFileTarget->ensureDirectory($parent);
                }

                $this->serverFileTarget->putFile(
                    $relativePath,
                    $backupPath,
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
            // Backup cleanup is best-effort and must never mask the installation outcome.
        }
    }
}
