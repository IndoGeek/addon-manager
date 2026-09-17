<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

// Resolves catalog providers by name so the API layer never depends on a concrete provider class.
final class CatalogProviderRegistry
{
    // @param array<int, CatalogProvider> $providers
    public function __construct(
        private readonly array $providers,
    ) {
    }

    // @return array<int, CatalogProvider>
    public function all(): array
    {
        return $this->providers;
    }

    public function get(string $name): CatalogProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw new InvalidArgumentException(
            'The requested modpack catalog provider is not supported.',
        );
    }
}