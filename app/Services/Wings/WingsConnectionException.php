<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

/**
 * Raised when the Wings node cannot be reached at all (connection refused,
 * DNS failure, TLS failure, or the request timed out and was aborted).
 *
 * The message intentionally contains no connection details, tokens, or
 * server identifiers.
 */
final class WingsConnectionException extends WingsException
{
}