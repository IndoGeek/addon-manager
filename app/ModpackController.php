<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\ModpackProviderRegistry;

final class ModpackController extends Controller
{
    public function metadata(Request $request): JsonResponse
    {
        $source = (string) $request->query('source');

        if ($source === '') {
            return response()->json([
                'error' => 'The source parameter is required.',
            ], 422);
        }

        try {
            $registry = new ModpackProviderRegistry([
                new MockModpackProvider(),
            ]);

            $provider = $registry->resolve($source);
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
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
