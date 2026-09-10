<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;
use RuntimeException;

final class PackageRootResolver
{
    public function resolve(
        string $workspace,
        PackageLayout $layout,
    ): string {
        $workspace = realpath($workspace);

        if ($workspace === false || !is_dir($workspace)) {
            throw new InvalidArgumentException(
                'The installation workspace does not exist.'
            );
        }

        return match ($layout) {
            PackageLayout::DIRECT => $workspace,

            PackageLayout::OVERRIDES => $this->resolveOverrides(
                $workspace,
            ),
        };
    }

    private function resolveOverrides(string $workspace): string
    {
        $overrides = $workspace . '/overrides';

        if (!is_dir($overrides)) {
            throw new RuntimeException(
                'The archive does not contain an overrides directory.'
            );
        }

        $resolved = realpath($overrides);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException(
                'Unable to resolve the overrides directory.'
            );
        }

        return $resolved;
    }
}
