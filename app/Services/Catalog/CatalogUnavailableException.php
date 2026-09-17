<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use RuntimeException;

// Raised when a catalog provider is not configured, unreachable, timing out, or rate limited.
final class CatalogUnavailableException extends RuntimeException
{
}