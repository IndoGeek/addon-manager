<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

// A provider that can be browsed and searched from the catalog UI.
interface CatalogProvider
{
    // Stable machine name used as the catalog "provider" filter value.
    public function name(): string;

    // Human-readable label shown in the provider selector.
    public function label(): string;

    // Whether catalog search is currently usable for this provider.
    public function available(): bool;

    // The current state of the provider: "available", "unavailable", or "not_configured".
    public function state(): string;

    // Whether this provider is only meant for development/internal use and must not be offered as a normal browsing source.
    public function developmentOnly(): bool;

    // A short, static reason the provider is currently unavailable, or null when it is available.
    public function unavailableReason(): ?string;

    // Which catalog filters/sorts this provider genuinely supports.
    public function capabilities(): array;

    // The option values the provider exposes for each filter group.
    public function facets(string $contentType = 'modpack'): array;

    // timing out, rate limited, or disabled. payload this provider cannot validate.
    public function search(CatalogSearchQuery $query): CatalogResult;

    // Returns the exact versions/releases available for a single project.
    public function versions(CatalogVersionQuery $query): array;

    // Returns the normalized details for a single project, including data that a compact search hit might omit.
    public function project(CatalogProjectQuery $query): CatalogItem;

    // Returns the provider's full project description as a sanitized HTML fragment (see DescriptionSanitizer for the safety...
    public function description(CatalogProjectQuery $query): CatalogDescription;
}