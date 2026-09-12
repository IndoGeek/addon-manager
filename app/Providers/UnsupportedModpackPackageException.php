<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers;

use RuntimeException;

/**
 * Thrown when a provider locates a package that cannot be converted into a
 * server-ready archive with the current installation engine.
 */
final class UnsupportedModpackPackageException extends RuntimeException
{
}