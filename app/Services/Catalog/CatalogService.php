<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * Application-level catalog facade used by the HTTP layer. It resolves the
 * requested provider, refuses unavailable providers, and exposes the list of
 * providers (with their availability) for the UI's provider selector.
 */
final class CatalogService
{
    public function __construct(
        private readonly CatalogProviderRegistry $registry,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->registry->all() as $provider) {
            $providers[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'available' => $provider->available(),
                'unavailable_reason' => $provider->unavailableReason(),
            ];
        }

        return $providers;
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $provider = $this->registry->get($query->provider);

        if (!$provider->available()) {
            throw new CatalogUnavailableException(
                $this->unavailableMessage($provider),
            );
        }

        return $provider->search($query);
    }

    public function versions(CatalogVersionQuery $query): CatalogVersionList
    {
        $provider = $this->registry->get($query->provider);

        if (!$provider->available()) {
            throw new CatalogUnavailableException(
                $this->unavailableMessage($provider),
            );
        }

        return new CatalogVersionList(
            provider: $provider->name(),
            appliedGameVersion: $query->gameVersion,
            appliedLoader: $query->loader,
            versions: $provider->versions($query),
        );
    }

    private function unavailableMessage(CatalogProvider $provider): string
    {
        $reason = $provider->unavailableReason();

        if ($reason !== null && $reason !== '') {
            return $reason;
        }

        return 'The requested modpack catalog provider is not available.';
    }
}
