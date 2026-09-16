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
     * The current state of the provider: "available", "unavailable", or
     * "not_configured". Lets the UI distinguish a missing API key from a hard
     * outage without leaking internals.
     */
    public function state(): string;

    /**
     * Whether this provider is only meant for development/internal use and must
     * not be offered as a normal browsing source. Development providers keep
     * working through the API so automated tests can exercise them.
     */
    public function developmentOnly(): bool;

    /**
     * A short, static reason the provider is currently unavailable, or null
     * when it is available. Shown to the user so they can react (for example
     * configuring a missing API key). Must never leak secrets or internals.
     */
    public function unavailableReason(): ?string;

    /**
     * Which catalog filters/sorts this provider genuinely supports. The UI
     * only offers controls whose capability is true, so users are never shown
     * fake filters that would silently do nothing.
     *
     * @return array{query: bool, game_versions: bool, loaders: bool, categories: bool, environment: bool, sort: bool}
     */
    public function capabilities(): array;

    /**
     * The option values the provider exposes for each filter group. These are
     * static capability metadata (the values a provider can express), never
     * search results.
     *
     * @return array{game_versions: array<int, string>, loaders: array<int, string>, categories: array<int, string>, environments: array<int, string>}
     */
    public function facets(): array;

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

    /**
     * Returns the normalized details for a single project, including data that
     * a compact search hit might omit. The returned item carries the same
     * contract as a search result so the details UI can reuse one renderer.
     *
     * @throws CatalogUnavailableException When the provider is unreachable,
     *                                     timing out, rate limited, or disabled.
     * @throws CatalogProviderException    When the provider responds with a
     *                                     payload this provider cannot validate.
     * @throws InvalidArgumentException    When the query itself is invalid.
     */
    public function project(CatalogProjectQuery $query): CatalogItem;

    /**
     * Returns the provider's full project description as a sanitized HTML
     * fragment (see DescriptionSanitizer for the safety contract). Providers
     * with no long-form body may return an empty html string; the UI then
     * falls back to the item summary.
     *
     * @throws CatalogUnavailableException When the provider is unreachable,
     *                                     timing out, rate limited, or disabled.
     * @throws CatalogProviderException    When the provider responds with a
     *                                     payload this provider cannot validate.
     * @throws InvalidArgumentException    When the query itself is invalid.
     */
    public function description(CatalogProjectQuery $query): CatalogDescription;
}