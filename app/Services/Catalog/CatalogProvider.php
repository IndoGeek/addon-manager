<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * A provider that can be browsed and searched from the catalog UI. This is a
 * separate contract from ModpackProvider: browsing/search is read-only and
 * must never download or install anything.
 */
interface CatalogProvider
{
    /**
     * Stable machine name used as the catalog "provider" filter value.
     */
    public function name(): string;

    /**
     * Human-readable label shown in the provider selector.
     */
    public function label(): string;

    /**
     * Whether catalog search is currently usable for this provider. A provider
     * may be recognizable by name but unavailable (missing configuration, or a
     * catalog implementation that does not exist yet).
     */
    public function available(): bool;

    /**
     * A short, static reason the provider is currently unavailable, or null
     * when it is available. Shown to the user so they can react (for example
     * configuring a missing API key). Must never leak secrets or internals.
     */
    public function unavailableReason(): ?string;

    /**
     * @throws CatalogUnavailableException When the provider is unreachable,
     *                                     timing out, rate limited, or disabled.
     * @throws CatalogProviderException    When the provider responds with a
     *                                     payload this provider cannot validate.
     * @throws InvalidArgumentException    When the query itself is invalid.
     */
    public function search(CatalogSearchQuery $query): CatalogResult;

    /**
     * Returns the exact versions/releases available for a single project.
     * Every returned entry must carry a resolved installable source for that
     * exact version so the UI can hand it straight to the installation flow.
     *
     * @return array<int, CatalogVersion>
     *
     * @throws CatalogUnavailableException When the provider is unreachable,
     *                                     timing out, rate limited, or disabled.
     * @throws CatalogProviderException    When the provider responds with a
     *                                     payload this provider cannot validate.
     * @throws InvalidArgumentException    When the query itself is invalid.
     */
    public function versions(CatalogVersionQuery $query): array;
}
