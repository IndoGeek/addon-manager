<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

// Raised when Wings returns a non-success HTTP status code.
class WingsHttpException extends WingsException
{
    public function __construct(
        private readonly int $status,
    ) {
        parent::__construct(self::messageFor($status));
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    private static function messageFor(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 =>
                'The server node denied the request. This may be an authentication failure or a restricted file.',
            $status === 404 =>
                'The requested resource was not found on the server node.',
            $status === 409 =>
                'The server node reported a conflict while processing the request.',
            $status === 422 =>
                'The server node rejected the request.',
            $status === 429 =>
                'The server node is temporarily rate limiting requests.',
            $status >= 500 =>
                'The server node is currently unavailable.',
            default =>
                'The server node returned an unexpected response.',
        };
    }
}