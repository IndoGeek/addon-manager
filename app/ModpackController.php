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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\PartialPackageProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\UnsupportedModpackPackageException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Models\ModpackMetadata;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogCache;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogService;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionFileResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CurseForgeModVersionCatalog;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\ModVersionCatalog;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\ContentInstallTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLock;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLockedException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallProgressStore;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\SimpleContentInstaller;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallIntegrityVerifier;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallHistoryStore;
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

    public function catalogProviders(Request $request): JsonResponse
    {
        // The active tab decides which category/loader lists apply, so the
        // facet payload is built for the requested content type.
        $contentType = $this->paramString(
            $request,
            'content_type',
            CatalogSearchQuery::DEFAULT_CONTENT_TYPE,
            32,
            '/^[a-z]{1,32}$/',
        );

        $service = $this->catalogService();

        if (
            $contentType !== null
            && in_array(
                $contentType,
                CatalogSearchQuery::CONTENT_TYPE_VALUES,
                true,
            )
        ) {
            $service = $service->withFacetsContentType($contentType);
        }

        // Admins can hide CurseForge entirely from the extension settings
        // page; the UI then only ever sees Modrinth.
        $disableCurseforge = $this->setting('disable_curseforge') === '1';

        $providers = array_values(array_filter(
            $service->providers(),
            static fn (array $provider): bool => !(
                $disableCurseforge
                && ($provider['name'] ?? null) === 'curseforge'
            ),
        ));

        $defaultProvider = $this->setting('default_provider');

        if (
            $defaultProvider === null
            || $defaultProvider === 'curseforge' && $disableCurseforge
        ) {
            $defaultProvider = $service->defaultProvider();
        }

        return response()->json([
            'data' => [
                'providers' => $providers,
                'default_provider' => $defaultProvider,
                'default_sort' => $this->setting('default_sort')
                    ?? 'relevance',
                'pagination' => [
                    'default_page' => CatalogSearchQuery::DEFAULT_PAGE,
                    'default_limit' => (int) (
                        $this->setting('page_size')
                        ?? CatalogSearchQuery::DEFAULT_LIMIT
                    ),
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

    // Version catalog for single-file content: per-version loader and
    // Minecraft-version options plus recommended dependencies, so the mod
    // version window can offer two dropdowns and a non-blocking hint.
    public function catalogModVersions(Request $request): JsonResponse
    {
        try {
            $projectValue = $request->query('project');

            if (!is_string($projectValue) || trim($projectValue) === '') {
                throw new InvalidArgumentException(
                    'The project parameter is required.',
                );
            }

            $project = trim($projectValue);

            $provider = $this->paramString(
                $request,
                'provider',
                'modrinth',
                32,
                '/^[a-z0-9-]{1,32}$/',
            ) ?? 'modrinth';

            // Both providers normalize into the same payload, so the version
            // window, the install path and the uninstall path are shared.
            $catalog = match ($provider) {
                'modrinth' => new ModVersionCatalog(
                    $this->providerHttp(),
                ),
                'curseforge' => new CurseForgeModVersionCatalog(
                    $this->providerHttp(),
                    $this->curseForgeApiKey(),
                ),
                default => throw new InvalidArgumentException(
                    'This provider has no content versions to install.',
                ),
            };

            return response()->json([
                'data' => $catalog->versions($project),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        } catch (CatalogProviderException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to load the mod versions.',
            ], 500);
        }
    }

    public function catalogDescription(Request $request): JsonResponse
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

            $description = $this->catalogService()->description(new CatalogProjectQuery(
                provider: $provider,
                project: $project,
            ));

            return response()->json([
                'data' => $description->toArray(),
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
                'error' => 'Unable to load the modpack description.',
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

            if ($this->curseForgeBlocked($source)) {
                throw new InvalidArgumentException(
                    'CurseForge has been disabled by the panel administrator.',
                );
            }

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

            // Ordered steps of a modpack install. Providers announce the ones
            // they walk (archive, extract, manifest, mods); prepare and deploy
            // are the controller's own. The downloading card renders this list,
            // so an install reads as labelled stages with a bar each instead of
            // one anonymous percentage that resets whenever the progress window
            // re-anchors between phases.
            $stages = [];

            $stageIndex = -1;

            $stageLabels = [
                'archive' => 'Downloading pack archive',
                'manifest' => 'Reading manifest.json',
                'extract' => 'Extracting pack files',
                'index' => 'Reading modpack index',
                'mods' => 'Downloading mod files',
                'prepare' => 'Preparing server files',
                'deploy' => 'Deploying files',
            ];

            // Enters a step, closing whichever one was in flight. A step that
            // already has a row is left alone: providers announce one again
            // when they re-enter it later in the same install (the archive is
            // opened, then streamed out), and a counted update arrives on every
            // tick, so neither may append a second row or reopen a closed step.
            $enterStage = static function (string $key) use (
                &$stages,
                &$stageIndex,
                $stageLabels,
            ): void {
                foreach ($stages as $index => $stage) {
                    if ($stage['key'] === $key) {
                        return;
                    }

                    $stages[$index]['state'] = 'done';
                }

                $stages[] = [
                    'key' => $key,
                    'label' => $stageLabels[$key] ?? ucfirst($key),
                    'state' => 'active',
                    'percent' => null,
                    'downloaded_bytes' => null,
                    'total_bytes' => null,
                    'current' => null,
                    'total' => null,
                ];

                $stageIndex = count($stages) - 1;
            };

            // Single writer for every progress snapshot of this install, so the
            // stage list travels with each one no matter which callback fired.
            $publish = static function (array $state) use (
                $progress,
                $progressToken,
                $lock,
                &$lastLockTouch,
                &$stages,
            ): void {
                $now = microtime(true);

                if (($now - $lastLockTouch) >= 2.0) {
                    $lastLockTouch = $now;

                    // Keep the install lock alive so a long download is never
                    // reclaimed as stale while it is still running.
                    @touch($lock);
                }

                $progress->set($progressToken, [
                    ...$state,
                    'stages' => $stages,
                ]);
            };

            $downloader->setStageCallback(
                static function (
                    string $key,
                    ?int $current = null,
                    ?int $total = null,
                ) use ($enterStage, &$stages, &$stageIndex, $publish): void {
                    $alreadyActive = $stageIndex >= 0
                        && ($stages[$stageIndex]['key'] ?? null) === $key;

                    $enterStage($key);

                    if (($stages[$stageIndex]['key'] ?? null) !== $key) {
                        // A late announcement of a step this install has already
                        // left: ignore it rather than rewinding the bar of the
                        // step actually in flight.
                        return;
                    }

                    if ($current !== null || $total !== null) {
                        $stages[$stageIndex]['current'] = $current;
                        $stages[$stageIndex]['total'] = $total;
                    }

                    if ($alreadyActive) {
                        // A counted update inside the step already in flight:
                        // keep its bar where it is and only refresh the counts.
                        $publish([
                            'phase' => 'download',
                            'percent' => (int) ($stages[$stageIndex]['percent'] ?? 0),
                            'indeterminate' => ($stages[$stageIndex]['percent'] ?? null) === null,
                            'downloaded_bytes' => $stages[$stageIndex]['downloaded_bytes'],
                            'total_bytes' => $stages[$stageIndex]['total_bytes'],
                        ]);

                        return;
                    }

                    // A stage transition carries no bytes of its own, so the
                    // bar restarts at zero (indeterminate) until the step it
                    // announced streams something.
                    $publish([
                        'phase' => 'download',
                        'percent' => 0,
                        'indeterminate' => true,
                    ]);
                },
            );

            $downloader->setProgressCallback(
                static function (
                    ?int $downloadedBytes,
                    ?int $totalBytes,
                ) use (&$stages, &$stageIndex, $publish): void {
                    $determinate = $totalBytes !== null && $totalBytes > 0;

                    $percent = $determinate && $downloadedBytes !== null
                        ? (int) floor(
                            min(1.0, $downloadedBytes / $totalBytes) * 100,
                        )
                        : 0;

                    // The byte window is per stage, so this percentage is the
                    // active step's own progress: the archive bar fills to 100%
                    // and then the extract bar starts, instead of the whole run
                    // sharing one window that freezes and rewinds.
                    if ($stageIndex >= 0) {
                        $stages[$stageIndex]['percent'] = $percent;
                        $stages[$stageIndex]['downloaded_bytes'] = $downloadedBytes;
                        $stages[$stageIndex]['total_bytes'] = $totalBytes;
                    }

                    $publish([
                        'phase' => 'download',
                        'percent' => $percent,
                        'indeterminate' => !$determinate,
                        'downloaded_bytes' => $downloadedBytes,
                        'total_bytes' => $totalBytes,
                    ]);
                },
            );

            $provider = $this->providerRegistry($downloader)->resolve($source);

            $package = $provider->getPackage($source);

            // Acquisition is done: the replace/unpack bookkeeping happens here,
            // after the download has fully succeeded and before deployment.
            $enterStage('prepare');

            $publish([
                'phase' => 'deploy',
                'percent' => 0,
                'indeterminate' => true,
            ]);

            $target = $this->serverTarget($server);

            // Replace semantics: the previous modpack's files must not survive
            // alongside the new install. The deployment planner only overwrites
            // paths the new pack also uses — every other file the old pack owns
            // would be orphaned in the server root (most visibly its mods).
            // Remove exactly those owned files (the same OwnershipRemover the
            // uninstall endpoint uses, so nothing outside the record's manifest
            // is ever touched), then drop the stale records. This runs after
            // the download has fully succeeded — the point where the install is
            // definitely proceeding — and before the new files deploy. Record
            // deletion happens only after complete file removal, mirroring the
            // uninstall endpoint's all-or-nothing behavior.
            $existingRecords = $this->store()->all((string) $server->uuid);

            if ($existingRecords !== []) {
                $remover = new OwnershipRemover($target);

                foreach ($existingRecords as $existingRecord) {
                    $outcome = $remover->remove(
                        $existingRecord->ownedFiles(),
                    );

                    if ($outcome['errors'] !== []) {
                        report(new RuntimeException(
                            'Modpack replace failed to remove the previously installed modpack files: '
                                . implode('; ', $outcome['errors']),
                        ));

                        throw new RuntimeException(
                            'Unable to remove the previously installed modpack. '
                            . 'No new files were deployed. Please uninstall it manually and try again.',
                        );
                    }

                    $this->store()->delete(
                        (string) $server->uuid,
                        $existingRecord->id,
                    );
                }
            }

            $enterStage('deploy');

            $orchestrator = $this->orchestrator(
                $target,
                static function (
                    int $deployedFiles,
                    int $totalFiles,
                ) use (&$stages, &$stageIndex, $publish): void {
                    $percent = (int) floor(
                        ($totalFiles > 0 ? $deployedFiles / $totalFiles : 0)
                            * 100,
                    );

                    if ($stageIndex >= 0) {
                        $stages[$stageIndex]['percent'] = $percent;
                        $stages[$stageIndex]['current'] = $deployedFiles;
                        $stages[$stageIndex]['total'] = $totalFiles;
                    }

                    $publish([
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

            // Every announced step is finished once deployment returns.
            foreach ($stages as $index => $stage) {
                $stages[$index]['state'] = 'done';
            }

            $publish([
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

            $this->logHistory(
                action: 'install',
                server: $server,
                modpack: $record->displayName,
                version: $record->version,
                detail: $result->totalFiles() . ' files deployed',
            );

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

    // Simple content install (mods, plugins, datapacks, resource packs,
    // shaders): download one file from the provider and upload it into the
    // server's target directory for that content type. No archive
    // extraction, dependency resolution, or replace semantics — each file is
    // tracked in its own install record so uninstall removes exactly what
    // was added.
    public function installContent(
        Request $request,
        Server $server,
    ): JsonResponse {
        $lock = null;
        $token = bin2hex(random_bytes(16));
        $progressToken = '';
        $target = null;

        @set_time_limit(0);

        try {
            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $this->clearCancelFlag($this->lockKey($server));

            $target = ContentInstallTarget::for(
                $this->contentTypeInput($request),
            );

            [$source, $displayName, $iconUrl, $mcVersion, $loader]
                = $this->contentInstallInput($request, $target['label']);

            // Optional additional dependency sources: the user picks
            // recommended mods in the version window and they are downloaded
            // in the same run, each as its own tracked record.
            $dependencySources = $this->contentDependencySources($request);

            [$provider, $projectId, $versionId] = $this->sourceParts($source);

            $progressToken = $this->progressToken($request);

            $progress = $this->installProgressStore();

            $progress->set($progressToken, [
                'phase' => 'starting',
                'percent' => 1,
                'indeterminate' => false,
            ]);

            $downloader = $this->downloader();

            $installer = new SimpleContentInstaller($downloader);

            // Every install in this run: the main content first, then any
            // selected dependencies. Each entry gets its own file and its
            // own install record so uninstall removes exactly what was added.
            $installItems = [[
                'source' => $source,
                'provider' => $provider,
                'project_id' => $projectId,
                'version_id' => $versionId,
                'display_name' => $displayName,
                'icon_url' => $iconUrl,
                'version_id_fallback' => $versionId,
                'is_dependency' => false,
            ]];

            foreach ($dependencySources as $index => $dependency) {
                [$depProvider, $depProject, $depVersion]
                    = $this->sourceParts($dependency['source']);

                $installItems[] = [
                    'source' => $dependency['source'],
                    'provider' => $depProvider,
                    'project_id' => $depProject,
                    'version_id' => $depVersion,
                    'display_name' => $dependency['name'],
                    'icon_url' => $dependency['icon_url'],
                    'version_id_fallback' => $depVersion,
                    'is_dependency' => true,
                ];
            }

            $totalItems = count($installItems);

            // Resolve every file up front. The concrete download URL and the
            // upstream filename live in the version payload, and knowing all
            // of them is what lets the files of this run download at the
            // same time instead of one after another.
            $resolver = new CatalogVersionFileResolver(
                $this->providerHttp(),
                $this->curseForgeApiKey(),
            );

            $prepared = [];

            foreach ($installItems as $item) {
                $file = $resolver->resolve(
                    $item['provider'],
                    $item['project_id'],
                    $item['version_id'] ?? '',
                );

                // A dependency can be a different kind of content than the
                // entry the user picked (a shader's Iris dependency is a
                // mod), so each file resolves its own destination.
                $itemTarget = $item['is_dependency']
                    ? ContentInstallTarget::forDependency(
                        $target,
                        basename($file['filename']),
                    )
                    : $target;

                // The uploaded file is renamed to encode the selection so
                // server owners can see at a glance what each file targets,
                // e.g. example-mod-fabric-1-20-1.jar.
                $prepared[] = [
                    'item' => $item,
                    // Which catalog kind this file is: a dependency can be a
                    // mod even when the picked entry was a shader.
                    'kind' => $itemTarget['kind'],
                    'url' => $file['url'],
                    // Declared size drives the per-file progress bars; the
                    // transfer itself is size-agnostic.
                    'bytes' => max(0, (int) ($file['size'] ?? 0)),
                    'filename' => ContentInstallTarget::filename(
                        basename($file['filename']),
                        $item['display_name'],
                        $mcVersion,
                        $loader,
                        $itemTarget['extensions'],
                    ),
                    'directory' => $itemTarget['directory'],
                ];
            }

            // Per-file state reported by the concurrent download engine, so
            // the downloading window can show one live card per file.
            $fileProgress = [];

            $lastLockTouch = 0.0;

            $report = function (array $state) use (
                $progress,
                $progressToken,
                $lock,
                &$lastLockTouch,
                &$fileProgress,
            ): void {
                $now = microtime(true);

                if (($now - $lastLockTouch) >= 2.0) {
                    $lastLockTouch = $now;

                    @touch($lock);
                }

                $progress->set($progressToken, [
                    ...$state,
                    'files' => $fileProgress,
                ]);
            };

            $results = $installer->installBatch(
                items: array_map(
                    static fn (array $entry): array => [
                        'url' => $entry['url'],
                        'bytes' => $entry['bytes'],
                        'filename' => $entry['filename'],
                        'directory' => $entry['directory'],
                    ],
                    $prepared,
                ),
                target: $this->serverTarget($server),
                workspace: self::TEMPORARY_ROOT
                    . '/content-'
                    . bin2hex(random_bytes(8)),
                cancelChecker: fn (): bool => $this->wasCancelled(
                    $this->lockKey($server),
                ),
                // Downloads own the 0–90% window; the uploads that follow
                // report the rest.
                onProgress: static function (
                    ?int $downloadedBytes,
                    ?int $totalBytes,
                ) use ($report, $totalItems): void {
                    $within = 1.0;

                    if (
                        $totalBytes !== null
                        && $totalBytes > 0
                        && $downloadedBytes !== null
                    ) {
                        $within = min(1.0, $downloadedBytes / $totalBytes);
                    }

                    $report([
                        'phase' => 'download',
                        'percent' => (int) floor($within * 90),
                        'indeterminate' => $totalBytes === null
                            || $totalBytes <= 0,
                        'downloaded_bytes' => $downloadedBytes,
                        'total_bytes' => $totalBytes,
                        'file_count' => $totalItems,
                    ]);
                },
                onFileProgress: static function (array $files) use (
                    &$fileProgress,
                    $prepared,
                ): void {
                    $states = [];

                    foreach ($files as $index => $state) {
                        if (!is_array($state)) {
                            continue;
                        }

                        $failed = ($state['failed'] ?? false) === true;
                        $done = ($state['done'] ?? false) === true;

                        $states[] = [
                            'kind' => $prepared[$index]['kind'] ?? null,
                            'downloaded_bytes' => (int) (
                                $state['downloaded_bytes'] ?? 0
                            ),
                            'total_bytes' => (int) (
                                $state['total_bytes'] ?? 0
                            ),
                            'state' => $failed
                                ? 'failed'
                                : ($done ? 'done' : 'downloading'),
                        ];
                    }

                    $fileProgress = $states;
                },
                onUpload: static function (int $index, int $count) use (
                    $report,
                ): void {
                    $report([
                        'phase' => 'deploy',
                        'percent' => 90 + (int) floor(
                            ($index / max(1, $count)) * 9,
                        ),
                        'indeterminate' => false,
                    ]);
                },
            );

            $firstRecord = null;
            $result = null;

            foreach ($prepared as $itemIndex => $entry) {
                $item = $entry['item'];

                $result = $results[$itemIndex] ?? null;

                if ($result === null) {
                    throw new RuntimeException(
                        'A content file in this install was not downloaded.'
                    );
                }

                $versionNumber = $this->contentVersionNumber(
                    $item['provider'],
                    $item['project_id'],
                    $item['version_id'] ?? '',
                );

                // Record exactly the file we placed so uninstall removes it.
                $record = new InstallRecord(
                    id: $this->newRecordId(),
                    serverUuid: (string) $server->uuid,
                    provider: $item['provider'],
                    projectId: $item['project_id'],
                    versionId: $item['version_id'] ?? '',
                    source: $item['source'],
                    displayName: $item['display_name'],
                    version: $versionNumber,
                    minecraftVersion: $mcVersion,
                    loader: $loader,
                    iconUrl: $item['icon_url'],
                    installedAt: gmdate('c'),
                    updatedAt: gmdate('c'),
                    status: InstallRecord::STATUS_INSTALLED,
                    createdFiles: [$result['path']],
                    overwrittenFiles: [],
                    contentType: InstallRecord::TYPE_CONTENT,
                    contentKind: $entry['kind'],
                );

                $this->store()->save($record);

                if ($firstRecord === null) {
                    $firstRecord = $record;
                }

                $this->logHistory(
                    action: 'install-content',
                    server: $server,
                    modpack: $item['display_name'],
                    version: $versionNumber,
                    detail: $result['path'] . ' (' . $result['size'] . ' bytes)',
                );
            }

            $progress->set($progressToken, [
                'phase' => 'complete',
                'percent' => 100,
                'indeterminate' => false,
            ]);

            return response()->json([
                'data' => [
                    'record_id' => $firstRecord?->id,
                    'path' => $result['path'],
                    'size' => $result['size'],
                    'installed_count' => $totalItems,
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
        } catch (CatalogProviderException $exception) {
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
                'error' => 'Unable to install the '
                    . ($target['label'] ?? 'content') . '.',
            ], 500);
        } finally {
            if ($lock !== null) {
                try {
                    $this->installationLock()->release($lock, $token);
                } catch (Throwable) {
                    // Stale-lock handling covers leftover locks.
                }
            }

            $this->clearCancelFlag($this->lockKey($server));
        }
    }

    // Which single-file content type an install targets. Defaults to a mod
    // so older clients (and the previous frontend) keep working.
    private function contentTypeInput(Request $request): string
    {
        $value = $request->input('content_type');

        if ($value === null || $value === '') {
            return ContentInstallTarget::DEFAULT_TYPE;
        }

        if (
            !is_string($value)
            || !ContentInstallTarget::supports($value)
        ) {
            throw new InvalidArgumentException(
                'Unsupported content type.',
            );
        }

        return $value;
    }

    // Validates the JSON body of a simple-content install request.
    // @return array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string}
    private function contentInstallInput(
        Request $request,
        string $label,
    ): array
    {
        $source = $this->installationSource($request);

        // Single-file content comes from either provider. Both are addressed
        // as "provider://project@version" and both install through the same
        // path; the resolver knows how to fetch each one's file.
        if (
            preg_match(
                '#^(modrinth://[A-Za-z0-9_-]{1,64}|curseforge://\d{1,12})@[A-Za-z0-9]{1,64}$#',
                $source,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Content can only be installed from a Modrinth or CurseForge source with a version.',
            );
        }

        $name = $request->input('name');

        $displayName = is_string($name) && trim($name) !== ''
            ? trim($name)
            : '';

        if (
            $displayName === ''
            || mb_strlen($displayName) > 128
        ) {
            throw new InvalidArgumentException(
                "The {$label} name is required.",
            );
        }

        $icon = $request->input('icon_url');

        $iconUrl = is_string($icon) && $icon !== '' ? $icon : null;

        // Loader and Minecraft version come from the version window's two
        // dropdowns; both are required before an install can start.
        $mcVersion = $this->contentSlug(
            $request->input('mc_version'),
            'Minecraft version',
        );

        // The loader is optional. Mods and modpacks need it to pick a
        // compatible file, but CurseForge does not record a loader for
        // plugins, resource packs, data packs or shaders — their version
        // window offers a Minecraft version only, and requiring a loader
        // here would make those installs impossible.
        $loader = $this->optionalContentSlug(
            $request->input('loader'),
            'Loader',
        );

        return [$source, $displayName, $iconUrl, $mcVersion, $loader];
    }

    // Optional "dependencies" body entries: recommended mods the user chose
    // in the version window. Each needs a Modrinth source with an explicit
    // version and a display name; icons are best-effort.
    // @return array<int, array{source: string, name: string, icon_url: ?string}>
    private function contentDependencySources(Request $request): array
    {
        $dependencies = $request->input('dependencies');

        if (!is_array($dependencies)) {
            return [];
        }

        if (count($dependencies) > 10) {
            throw new InvalidArgumentException(
                'Too many dependency mods were requested (maximum 10).',
            );
        }

        $items = [];

        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                throw new InvalidArgumentException(
                    'The dependency list is malformed.',
                );
            }

            $source = $dependency['source'] ?? null;

            if (
                !is_string($source)
                || preg_match(
                    '#^(modrinth://[A-Za-z0-9_-]{1,64}|curseforge://\d{1,12})@[A-Za-z0-9]{1,64}$#',
                    $source,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Each dependency needs a valid Modrinth or CurseForge source with a version.',
                );
            }

            $name = $dependency['name'] ?? null;

            if (
                !is_string($name)
                || trim($name) === ''
                || mb_strlen($name) > 128
            ) {
                throw new InvalidArgumentException(
                    'Each dependency needs a display name.',
                );
            }

            $icon = $dependency['icon_url'] ?? null;

            $items[] = [
                'source' => $source,
                'name' => trim($name),
                'icon_url' => is_string($icon) && $icon !== ''
                    ? $icon
                    : null,
            ];
        }

        return $items;
    }

    // Validates an optional loader value: absent or empty is allowed (a
    // loader-less content type), anything present must be well formed.
    private function optionalContentSlug(
        mixed $value,
        string $label,
    ): ?string {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->contentSlug($value, $label);
    }

    // Validates a loader/Minecraft-version value from the install body.
    private function contentSlug(mixed $value, string $label): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "The {$label} selection is required.",
            );
        }

        $value = trim($value);

        if (
            strlen($value) > 32
            || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                "The {$label} selection is invalid.",
            );
        }

        return $value;
    }

    // Best-effort human version label for a content install: the Modrinth
    // version number when it can be fetched, otherwise the version id.
    private function contentVersionNumber(
        string $provider,
        string $projectId,
        ?string $versionId,
    ): string {
        if ($versionId === null || $versionId === '') {
            return '';
        }

        try {
            $response = $this->providerHttp()->get(
                'https://api.modrinth.com/v2/version/'
                    . rawurlencode($versionId),
            );

            if (
                is_array($response->body)
                && is_string($response->body['version_number'] ?? null)
                && $response->body['version_number'] !== ''
            ) {
                return $response->body['version_number'];
            }
        } catch (Throwable) {
            // Fall through to the raw version id.
        }

        return $versionId;
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

            // Single-file content (mods) shares the directory with mods the
            // user installed themselves — only the recorded files may be
            // removed, never the (now possibly non-empty) directory.
            $outcome = (new OwnershipRemover($target))->remove(
                $record->ownedFiles(),
                pruneEmptyDirs: $record->contentType !== InstallRecord::TYPE_CONTENT,
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

            $this->logHistory(
                action: 'uninstall',
                server: $server,
                modpack: $record->displayName,
                version: $record->version,
                detail: count($outcome['deleted']) . ' files removed',
            );

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

            $this->logHistory(
                action: 'update',
                server: $server,
                modpack: $updated->displayName,
                version: $record->version . ' → ' . $updated->version,
                detail: $result->totalFiles() . ' files deployed',
            );

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

        // A restore still downloads the pack archive (the manifest inside it
        // identifies the files), which can take minutes on slow links. Lift
        // the request timer for the same reason the install route does.
        @set_time_limit(0);

        try {
            $id = $this->validateRecordId($id);

            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            $this->clearCancelFlag($this->lockKey($server));

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

            $downloader = $this->downloader();

            $downloader->setCancelChecker(
                fn (): bool => $this->wasCancelled(
                    $this->lockKey($server),
                ),
            );

            $provider = $this->providerRegistry($downloader)
                ->resolve($record->source);

            // Providers that support partial builds fetch only the missing
            // files: the pack archive still downloads (the manifest inside it
            // identifies every file), but the mods phase resolves and fetches
            // exactly the wanted paths instead of re-downloading hundreds of
            // mods that are already deployed and intact.
            if ($provider instanceof PartialPackageProvider) {
                $package = $provider->getPackageForPaths(
                    $record->source,
                    $missing,
                );
            } else {
                $package = $provider->getPackage($record->source);
            }

            $result = $this->orchestrator(
                $target,
                null,
                fn (): bool => $this->wasCancelled(
                    $this->lockKey($server),
                ),
            )->restore(
                archivePath: $package->archivePath,
                paths: $missing,
            );

            $this->logHistory(
                action: 'restore',
                server: $server,
                modpack: $record->displayName,
                version: $record->version,
                detail: $result->createdCount() . ' of '
                    . count($missing) . ' missing files restored',
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
        } catch (InstallationCancelledException $exception) {
            return response()->json([
                'error' => 'Restore cancelled.',
            ], 409);
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

            $this->clearCancelFlag($this->lockKey($server));
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

        $contentType = $this->paramString(
            $request,
            'content_type',
            CatalogSearchQuery::DEFAULT_CONTENT_TYPE,
            16,
            '/^[a-z-]{1,16}$/',
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
            contentType: $contentType,
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

    // Parses a comma-separated multi-value query parameter.
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
            $this->catalogCache(),
        );
    }



    // Settings saved from the admin page live in the panel database and take
    // precedence over the .env-backed config values. Empty stored values mean
    // "use the environment".
    private function setting(string $key): ?string
    {
        try {
            $value = app(
                \Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary::class,
            )->dbGet('modpackinstaller', 'setting_' . $key);
        } catch (Throwable) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function catalogCache(): ?CatalogCache
    {
        $ttl = $this->setting('catalog_cache_ttl')
            ?? config('modpackinstaller.catalog_cache_ttl');

        if (!is_numeric($ttl) || (int) $ttl <= 0) {
            return null;
        }

        return new CatalogCache((int) $ttl);
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
        $maxMb = $this->setting('max_download_mb')
            ?? config('modpackinstaller.max_download_mb');

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
        $key = $this->setting('curseforge_api_key')
            ?? config('modpackinstaller.curseforge_api_key');

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

    // Removes the files a just-completed deployment created when the install could not be recorded.
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
        $mode = $this->setting('server_target')
            ?? config('modpackinstaller.server_target', 'local');

        if (!is_string($mode) || trim($mode) === '') {
            return 'local';
        }

        return trim($mode);
    }

    // Whether a source targets CurseForge while the admin has disabled the
    // provider. Checked on install/update so hiding the provider is not just
    // cosmetic.
    private function curseForgeBlocked(string $source): bool
    {
        if ($this->setting('disable_curseforge') !== '1') {
            return false;
        }

        return stripos($source, 'curseforge:') === 0;
    }

    // Append one entry to the admin-visible install history. Best-effort:
    // a logging failure must never break the operation it records.
    private function logHistory(
        string $action,
        Server $server,
        string $modpack,
        string $version = '',
        string $detail = '',
    ): void {
        try {
            $this->historyStore()->append([
                'action' => $action,
                'server_uuid' => (string) $server->uuid,
                'server_name' => (string) ($server->name ?? ''),
                'user' => optional($request = request())->user()?->email ?? '',
                'modpack' => $modpack,
                'version' => $version,
                'detail' => $detail,
                'time' => date('c'),
            ]);
        } catch (Throwable) {
            // Intentionally ignored.
        }
    }

    private function historyStore(): InstallHistoryStore
    {
        return new InstallHistoryStore($this->installDataDir());
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
            // A modpack record keeps the kind it already had, so rewriting a
            // record never relabels the content it tracks.
            contentKind: $existingRecord?->contentKind
                ?? ContentInstallTarget::KIND_MODPACK,
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

    // Returns the unpinned base of an installed source so that an update can resolve the latest available version: the scheme...
    private function latestBaseSource(string $source): string
    {
        return explode('@', $source, 2)[0];
    }

    // @return array{0: string, 1: string, 2: string|null}
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
