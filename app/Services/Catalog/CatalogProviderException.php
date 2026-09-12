<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use RuntimeException;

/**
 * Raised when a catalog provider responds with data the provider cannot
 * validate (malformed payloads, rejected requests). The API maps this to a
 * 502 - the request itself is valid but the upstream response is unusable.
 */
final class CatalogProviderException extends RuntimeException
{
}