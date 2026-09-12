<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\CurseForgeProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModrinthProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\UnsupportedModpackPackageException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
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
        $source = trim((string) $request->query('source'));

        if ($source === '') {
            return response()->json([
                'error' => 'The source parameter is required.',
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

        try {
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
            if ($provider !== null && $package !== null) {
                $provider->cleanup($package);
            }
        }
    }

    private function installationOptions(
        Request $request,
    ): array {
        $source = trim((string) $request->input('source'));

        if ($source === '') {
            throw new InvalidArgumentException(
                'The source parameter is required.',
            );
        }

        $policy = DeploymentPolicy::tryFrom(
            (string) $request->input(
                'policy',
                DeploymentPolicy::OVERWRITE->value,
            ),
        );

        if ($policy === null) {
            throw new InvalidArgumentException(
                'Invalid installation policy.',
            );
        }

        $layout = PackageLayout::tryFrom(
            (string) $request->input(
                'layout',
                PackageLayout::DIRECT->value,
            ),
        );

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

    private function providerRegistry(): ModpackProviderRegistry
    {
        $http = $this->providerHttp();

        return new ModpackProviderRegistry([
            new MockModpackProvider(),
            new ModrinthProvider(
                http: $http,
                downloader: new DownloadManager(
                    self::TEMPORARY_ROOT,
                ),
                temporaryRoot: self::TEMPORARY_ROOT,
            ),
            new CurseForgeProvider(
                http: $http,
                downloader: new DownloadManager(
                    self::TEMPORARY_ROOT,
                ),
                apiKey: $this->curseForgeApiKey(),
            ),
        ]);
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
