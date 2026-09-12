<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use RuntimeException;

/**
 * Raised when a catalog provider is not configured, unreachable, timing out,
 * or rate limited. The API maps this to a 503 so clients can retry later.
 */
final class CatalogUnavailableException extends RuntimeException
{
}