<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\CurseForgeCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\MockCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog\ModrinthCatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\CurseForgeProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\UnsupportedModpackPackageException;
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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLock;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLockedException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageLayout;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;
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
        return response()->json([
            'data' => $this->catalogService()->providers(),
        ]);
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

    public function preview(
        Request $request,
        Server $server,
    ): JsonResponse {
        $provider = null;
        $package = null;

        try {
            [$source, $policy, $layout] =
                $this->installationOptions($request);

            $provider = $this->providerRegistry()->resolve($source);

            $package = $provider->getPackage($source);

            $target = $this->serverTarget($server);

            $orchestrator = $this->orchestrator($target);

            $preview = $orchestrator->preview(
                archivePath: $package->archivePath,
                policy: $policy,
                layout: $layout,
            );

            $operations = [];

            foreach ($preview->plan->operations as $operation) {
                $operations[] = [
                    'path' => $operation->relativePath,
                    'action' => $operation->policy->value,
                ];
            }

            return response()->json([
                'data' => [
                    'total_files' => $preview->totalFiles(),
                    'create_count' => $preview->createdCount(),
                    'overwrite_count' => $preview->overwrittenCount(),
                    'operations' => $operations,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
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
                'error' => 'Unable to preview the modpack installation.',
            ], 500);
        } finally {
            if ($provider !== null && $package !== null) {
                $provider->cleanup($package);
            }
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

        try {
            $lock = $this->installationLock()->acquire(
                $this->lockKey($server),
                $token,
            );

            [$source, $policy, $layout] =
                $this->installationOptions($request);

            $provider = $this->providerRegistry()->resolve($source);

            $package = $provider->getPackage($source);

            $target = $this->serverTarget($server);

            $orchestrator = $this->orchestrator($target);

            $result = $orchestrator->install(
                archivePath: $package->archivePath,
                policy: $policy,
                layout: $layout,
            );

            return response()->json([
                'data' => [
                    'total_files' => $result->totalFiles(),
                    'created' => $result->createdCount(),
                    'overwritten' => $result->overwrittenCount(),
                    'backed_up' => $result->backupCount(),
                ],
            ]);
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
        }
    }

    private function installationOptions(
        Request $request,
    ): array {
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

        $policyValue = $request->input(
            'policy',
            DeploymentPolicy::OVERWRITE->value,
        );

        if (!is_string($policyValue)) {
            throw new InvalidArgumentException(
                'Invalid installation policy.',
            );
        }

        $policy = DeploymentPolicy::tryFrom($policyValue);

        if ($policy === null) {
            throw new InvalidArgumentException(
                'Invalid installation policy.',
            );
        }

        $layoutValue = $request->input(
            'layout',
            PackageLayout::DIRECT->value,
        );

        if (!is_string($layoutValue)) {
            throw new InvalidArgumentException(
                'Invalid package layout.',
            );
        }

        $layout = PackageLayout::tryFrom($layoutValue);

        if ($layout === null) {
            throw new InvalidArgumentException(
                'Invalid package layout.',
            );
        }

        return [
            $source,
            $policy,
            $layout,
        ];
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

        $gameVersion = $this->paramString(
            $request,
            'game_version',
            null,
            32,
            CatalogSearchQuery::VERSION_PATTERN,
        );

        $loader = $this->paramString(
            $request,
            'loader',
            null,
            32,
            CatalogSearchQuery::SLUG_PATTERN,
        );

        $category = $this->paramString(
            $request,
            'category',
            null,
            32,
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
            gameVersion: $gameVersion,
            loader: $loader,
            category: $category,
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

        $gameVersion = $this->paramString(
            $request,
            'game_version',
            null,
            32,
            CatalogSearchQuery::VERSION_PATTERN,
        );

        $loader = $this->paramString(
            $request,
            'loader',
            null,
            32,
            CatalogSearchQuery::SLUG_PATTERN,
        );

        return new CatalogVersionQuery(
            provider: $provider,
            project: $project,
            gameVersion: $gameVersion,
            loader: $loader,
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

    private function parameterLabel(string $key): string
    {
        return str_replace('_', ' ', $key);
    }

    private function catalogService(): CatalogService
    {
        return new CatalogService(
            new CatalogProviderRegistry([
                new MockCatalogProvider(),
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

    private function providerRegistry(): ModpackProviderRegistry
    {
        $http = $this->providerHttp();

        return new ModpackProviderRegistry([
            new MockModpackProvider(),
            new ModrinthProvider(
                http: $http,
                downloader: $this->downloader(),
                temporaryRoot: self::TEMPORARY_ROOT,
            ),
            new CurseForgeProvider(
                http: $http,
                downloader: $this->downloader(),
                apiKey: $this->curseForgeApiKey(),
            ),
        ]);
    }

    private function downloader(): DownloadManager
    {
        $maxMb = env('MODPACK_INSTALLER_MAX_DOWNLOAD_MB');

        if (is_numeric($maxMb) && (int) $maxMb > 0) {
            return new DownloadManager(
                self::TEMPORARY_ROOT,
                (int) $maxMb * 1024 * 1024,
            );
        }

        return new DownloadManager(self::TEMPORARY_ROOT);
    }

    private function providerHttp(): ProviderHttpClient
    {
        return new CurlProviderHttpClient();
    }

    private function curseForgeApiKey(): ?string
    {
        $key = env('CURSEFORGE_API_KEY');

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

    private function targetMode(): string
    {
        $mode = env('MODPACK_INSTALLER_SERVER_TARGET', 'local');

        if (!is_string($mode) || trim($mode) === '') {
            return 'local';
        }

        return trim($mode);
    }

    private function localServerRoot(): string
    {
        $root = env('MODPACK_INSTALLER_SERVER_ROOT');

        if (is_string($root) && $root !== '') {
            return $root;
        }

        return self::VOLUMES_ROOT;
    }

    private function wingsServerTargetFactory(): WingsServerFileTargetFactory
    {
        return new WingsServerFileTargetFactory(
            timeout: 30,
            connectTimeout: 10,
            verifySsl: app()->environment('production'),
        );
    }

    private function orchestrator(
        ServerFileTarget $target,
    ): InstallationOrchestrator {
        return new InstallationOrchestrator(
            workspaceManager: new InstallationWorkspace(
                self::TEMPORARY_ROOT,
            ),
            planner: new DeploymentPlanner($target),
            backupManager: new BackupManager($target),
            executor: new DeploymentExecutor($target),
            temporaryRoot: self::TEMPORARY_ROOT,
            packageRootResolver: new PackageRootResolver(),
            serverFileTarget: $target,
        );
    }
}
