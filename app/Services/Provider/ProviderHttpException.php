<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider;

use RuntimeException;
use Throwable;

/**
 * Represents a provider API request failure. Messages are deliberately
 * generic and never include credentials, request headers, or response bodies.
 */
final class ProviderHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): ?int
    {
        return $this->status;
    }
}