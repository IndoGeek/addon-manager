<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

// Raised when the Wings node cannot be reached at all (connection refused, DNS failure, TLS failure, or the request timed...
final class WingsConnectionException extends WingsException
{
}