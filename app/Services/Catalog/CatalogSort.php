<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * Sorting options exposed by the catalog API. Values map 1:1 onto the
 * Modrinth search "index" values so no translation table is required.
 */
enum CatalogSort: string
{
    case RELEVANCE = 'relevance';

    case DOWNLOADS = 'downloads';

    case FOLLOWS = 'follows';

    case NEWEST = 'newest';

    case UPDATED = 'updated';
}