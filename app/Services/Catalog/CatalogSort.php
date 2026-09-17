<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

// Sorting options exposed by the catalog API.
enum CatalogSort: string
{
    case RELEVANCE = 'relevance';

    case DOWNLOADS = 'downloads';

    case FOLLOWS = 'follows';

    case NEWEST = 'newest';

    case UPDATED = 'updated';
}