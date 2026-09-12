<?php

namespace Pterodactyl\Models {

    final class Node
    {
        public function __construct(
            private readonly string $address,
            private readonly string $key,
        ) {
        }

        public function getConnectionAddress(): string
        {
            return $this->address;
        }

        public function getDecryptedKey(): string
        {
            return $this->key;
        }
    }

    final class Server
    {
        public string $uuid = '';

        public ?Node $node = null;
    }
}

namespace {

    $projectRoot = dirname(__DIR__, 2);

    spl_autoload_register(static function (string $class) use ($projectRoot): void {
        $prefix =
            'Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $projectRoot
            . '/app/'
            . str_replace('\\', '/', $relative)
            . '.php';

        if (is_file($path)) {
            require $path;
        }
    });

    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerTargetSelector;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\WingsServerFileTarget;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\WingsServerFileTargetFactory;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsConnectionException;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsTransport;
    use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsTransportResponse;

    final class FakeWingsTransportForSelection implements WingsTransport
    {
        /** @var array<int, array{method: string, url: string, options: array}> */
        public array $requests = [];

        public function __construct(
            private array $responses = [],
        ) {
        }

        public function request(string $method, string $url, array $options = []): WingsTransportResponse
        {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ($this->responses === []) {
                throw new WingsConnectionException(
                    'Unable to reach the Wings node for this server.',
                );
            }

            return array_shift($this->responses);
        }
    }

    $root = sys_get_temp_dir()
        . '/modpack-selector-'
        . bin2hex(random_bytes(4));

    $serverUuid = '539fdca8-4a08-4551-a8d2-8ee5475b50d9';

    if (!is_dir($root . '/' . $serverUuid)) {
        mkdir($root . '/' . $serverUuid, 0777, true);
    }

    $node = new \Pterodactyl\Models\Node(
        'http://wings.example:8080',
        'selector-secret',
    );

    $server = new \Pterodactyl\Models\Server();
    $server->uuid = $serverUuid;
    $server->node = $node;

    $localFactory = new ServerFileTargetFactory($root);

    $wingsProbe = new FakeWingsTransportForSelection([
        new WingsTransportResponse(200, '[]'),
    ]);

    $wingsFactory = new WingsServerFileTargetFactory(
        transport: $wingsProbe,
    );

    $count = 0;

    // local mode resolves to the local filesystem target and never touches Wings.
    $selector = new ServerTargetSelector(
        'local',
        $localFactory,
        $wingsFactory,
    );

    if ($selector->mode() !== 'local') {
        throw new RuntimeException('Local mode not reported.');
    }

    $target = $selector->forServer($server);

    if (!$target instanceof LocalFilesystemServerFileTarget) {
        throw new RuntimeException('Local mode did not produce the local target.');
    }

    $target->write('selector.txt', 'hello');

    if ($target->read('selector.txt') !== 'hello') {
        throw new RuntimeException('Local target read-back failed.');
    }

    if ($wingsProbe->requests !== []) {
        throw new RuntimeException('Wings should never be contacted in local mode.');
    }

    $count++;
    echo "PASS: local mode uses the local filesystem target\n";

    // wings mode resolves through the Wings factory to the Wings target.
    $selector = new ServerTargetSelector(
        'WINGS',
        $localFactory,
        new WingsServerFileTargetFactory(
            timeout: 5,
            connectTimeout: 1,
            transport: $wingsProbe,
        ),
    );

    if ($selector->mode() !== 'wings') {
        throw new RuntimeException('Wings mode not normalized.');
    }

    $target = $selector->forServer($server);

    if (!$target instanceof WingsServerFileTarget) {
        throw new RuntimeException('Wings mode did not produce the Wings target.');
    }

    if ($target->exists('server.properties') !== false) {
        throw new RuntimeException('Unexpected existence result from the Wings target.');
    }

    $request = $wingsProbe->requests[0];

    if (
        $request['url']
        !== 'http://wings.example:8080/api/servers/' . $serverUuid . '/files/list-directory'
    ) {
        throw new RuntimeException('Unexpected request URL from selector wiring.');
    }

    if (($request['options']['headers']['Authorization'] ?? null) !== 'Bearer selector-secret') {
        throw new RuntimeException('Unexpected Authorization header from selector wiring.');
    }

    $count++;
    echo "PASS: wings mode resolves the authenticated Wings target from the server node\n";

    // wings mode never resolves a bare identity.
    try {
        $selector->forIdentity(ServerIdentity::fromUuid($serverUuid));
        throw new RuntimeException('Identity resolution should fail in wings mode.');
    } catch (InvalidArgumentException $exception) {
        // expected
    }

    $count++;
    echo "PASS: wings mode refuses bare identity resolution\n";

    // invalid mode is a hard configuration error.
    try {
        new ServerTargetSelector(
            'cloud',
            $localFactory,
            $wingsFactory,
        );
        throw new RuntimeException('Invalid mode should be rejected.');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'local')) {
            throw new RuntimeException('Invalid-mode error should mention local.');
        }
        if (!str_contains($exception->getMessage(), 'wings')) {
            throw new RuntimeException('Invalid-mode error should mention wings.');
        }
    }

    $count++;
    echo "PASS: invalid target mode is rejected with a clear error\n";

    // local mode still resolves identities through the existing resolver.
    $identitySelector = new ServerTargetSelector(
        'local',
        $localFactory,
        $wingsFactory,
    );

    $identityTarget = $identitySelector->forIdentity(
        ServerIdentity::fromUuid($serverUuid),
    );

    if (!$identityTarget instanceof LocalFilesystemServerFileTarget) {
        throw new RuntimeException('Identity resolution in local mode failed.');
    }

    $count++;
    echo "PASS: local mode keeps resolving identities through the existing resolver\n";

    // the server root directory is created as part of local resolution.
    if (!is_dir($root . '/' . $serverUuid)) {
        throw new RuntimeException('Server root directory missing.');
    }

    echo "\n{$count}/{$count} server target selector tests passed.\n";

    $localFactory = null;

    $cleanup = function(string $path) use (&$cleanup): void {
        $items = scandir($path);

        foreach ($items ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;

            if (is_dir($full)) {
                $cleanup($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    };

    $cleanup($root);

    echo "Cleanup complete.\n";
}