<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use RuntimeException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\CurseForgeCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\ModrinthCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\CurseForgeProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ManualDownloadProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\UnsupportedModpackPackageException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogService;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLock;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLockedException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallProgressStore;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallIntegrityVerifier;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecordStore;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\OwnershipRemover;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\ModpackProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\CurlProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerTargetSelector;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\WingsServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsHttpException;
use Throwable;

final class ModpackController extends Controller
{
    private const VOLUMES_ROOT = '/var/lib/pterodactyl/volumes';

    private const TEMPORARY_ROOT = '/tmp/modpack-installer';

    private const CANCEL_FLAG_DIR = '/tmp/modpack-installer/cancel';

    public function metadata(Request $request): JsonResponse
    {
        $sourceValue = $request->query('source');

        if (!is_string($sourceValue) || trim($sourceValue) === '') {
            return response()->json([
                'error' => 'The source parameter is required.',
            ], 422);
        }

        $source = trim($sourceValue);

        if (strlen($source) > 1024) {
            return response()->json([
                'error' => 'The source parameter is too long.',
            ], 422);
        }

        try {
            $provider = $this->providerRegistry()->resolve($source);
            $metadata = $provider->getMetadata($source);

            $manualDownload = null;

            if ($provider instanceof ManualDownloadProvider) {
                $manualDownload = $provider->manualDownloadInfoFor($source);
            }

            return response()->json([
                'data' => [
                    'id' => $metadata->id,
                    'name' => $metadata->name,
                    'version' => $metadata->version,
                    'minecraft_version' => $metadata->minecraftVersion,
                    'loader' => $metadata->loader,
                    'description' => $metadata->description,
                    'icon_url' => $metadata->iconUrl,
                    'source' => $metadata->source,
                    'manual_download' => $manualDownload,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            $query = $this->catalogQuery($request);

            $result = $this->catalogService()->search($query);

            return response()->json([
                'data' => $result->toArray(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (CatalogUnavailableException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 503);
        } catch (CatalogProviderException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the modpack catalog.',
            ], 500);
        }
    }

    public function catalogProviders(): JsonResponse
    {
        $service = $this->catalogService();

        return response()->json([
            'data' => [
                'providers' => $service->providers(),
                'default_provider' => $service->defaultProvider(),
                'pagination' => [
                    'default_page' => CatalogSearchQuery::DEFAULT_PAGE,
                    'default_limit' => CatalogSearchQuery::DEFAULT_LIMIT,
                ],
            ],
        ]);
    }

    public function catalogProject(Request $request): JsonResponse
    {
        try {
            $provider = $this->paramString(
                $request,
                'provider',
                CatalogProjectQuery::DEFAULT_PROVIDER,
                32,
                '/^[a-z0-9-]{1,32}$/',
            );

            $projectValue = $request->query('project');

            if (!is_string($projectValue) || trim($projectValue) === '') {
                throw new InvalidArgumentException(
                    'The project parameter is required.',
                );
            }

            $project = trim($projectValue);

            if (
                strlen($project) > 64
                || preg_match(
                    CatalogProjectQuery::PROJECT_PATTERN,
                    $project,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid project parameter.',
                );
            }

            $item = $this->catalogService()->project(new CatalogProjectQuery(
                provider: $provider,
                project: $project,
            ));

            return response()->json([
                'data' => $item->toArray(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (CatalogUnavailableException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 503);
        } catch (CatalogProviderException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the modpack project details.',
            ], 500);
        }
    }

    public function catalogVersions(Request $request): JsonResponse
    {
        try {
            $query = $this->catalogVersionQuery($request);

            $result = $this->catalogService()->versions($query);

            return response()->json([
                'data' => $result->toArray(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (CatalogUnavailableException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 503);
        } catch (CatalogProviderException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the modpack versions.',
            ], 500);
        }
    }

    public function install(
        Request $request,
        Server $server,
    ): JsonResponse {
        $provider = null;
        $package = null;
        $lock = null;
        $token = bin2hex(random_bytes(16));
        $progressToken = '';

        // A modpack install is a many-minute operation (a 600MB+ mod phase,
        // packaging and deployment), but PHP-FPM's default max_execution_time
        // is 30 seconds. Without lifting it, the engine dies mid-transfer with
        // a fatal "Maximum execution time exceeded" and the frontend is left
        // polling a frozen progress snapshot forever. Unblock this request's
        // timer; the install lock's own staleness timeout remains the safety
        // net that reclaims an actually-abandoned install.
        @set_time_limit(0);

        try {
            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $this->clearCancelFlag($this->lockKey($server));

            $source = $this->installationSource($request);

            $progressToken = $this->progressToken($request);

            $progress = $this->installProgressStore();

            $progress->set($progressToken, [
                'phase' => 'starting',
                'percent' => 1,
                'indeterminate' => false,
            ]);

            $downloader = $this->downloader();

            $downloader->setCancelChecker(
                fn (): bool => $this->wasCancelled(
                    $this->lockKey($server),
                ),
            );

            $lastLockTouch = 0.0;

            $downloader->setProgressCallback(
                static function (
                    ?int $downloadedBytes,
                    ?int $totalBytes,
                ) use (
                    $progress,
                    $progressToken,
                    $lock,
                    &$lastLockTouch,
                ): void {
                    $now = microtime(true);

                    if (($now - $lastLockTouch) >= 2.0) {
                        $lastLockTouch = $now;

                        // Keep the install lock alive so a long download is
                        // never reclaimed as stale while it is still running.
                        @touch($lock);
                    }

                    $percent = 40;

                    if (
                        $totalBytes !== null
                        && $totalBytes > 0
                        && $downloadedBytes !== null
                    ) {
                        $percent = (int) floor(
                            min(1.0, $downloadedBytes / $totalBytes) * 85,
                        );
                    }

                    // No monotonic clamp here on purpose: the CurseForge flow
                    // reports two consecutive windows (the small client-pack
                    // zip, then the much larger manifest-mods footprint). The
                    // second window re-anchors to 0 so the bar visibly starts
                    // filling again instead of freezing at the zip's 100%.

                    $progress->set($progressToken, [
                        'phase' => 'download',
                        'percent' => $percent,
                        'indeterminate' => $totalBytes === null || $totalBytes <= 0,
                        'downloaded_bytes' => $downloadedBytes,
                        'total_bytes' => $totalBytes,
                    ]);
                },
            );

            $provider = $this->providerRegistry($downloader)->resolve($source);

            $package = $provider->getPackage($source);

            $progress->set($progressToken, [
                'phase' => 'preparing',
                'percent' => 88,
                'indeterminate' => false,
            ]);

            $target = $this->serverTarget($server);

            $orchestrator = $this->orchestrator(
                $target,
                static function (
                    int $deployedFiles,
                    int $totalFiles,
                ) use (
                    $progress,
                    $progressToken,
                    $lock,
                    &$lastLockTouch,
                ): void {
                    $now = microtime(true);

                    if (($now - $lastLockTouch) >= 2.0) {
                        $lastLockTouch = $now;

                        @touch($lock);
                    }

                    $percent = 88 + (int) floor(
                        ($totalFiles > 0 ? $deployedFiles / $totalFiles : 0)
                            * 12,
                    );

                    $progress->set($progressToken, [
                        'phase' => 'deploy',
                        'percent' => $percent,
                        'indeterminate' => false,
                        'deployed_files' => $deployedFiles,
                        'total_files' => $totalFiles,
                    ]);
                },
                fn (): bool => $this->wasCancelled(
                    $this->lockKey($server),
                ),
            );

            $result = $orchestrator->install(
                archivePath: $package->archivePath,
            );

            $progress->set($progressToken, [
                'phase' => 'complete',
                'percent' => 100,
                'indeterminate' => false,
            ]);

            try {
                $metadata = $this->installMetadata(
                    $provider,
                    $package->source,
                );

                $record = $this->buildInstallRecord(
                    server: $server,
                    installedSource: $package->source,
                    metadata: $metadata,
                    result: $result,
                );

                $this->store()->save($record);
            } catch (Throwable $exception) {
                // The deployment itself succeeded, so a failure while recording
                // it (metadata lookup, record store) must not leave newly
                // deployed files stranded without a record. Best-effort remove
                // exactly the files this install created.
                $this->rollbackDeployedFiles($server, $result);

                throw $exception;
            }

            return response()->json([
                'data' => [
                    'total_files' => $result->totalFiles(),
                    'created' => $result->createdCount(),
                    'overwritten' => $result->overwrittenCount(),
                    'backed_up' => $result->backupCount(),
                    'record_id' => $record->id,
                ],
            ]);
        } catch (InstallationCancelledException $exception) {
            if ($progressToken !== '') {
                $this->installProgressStore()->set($progressToken, [
                    ...($this->installProgressStore()
                        ->get($progressToken) ?? []),
                    'phase' => 'cancelled',
                    'indeterminate' => false,
                    'message' => $exception->getMessage(),
                ]);
            }

            return response()->json([
                'error' => 'Installation cancelled.',
            ], 409);
        } catch (InstallationLockedException $exception) {
            return response()->json([
                'error' => 'Another installation is already running for this server. Please wait and try again.',
            ], 503);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (UnsupportedModpackPackageException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (WingsConnectionException $exception) {
            if ($progressToken !== '') {
                $this->installProgressStore()->set($progressToken, [
                    ...($this->installProgressStore()
                        ->get($progressToken) ?? []),
                    'phase' => 'failed',
                    'indeterminate' => false,
                    'message' => 'Unable to reach the server node. The installation failed.',
                ]);
            }

            return response()->json([
                'error' => 'Unable to reach the server node. Please try again later.',
            ], 503);
        } catch (WingsHttpException $exception) {
            if ($progressToken !== '') {
                $this->installProgressStore()->set($progressToken, [
                    ...($this->installProgressStore()
                        ->get($progressToken) ?? []),
                    'phase' => 'failed',
                    'indeterminate' => false,
                    'message' => 'The server node could not complete the operation. The installation failed.',
                ]);
            }

            return response()->json([
                'error' => 'The server node could not complete the operation. Please try again later.',
            ], 503);
        } catch (Throwable $exception) {
            report($exception);

            if ($progressToken !== '') {
                $lastState = $this->installProgressStore()
                    ->get($progressToken);

                $this->installProgressStore()->set($progressToken, [
                    ...($lastState ?? []),
                    'phase' => 'failed',
                    'indeterminate' => false,
                    'message' => 'The installation failed.',
                ]);
            }

            return response()->json([
                'error' => 'Unable to install the modpack.',
            ], 500);
        } finally {
            if ($lock !== null) {
                try {
                    $this->installationLock()->release($lock, $token);
                } catch (Throwable) {
                    // Releasing the lock must never mask the installation
                    // outcome; stale-lock handling covers leftover locks.
                }
            }

            if ($provider !== null && $package !== null) {
                $provider->cleanup($package);
            }

            $this->clearCancelFlag($this->lockKey($server));
        }
    }

    public function installProgress(
        Request $request,
    ): JsonResponse {
        try {
            $state = $this->installProgressStore()->get(
                $this->progressToken($request),
            );

            return response()->json([
                'data' => $state ?? [
                    'phase' => 'idle',
                    'percent' => 0,
                    'indeterminate' => false,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the installation progress.',
            ], 500);
        }
    }

    public function cancelInstall(
        Request $request,
        Server $server,
    ): JsonResponse {
        try {
            $serverId = $this->lockKey($server);

            $this->requestCancel($serverId);

            $progressToken = $this->optionalProgressToken($request);

            if ($progressToken !== null) {
                $progress = $this->installProgressStore();

                $progress->set($progressToken, [
                    ...($progress->get($progressToken) ?? []),
                    'phase' => 'cancelling',
                    'indeterminate' => false,
                ]);
            }

            return response()->json([
                'data' => [
                    'cancelled' => true,
                    'applied' => true,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to cancel the installation.',
            ], 500);
        }
    }

    public function installedModpacks(
        Request $request,
        Server $server,
    ): JsonResponse {
        try {
            $records = $this->store()->all((string) $server->uuid);

            $target = $this->serverTarget($server);

            $verifier = new InstallIntegrityVerifier();

            $data = [];

            foreach ($records as $record) {
                if ($verifier->isCompletelyGone($record, $target)) {
                    $this->store()->delete(
                        (string) $server->uuid,
                        $record->id,
                    );

                    continue;
                }

                $item = $record->toArray();

                $missing = $verifier->missingFiles($record, $target);

                $item['integrity'] = [
                    'status' => $missing === []
                        ? 'ok'
                        : 'degraded',
                    'missing' => $missing,
                    'missing_count' => count($missing),
                ];

                $data[] = $item;
            }

            return response()->json([
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the installed modpacks.',
            ], 500);
        }
    }

    public function installedModpack(
        Request $request,
        Server $server,
        string $id,
    ): JsonResponse {
        try {
            $record = $this->store()->find(
                (string) $server->uuid,
                $this->validateRecordId($id),
            );

            if ($record === null) {
                return response()->json([
                    'error' => 'The installed modpack was not found.',
                ], 404);
            }

            return response()->json([
                'data' => $record->toArray(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the installed modpack.',
            ], 500);
        }
    }

    public function uninstall(
        Request $request,
        Server $server,
        string $id,
    ): JsonResponse {
        $lock = null;
        $token = bin2hex(random_bytes(16));

        try {
            $id = $this->validateRecordId($id);

            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $record = $this->store()->find(
                (string) $server->uuid,
                $id,
            );

            if ($record === null) {
                return response()->json([
                    'error' => 'The installed modpack was not found.',
                ], 404);
            }

            if ($record->status !== InstallRecord::STATUS_INSTALLED) {
                return response()->json([
                    'error' => 'The installed modpack is not in an active state.',
                ], 409);
            }

            $target = $this->serverTarget($server);

            $outcome = (new OwnershipRemover($target))->remove(
                $record->ownedFiles(),
            );

            if ($outcome['errors'] !== []) {
                report(new RuntimeException(
                    'Modpack uninstall failed to remove owned files: '
                        . implode('; ', $outcome['errors']),
                ));

                return response()->json([
                    'error' => 'Unable to fully uninstall the modpack. No files were partially removed from the record.',
                ], 500);
            }

            $this->store()->delete((string) $server->uuid, $id);

            return response()->json([
                'data' => [
                    'id' => $record->id,
                    'display_name' => $record->displayName,
                    'version' => $record->version,
                    'removed' => count($outcome['deleted']),
                    'missing' => count($outcome['missing']),
                ],
            ]);
        } catch (InstallationLockedException $exception) {
            return response()->json([
                'error' => 'Another installation operation is already running for this server. Please wait and try again.',
            ], 503);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (WingsConnectionException $exception) {
            return response()->json([
                'error' => 'Unable to reach the server node. Please try again later.',
            ], 503);
        } catch (WingsHttpException $exception) {
            return response()->json([
                'error' => 'The server node could not complete the operation. Please try again later.',
            ], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to uninstall the modpack.',
            ], 500);
        } finally {
            if ($lock !== null) {
                try {
                    $this->installationLock()->release($lock, $token);
                } catch (Throwable) {
                    // Releasing the lock must never mask the outcome.
                }
            }
        }
    }

    public function updateModpack(
        Request $request,
        Server $server,
        string $id,
    ): JsonResponse {
        $lock = null;
        $provider = null;
        $package = null;
        $token = bin2hex(random_bytes(16));

        try {
            $id = $this->validateRecordId($id);

            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $record = $this->store()->find(
                (string) $server->uuid,
                $id,
            );

            if ($record === null) {
                return response()->json([
                    'error' => 'The installed modpack was not found.',
                ], 404);
            }

            if ($record->status !== InstallRecord::STATUS_INSTALLED) {
                return response()->json([
                    'error' => 'The installed modpack is not in an active state.',
                ], 409);
            }

            $provider = $this->providerRegistry()->resolve($record->source);

            $latestBase = $this->latestBaseSource($record->source);

            $package = $provider->getPackage($latestBase);

            $metadata = $this->installMetadata(
                $provider,
                $package->source,
            );

            if ($metadata !== null && $metadata->version !== ''
                && $metadata->version === $record->version
            ) {
                return response()->json([
                    'error' => 'The modpack is already up to date.',
                ], 409);
            }

            $target = $this->serverTarget($server);

            $orchestrator = $this->orchestrator($target);

            $result = $orchestrator->install(
                archivePath: $package->archivePath,
            );

            $updated = $this->buildInstallRecord(
                server: $server,
                installedSource: $package->source,
                metadata: $metadata,
                result: $result,
                existingRecord: $record,
            );

            $this->store()->save($updated);

            return response()->json([
                'data' => [
                    'id' => $updated->id,
                    'display_name' => $updated->displayName,
                    'previous_version' => $record->version,
                    'version' => $updated->version,
                    'total_files' => $result->totalFiles(),
                    'created' => $result->createdCount(),
                    'overwritten' => $result->overwrittenCount(),
                    'backed_up' => $result->backupCount(),
                ],
            ]);
        } catch (InstallationLockedException $exception) {
            return response()->json([
                'error' => 'Another installation operation is already running for this server. Please wait and try again.',
            ], 503);
        } catch (InvalidArgumentException $exception) {
            if (
                $provider instanceof ManualDownloadProvider
                && $record !== null
            ) {
                try {
                    $manualDownload = $provider->manualDownloadInfoFor(
                        $this->latestBaseSource($record->source),
                    );
                } catch (Throwable) {
                    $manualDownload = null;
                }

                if ($manualDownload !== null) {
                    return response()->json([
                        'error' => $exception->getMessage(),
                        'manual_download' => $manualDownload,
                    ], 422);
                }
            }

            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (WingsConnectionException $exception) {
            return response()->json([
                'error' => 'Unable to reach the server node. Please try again later.',
            ], 503);
        } catch (WingsHttpException $exception) {
            return response()->json([
                'error' => 'The server node could not complete the operation. Please try again later.',
            ], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to update the modpack.',
            ], 500);
        } finally {
            if ($lock !== null) {
                try {
                    $this->installationLock()->release($lock, $token);
                } catch (Throwable) {
                    // Releasing the lock must never mask the outcome.
                }
            }

            if ($provider !== null && $package !== null) {
                $provider->cleanup($package);
            }
        }
    }

    public function restoreModpack(
        Request $request,
        Server $server,
        string $id,
    ): JsonResponse {
        $provider = null;
        $package = null;
        $lock = null;
        $record = null;
        $token = bin2hex(random_bytes(16));

        try {
            $id = $this->validateRecordId($id);

            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $record = $this->store()->find(
                (string) $server->uuid,
                $id,
            );

            if ($record === null) {
                return response()->json([
                    'error' => 'The installed modpack was not found.',
                ], 404);
            }

            if ($record->status !== InstallRecord::STATUS_INSTALLED) {
                return response()->json([
                    'error' => 'The installed modpack is not in an active state.',
                ], 409);
            }

            $target = $this->serverTarget($server);

            $missing = (new InstallIntegrityVerifier())->missingFiles(
                $record,
                $target,
            );

            if ($missing === []) {
                return response()->json([
                    'error' => 'The modpack files are intact. Nothing to restore.',
                ], 409);
            }

            $provider = $this->providerRegistry()->resolve($record->source);

            $package = $provider->getPackage($record->source);

            $result = $this->orchestrator($target)->restore(
                archivePath: $package->archivePath,
                paths: $missing,
            );

            return response()->json([
                'data' => [
                    'id' => $record->id,
                    'display_name' => $record->displayName,
                    'version' => $record->version,
                    'restored' => $result->createdCount(),
                    'requested' => count($missing),
                ],
            ]);
        } catch (InstallationLockedException $exception) {
            return response()->json([
                'error' => 'Another installation operation is already running for this server. Please wait and try again.',
            ], 503);
        } catch (InvalidArgumentException $exception) {
            if (
                $provider instanceof ManualDownloadProvider
                && $record !== null
            ) {
                try {
                    $manualDownload = $provider->manualDownloadInfoFor(
                        $record->source,
                    );
                } catch (Throwable) {
                    $manualDownload = null;
                }

                if ($manualDownload !== null) {
                    return response()->json([
                        'error' => $exception->getMessage(),
                        'manual_download' => $manualDownload,
                    ], 422);
                }
            }

            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (UnsupportedModpackPackageException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (WingsConnectionException $exception) {
            return response()->json([
                'error' => 'Unable to reach the server node. Please try again later.',
            ], 503);
        } catch (WingsHttpException $exception) {
            return response()->json([
                'error' => 'The server node could not complete the operation. Please try again later.',
            ], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to restore the missing modpack files.',
            ], 500);
        } finally {
            if ($lock !== null) {
                try {
                    $this->installationLock()->release($lock, $token);
                } catch (Throwable) {
                    // Releasing the lock must never mask the outcome.
                }
            }

            if ($provider !== null && $package !== null) {
                $provider->cleanup($package);
            }
        }
    }

    private function installationSource(
        Request $request,
    ): string {
        $sourceValue = $request->input('source');

        if (!is_string($sourceValue) || trim($sourceValue) === '') {
            throw new InvalidArgumentException(
                'The source parameter is required.',
            );
        }

        $source = trim($sourceValue);

        if (strlen($source) > 1024) {
            throw new InvalidArgumentException(
                'The source parameter is too long.',
            );
        }

        return $source;
    }

    private function catalogQuery(
        Request $request,
    ): CatalogSearchQuery {
        $provider = $this->paramString(
            $request,
            'provider',
            CatalogSearchQuery::DEFAULT_PROVIDER,
            32,
            '/^[a-z0-9-]{1,32}$/',
        );

        $query = $this->paramString(
            $request,
            'query',
            null,
            CatalogSearchQuery::MAX_QUERY_LENGTH,
        );

        $gameVersions = $this->paramList(
            $request,
            'game_versions',
            CatalogSearchQuery::VERSION_PATTERN,
        );

        $loaders = $this->paramList(
            $request,
            'loaders',
            CatalogSearchQuery::SLUG_PATTERN,
        );

        $categories = $this->paramList(
            $request,
            'categories',
            CatalogSearchQuery::SLUG_PATTERN,
        );

        $environments = $this->paramList(
            $request,
            'environments',
            CatalogSearchQuery::SLUG_PATTERN,
        );

        $sortValue = $this->paramString(
            $request,
            'sort',
            CatalogSort::RELEVANCE->value,
            16,
        );

        $sort = CatalogSort::tryFrom((string) $sortValue);

        if ($sort === null) {
            throw new InvalidArgumentException(
                'Invalid catalog sort.',
            );
        }

        $page = $this->paramInt(
            $request,
            'page',
            CatalogSearchQuery::DEFAULT_PAGE,
            CatalogSearchQuery::DEFAULT_PAGE,
            CatalogSearchQuery::MAX_PAGE,
        );

        $limit = $this->paramInt(
            $request,
            'limit',
            CatalogSearchQuery::DEFAULT_LIMIT,
            CatalogSearchQuery::MIN_LIMIT,
            CatalogSearchQuery::MAX_LIMIT,
        );

        return new CatalogSearchQuery(
            provider: $provider,
            query: $query,
            gameVersion: $gameVersions,
            loader: $loaders,
            category: $categories,
            environments: $environments,
            sort: $sort,
            page: $page,
            limit: $limit,
        );
    }

    private function catalogVersionQuery(
        Request $request,
    ): CatalogVersionQuery {
        $provider = $this->paramString(
            $request,
            'provider',
            CatalogVersionQuery::DEFAULT_PROVIDER,
            32,
            '/^[a-z0-9-]{1,32}$/',
        );

        $projectValue = $request->query('project');

        if (!is_string($projectValue) || trim($projectValue) === '') {
            throw new InvalidArgumentException(
                'The project parameter is required.',
            );
        }

        $project = trim($projectValue);

        if (
            strlen($project) > 64
            || preg_match(
                CatalogVersionQuery::PROJECT_PATTERN,
                $project,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid project parameter.',
            );
        }

        $gameVersions = $this->paramList(
            $request,
            'game_versions',
            CatalogVersionQuery::VERSION_PATTERN,
        );

        $loaders = $this->paramList(
            $request,
            'loaders',
            CatalogVersionQuery::SLUG_PATTERN,
        );

        return new CatalogVersionQuery(
            provider: $provider,
            project: $project,
            gameVersion: $gameVersions,
            loader: $loaders,
        );
    }

    private function paramString(
        Request $request,
        string $key,
        ?string $default,
        int $maxLength,
        ?string $pattern = null,
    ): ?string {
        $value = $request->query($key);

        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                $this->invalidParameterMessage($key),
            );
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                'The ' . $this->parameterLabel($key)
                    . ' parameter is too long.',
            );
        }

        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(
                $this->invalidParameterMessage($key),
            );
        }

        return $value;
    }

    private function paramInt(
        Request $request,
        string $key,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $request->query($key);

        if ($value === null) {
            return $default;
        }

        if (!is_string($value) || !is_numeric($value)) {
            throw new InvalidArgumentException(
                $this->invalidParameterMessage($key),
            );
        }

        $integer = (int) $value;

        if ((string) $integer !== trim($value)) {
            throw new InvalidArgumentException(
                $this->invalidParameterMessage($key),
            );
        }

        if ($integer < $minimum || $integer > $maximum) {
            if ($key === 'page') {
                throw new InvalidArgumentException(
                    'The requested page is out of range.',
                );
            }

            throw new InvalidArgumentException(
                'Invalid ' . $this->parameterLabel($key)
                    . ' parameter.',
            );
        }

        return $integer;
    }

    private function invalidParameterMessage(string $key): string
    {
        return 'Invalid ' . $this->parameterLabel($key) . ' parameter.';
    }

    /**
     * Parses a comma-separated multi-value query parameter. Repeats the
     * CatalogSearchQuery/Controller validation rules per entry: each value is
     * trimmed, lowercased for slug groups, pattern-validated, bounded, and
     * deduplicated.
     *
     * @return array<string>
     */
    private function paramList(
        Request $request,
        string $key,
        string $pattern,
        int $maxValues = CatalogSearchQuery::MAX_FILTER_VALUES,
    ): array {
        $value = $request->query($key);

        if ($value === null) {
            return [];
        }

        if (!is_string($value) || trim($value) === '') {
            if ($value === '') {
                return [];
            }

            throw new InvalidArgumentException(
                $this->invalidParameterMessage($key),
            );
        }

        $items = explode(',', $value);

        if (count($items) > $maxValues) {
            throw new InvalidArgumentException(
                'Too many ' . $this->parameterLabel($key)
                    . ' values were provided.',
            );
        }

        $normalized = [];

        foreach ($items as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (strlen($item) > 32 || preg_match($pattern, $item) !== 1) {
                throw new InvalidArgumentException(
                    $this->invalidParameterMessage($key),
                );
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }

    private function parameterLabel(string $key): string
    {
        return str_replace('_', ' ', $key);
    }

    private function catalogService(): CatalogService
    {
        return new CatalogService(
            new CatalogProviderRegistry([
                new ModrinthCatalogProvider(
                    $this->providerHttp(),
                ),
                new CurseForgeCatalogProvider(
                    $this->providerHttp(),
                    $this->curseForgeApiKey(),
                ),
            ]),
        );
    }

    private function installationLock(): InstallationLock
    {
        return new InstallationLock(
            temporaryRoot: self::TEMPORARY_ROOT,
        );
    }

    private function lockKey(
        Server $server,
    ): string {
        $uuid = (string) $server->uuid;

        if ($uuid === '') {
            return 'server-' . md5((string) $server->id);
        }

        return $uuid;
    }

    private function providerRegistry(
        ?DownloadManager $downloader = null,
    ): ModpackProviderRegistry {
        $driver = $downloader ?? $this->downloader();

        $http = $this->providerHttp();

        return new ModpackProviderRegistry([
            new MockModpackProvider(),
            new ModrinthProvider(
                http: $http,
                downloader: $driver,
                temporaryRoot: self::TEMPORARY_ROOT,
            ),
            new CurseForgeProvider(
                http: $http,
                downloader: $driver,
                apiKey: $this->curseForgeApiKey(),
                temporaryRoot: self::TEMPORARY_ROOT,
            ),
        ]);
    }

    private function downloader(): DownloadManager
    {
        $maxMb = config('modpackinstaller.max_download_mb');

        if (is_numeric($maxMb) && (int) $maxMb > 0) {
            return new DownloadManager(
                self::TEMPORARY_ROOT,
                (int) $maxMb * 1024 * 1024,
            );
        }

        return new DownloadManager(self::TEMPORARY_ROOT);
    }

    private function installProgressStore(): InstallProgressStore
    {
        return new InstallProgressStore(
            self::TEMPORARY_ROOT . '/progress',
        );
    }

    private function progressToken(Request $request): string
    {
        $token = $this->optionalProgressToken($request);

        if ($token === null) {
            throw new InvalidArgumentException(
                'A progress token is required.',
            );
        }

        return $token;
    }

    private function optionalProgressToken(
        Request $request,
    ): ?string {
        $value = $request->query('progress_token');

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $token = trim($value);

        if (
            strlen($token) < 8
            || strlen($token) > 64
            || preg_match('/^[a-zA-Z0-9-]+$/', $token) !== 1
        ) {
            throw new InvalidArgumentException(
                'The progress token is invalid.',
            );
        }

        return $token;
    }

    private function requestCancel(string $serverId): void
    {
        if (!is_dir(self::CANCEL_FLAG_DIR)) {
            if (
                !@mkdir(self::CANCEL_FLAG_DIR, 0750, true)
                && !is_dir(self::CANCEL_FLAG_DIR)
            ) {
                throw new RuntimeException(
                    'Unable to create the cancellation flag directory.'
                );
            }
        }

        $path = self::CANCEL_FLAG_DIR . '/' . $serverId;

        if (@file_put_contents($path, (string) time()) === false) {
            throw new RuntimeException(
                'Unable to write the cancellation flag.'
            );
        }
    }

    private function clearCancelFlag(string $serverId): void
    {
        @unlink(self::CANCEL_FLAG_DIR . '/' . $serverId);
    }

    private function wasCancelled(string $serverId): bool
    {
        return is_file(
            self::CANCEL_FLAG_DIR . '/' . $serverId,
        );
    }

    private function providerHttp(): ProviderHttpClient
    {
        return new CurlProviderHttpClient();
    }

    private function curseForgeApiKey(): ?string
    {
        $key = config('modpackinstaller.curseforge_api_key');

        if (!is_string($key) || $key === '') {
            return null;
        }

        return $key;
    }

    private function serverTarget(
        Server $server,
    ): ServerFileTarget {
        $selector = new ServerTargetSelector(
            mode: $this->targetMode(),
            localFactory: new ServerFileTargetFactory(
                $this->localServerRoot(),
            ),
            wingsFactory: $this->wingsServerTargetFactory(),
        );

        return $selector->forServer($server);
    }

    /**
     * Removes the files a just-completed deployment created when the install
     * could not be recorded. Mirrors the orchestrator rollback for the new-file
     * case; previously existing files are left untouched.
     */
    private function rollbackDeployedFiles(
        Server $server,
        InstallationResult $result,
    ): void {
        try {
            $target = $this->serverTarget($server);
        } catch (Throwable) {
            return;
        }

        foreach ($result->created as $relativePath) {
            try {
                if (
                    $target->exists($relativePath)
                    && !$target->isDirectory($relativePath)
                ) {
                    $target->delete($relativePath);
                }
            } catch (Throwable) {
                // Best-effort cleanup must never mask the original failure.
            }
        }
    }

    private function targetMode(): string
    {
        $mode = config('modpackinstaller.server_target', 'local');

        if (!is_string($mode) || trim($mode) === '') {
            return 'local';
        }

        return trim($mode);
    }

    private function localServerRoot(): string
    {
        $root = config('modpackinstaller.server_root');

        if (is_string($root) && $root !== '') {
            return $root;
        }

        return self::VOLUMES_ROOT;
    }

    private function wingsServerTargetFactory(): WingsServerFileTargetFactory
    {
        return new WingsServerFileTargetFactory(
            timeout: 600,
            connectTimeout: 10,
            verifySsl: app()->environment('production'),
        );
    }

    private function orchestrator(
        ServerFileTarget $target,
        ?callable $progress = null,
        ?callable $cancelChecker = null,
    ): InstallationOrchestrator {
        $executor = new DeploymentExecutor($target);

        if ($progress !== null) {
            $executor->setProgressCallback($progress);
        }

        $orchestrator = new InstallationOrchestrator(
            workspaceManager: new InstallationWorkspace(
                self::TEMPORARY_ROOT,
            ),
            planner: new DeploymentPlanner($target),
            backupManager: new BackupManager($target),
            executor: $executor,
            temporaryRoot: self::TEMPORARY_ROOT,
            serverFileTarget: $target,
        );

        if ($cancelChecker !== null) {
            $orchestrator->setCancelChecker($cancelChecker);
        }

        return $orchestrator;
    }

    private function store(): InstallRecordStore
    {
        return new InstallRecordStore(
            $this->installDataDir(),
        );
    }

    private function installDataDir(): string
    {
        $directory = config('modpackinstaller.data_dir');

        if (is_string($directory) && $directory !== '') {
            return $directory;
        }

        return '/var/lib/pterodactyl/modpack-installer';
    }

    private function buildInstallRecord(
        Server $server,
        string $installedSource,
        ?ModpackMetadata $metadata,
        InstallationResult $result,
        ?InstallRecord $existingRecord = null,
    ): InstallRecord {
        [$provider, $projectId, $versionId] = $this->sourceParts(
            $installedSource,
        );

        if ($metadata !== null && $metadata->version !== '') {
            $version = $metadata->version;
        } else {
            $version = $versionId
                ?? $existingRecord?->version
                ?? ($metadata?->version ?? '');
        }

        return new InstallRecord(
            id: $existingRecord?->id ?? $this->newRecordId(),
            serverUuid: (string) $server->uuid,
            provider: $provider,
            projectId: $projectId,
            versionId: $versionId,
            source: $installedSource,
            displayName: ($metadata !== null && $metadata->name !== '')
                ? $metadata->name
                : ($existingRecord?->displayName ?? $projectId),
            version: $version,
            minecraftVersion: $metadata?->minecraftVersion,
            loader: $metadata?->loader,
            iconUrl: $metadata?->iconUrl
                ?? $existingRecord?->iconUrl,
            installedAt: $existingRecord?->installedAt ?? gmdate('c'),
            updatedAt: gmdate('c'),
            status: InstallRecord::STATUS_INSTALLED,
            createdFiles: $result->created,
            overwrittenFiles: $result->overwritten,
        );
    }

    private function installMetadata(
        ModpackProvider $provider,
        string $source,
    ): ?ModpackMetadata {
        try {
            return $provider->getMetadata($source);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the unpinned base of an installed source so that an update can
     * resolve the latest available version: the scheme and project id remain,
     * any pinned version is dropped.
     */
    private function latestBaseSource(string $source): string
    {
        return explode('@', $source, 2)[0];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function sourceParts(string $source): array
    {
        $rest = preg_replace('/^([a-z0-9-]+):\/\//', '', $source)
            ?? $source;

        $pieces = explode('@', $rest, 2);

        $projectId = $pieces[0] ?? '';
        $versionId = isset($pieces[1]) && $pieces[1] !== ''
            ? $pieces[1]
            : null;

        if (preg_match('/^([a-z0-9-]+):\/\//', $source, $matches) === 1) {
            $provider = $matches[1];
        } else {
            $provider = '';
        }

        return [$provider, $projectId, $versionId];
    }

    private function validateRecordId(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new InvalidArgumentException(
                'The installed modpack id is invalid.',
            );
        }

        return $id;
    }

    private function newRecordId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
