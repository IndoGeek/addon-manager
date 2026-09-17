<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment;

use RuntimeException;
use Throwable;

final class DeploymentException extends RuntimeException
{
    // @param array<string> $deployed Paths deployed before the failure.
    public function __construct(
        string $message,
        private readonly array $deployed,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    // @return array<string>
    public function deployed(): array
    {
        return $this->deployed;
    }
}