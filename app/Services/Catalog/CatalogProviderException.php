<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use RuntimeException;

// Raised when a catalog provider responds with data the provider cannot validate (malformed payloads, rejected requests).
final class CatalogProviderException extends RuntimeException
{
}