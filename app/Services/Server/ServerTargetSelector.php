<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use InvalidArgumentException;
use Pterodactyl\Models\Server;

// Selects the server file target based on the configured deployment mode.
final class ServerTargetSelector
{
    public const MODE_LOCAL = 'local';

    public const MODE_WINGS = 'wings';

    private readonly string $mode;

    public function __construct(
        string $mode,
        private readonly ServerFileTargetFactory $localFactory,
        private readonly WingsServerFileTargetFactory $wingsFactory,
    ) {
        $normalized = strtolower(trim($mode));

        if (
            $normalized !== self::MODE_LOCAL
            && $normalized !== self::MODE_WINGS
        ) {
            throw new InvalidArgumentException(
                "Invalid server target mode: {$mode}. "
                . 'Expected "local" or "wings".'
            );
        }

        $this->mode = $normalized;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function forIdentity(ServerIdentity $identity): ServerFileTarget
    {
        if ($this->mode !== self::MODE_LOCAL) {
            throw new InvalidArgumentException(
                'A server identity cannot be resolved in wings mode; '
                . 'the authenticated server model is required.'
            );
        }

        return (new ServerTargetResolver($this->localFactory))
            ->resolve($identity);
    }

    public function forServer(Server $server): ServerFileTarget
    {
        if ($this->mode === self::MODE_LOCAL) {
            return (new ServerTargetResolver($this->localFactory))
                ->resolve(ServerIdentity::fromUuid($server->uuid));
        }

        return $this->wingsFactory->forServer($server);
    }
}