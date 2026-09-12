<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;

/**
 * CurseForge catalog search is currently not implemented. The provider uses
 * the same normalized contract and is listed as unavailable so the API and UI
 * behave consistently, but it never claims live search support and never makes
 * an upstream request (the API key is never sent anywhere by this provider).
 */
final class CurseForgeCatalogProvider implements CatalogProvider
{
    public function __construct(
        private readonly ?string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    public function available(): bool
    {
        return false;
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new CatalogUnavailableException(
                'The CurseForge catalog is not configured. Set the CURSEFORGE_API_KEY server-side environment variable.',
            );
        }

        throw new CatalogUnavailableException(
            'The CurseForge catalog is not available yet.',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function versions(CatalogVersionQuery $query): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new CatalogUnavailableException(
                'The CurseForge catalog is not configured. Set the CURSEFORGE_API_KEY server-side environment variable.',
            );
        }

        throw new CatalogUnavailableException(
            'The CurseForge catalog is not available yet.',
        );
    }
}
