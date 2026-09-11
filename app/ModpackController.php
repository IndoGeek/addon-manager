<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageLayout;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\ModpackProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerTargetResolver;
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
        try {
            [$source, $policy, $layout] =
                $this->installationOptions($request);

            $provider = $this->providerRegistry()->resolve($source);

            $package = $provider->getPackage($source);

            $identity = ServerIdentity::fromUuid($server->uuid);

            $target = $this->serverTarget($identity);

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
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to preview the modpack installation.',
            ], 500);
        }
    }

    public function install(
        Request $request,
        Server $server,
    ): JsonResponse {
        try {
            [$source, $policy, $layout] =
                $this->installationOptions($request);

            $provider = $this->providerRegistry()->resolve($source);

            $package = $provider->getPackage($source);

            $identity = ServerIdentity::fromUuid($server->uuid);

            $target = $this->serverTarget($identity);

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
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'Unable to install the modpack.',
            ], 500);
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
        return new ModpackProviderRegistry([
            new MockModpackProvider(),
        ]);
    }

    private function serverTarget(
        ServerIdentity $identity,
    ): ServerFileTarget {
        $factory = new ServerFileTargetFactory(
            self::VOLUMES_ROOT,
        );

        $resolver = new ServerTargetResolver($factory);

        return $resolver->resolve($identity);
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
