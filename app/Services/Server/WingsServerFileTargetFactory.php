<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\GuzzleWingsTransport;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsFileClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsTransport;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Builds a Wings-backed target from the authenticated Pterodactyl Server
 * model. The node connection address and daemon key come exclusively from the
 * Panel's Node model (same scheme the Panel itself uses); no URL, node ID,
 * port or token is ever accepted from a client request.
 */
final class WingsServerFileTargetFactory
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly bool $verifySsl = true,
        private readonly ?WingsTransport $transport = null,
    ) {
    }

    public function forServer(Server $server): WingsServerFileTarget
    {
        $node = $server->node;

        if (!$node instanceof Node) {
            throw new InvalidArgumentException(
                'The server does not have a node configured.'
            );
        }

        $connectionAddress = $node->getConnectionAddress();
        $daemonKey = $node->getDecryptedKey();

        if ($connectionAddress === '' || $daemonKey === '') {
            throw new InvalidArgumentException(
                'The node for this server is not fully configured.'
            );
        }

        $transport = $this->transport ?? new GuzzleWingsTransport(
            $this->timeout,
            $this->connectTimeout,
            $this->verifySsl,
        );

        return new WingsServerFileTarget(
            new WingsFileClient(
                $transport,
                $connectionAddress,
                $daemonKey,
                $server->uuid,
            ),
        );
    }
}