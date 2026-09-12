<?php

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\WingsServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsFileClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsFileNotFoundException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsHttpException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsTransport;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings\WingsTransportResponse;

final class FakeWingsTransport implements WingsTransport
{
    /** @var array<int, array{method: string, url: string, options: array}> */
    public array $requests = [];

    private int $index = 0;

    /**
     * @param array<int, WingsTransportResponse> $responses
     */
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

        if (!array_key_exists($this->index, $this->responses)) {
            throw new WingsConnectionException(
                'Unable to reach the Wings node for this server.',
            );
        }

        return $this->responses[$this->index++];
    }
}

const NODE_ADDRESS = 'http://127.0.0.1:8080';
const DAEMON_KEY = 'daemon-secret-token';
const SERVER_UUID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function ok(string $responseBody): WingsTransportResponse
{
    return new WingsTransportResponse(200, $responseBody);
}

function makeTarget(FakeWingsTransport $transport): WingsServerFileTarget
{
    return new WingsServerFileTarget(
        new WingsFileClient(
            $transport,
            NODE_ADDRESS,
            DAEMON_KEY,
            SERVER_UUID,
        ),
    );
}

$count = 0;

// 1 + 2 + 14: authenticated request with the resolved server UUID.
$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'server.properties', 'file' => true]])),
]);
$target = makeTarget($transport);

if (!$target->exists('server.properties')) {
    throw new RuntimeException('Unexpected result for authenticated exists().');
}

$request = $transport->requests[0];

if ($request['method'] !== 'GET') {
    throw new RuntimeException('Expected a GET request.');
}

if (
    $request['url']
    !== NODE_ADDRESS . '/api/servers/' . SERVER_UUID . '/files/list-directory'
) {
    throw new RuntimeException('Unexpected Wings request URL: ' . $request['url']);
}

if (($request['options']['query'] ?? null) !== ['directory' => '/']) {
    throw new RuntimeException('Unexpected list-directory query.');
}

if (($request['options']['headers']['Authorization'] ?? null) !== 'Bearer ' . DAEMON_KEY) {
    throw new RuntimeException('Missing or incorrect Authorization header.');
}

if (($request['options']['headers']['Accept'] ?? null) !== 'application/json') {
    throw new RuntimeException('Missing Accept header.');
}

if (($request['options']['headers']['Content-Type'] ?? null) !== 'application/json') {
    throw new RuntimeException('Missing Content-Type header.');
}

$count++;
echo "PASS: wings target sends authenticated request to the resolved server UUID\n";

// 3: successful file existence checks (present and missing).
$transport = new FakeWingsTransport([
    ok(json_encode([])),
]);
$target = makeTarget($transport);

if ($target->exists('server.properties') !== false) {
    throw new RuntimeException('Missing file reported as existing.');
}

$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'server.properties', 'file' => true]])),
]);
$target = makeTarget($transport);

if ($target->exists('server.properties') !== true) {
    throw new RuntimeException('Existing file reported as missing.');
}

$count++;
echo "PASS: wings target file existence checks\n";

// 4: successful directory checks.
$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'mods', 'file' => false]])),
]);
$target = makeTarget($transport);

if (!$target->isDirectory('mods')) {
    throw new RuntimeException('Directory not detected.');
}

$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'server.properties', 'file' => true]])),
]);
$target = makeTarget($transport);

if ($target->isDirectory('server.properties')) {
    throw new RuntimeException('File detected as a directory.');
}

$count++;
echo "PASS: wings target directory checks\n";

// 5: successful file read.
$transport = new FakeWingsTransport([
    ok('file-contents'),
]);
$target = makeTarget($transport);

if ($target->read('config/server.properties') !== 'file-contents') {
    throw new RuntimeException('Read did not return the expected contents.');
}

$request = $transport->requests[0];

if (($request['options']['query'] ?? null) !== ['file' => 'config/server.properties']) {
    throw new RuntimeException('Unexpected file read query.');
}

$count++;
echo "PASS: wings target file read\n";

// 6: successful file write (with parent directory creation first).
$transport = new FakeWingsTransport([
    ok(json_encode([])),                       // stat target: list config
    ok(json_encode([])),                       // stat dir: list /
    new WingsTransportResponse(204, ''),       // create-directory config
    new WingsTransportResponse(204, ''),       // write file
    ok(json_encode([['name' => 'server.properties', 'file' => true]])),
]);
$target = makeTarget($transport);

$target->write('config/server.properties', 'new-data');

if (count($transport->requests) !== 4) {
    throw new RuntimeException('Unexpected number of requests during write.');
}

$create = $transport->requests[2];

if (($create['options']['json'] ?? null) !== ['name' => 'config', 'path' => '/']) {
    throw new RuntimeException('create-directory payload mismatch.');
}

$write = $transport->requests[3];

if ($write['method'] !== 'POST') {
    throw new RuntimeException('Expected a POST for write.');
}

if (($write['options']['query'] ?? null) !== ['file' => 'config/server.properties']) {
    throw new RuntimeException('Unexpected write query.');
}

if (($write['options']['body'] ?? null) !== 'new-data') {
    throw new RuntimeException('Write body mismatch.');
}

if (!$target->exists('config/server.properties')) {
    throw new RuntimeException('Written file not reported as existing.');
}

$count++;
echo "PASS: wings target file write creates parents and sends contents\n";

// 7: successful directory creation (nested).
$transport = new FakeWingsTransport([
    ok(json_encode([])),                       // stat a via root listing
    new WingsTransportResponse(204, ''),       // create a at /
    ok(json_encode([])),                       // stat a/b
    new WingsTransportResponse(204, ''),       // create b at a
    ok(json_encode([])),                       // stat a/b/c
    new WingsTransportResponse(204, ''),       // create c at a/b
    ok(json_encode([['name' => 'c', 'file' => false]])),
]);
$target = makeTarget($transport);

$target->ensureDirectory('a/b/c');

$expectedCreates = [
    ['name' => 'a', 'path' => '/'],
    ['name' => 'b', 'path' => 'a'],
    ['name' => 'c', 'path' => 'a/b'],
];

foreach ($expectedCreates as $index => $expected) {
    $request = $transport->requests[$index * 2 + 1];

    if ($request['method'] !== 'POST') {
        throw new RuntimeException('Expected a create-directory POST.');
    }

    if (($request['options']['json'] ?? null) !== $expected) {
        throw new RuntimeException(
            'create-directory payload mismatch: ' . json_encode($request['options']['json'] ?? null),
        );
    }
}

if (!$target->isDirectory('a/b/c')) {
    throw new RuntimeException('Nested directory not detected.');
}

$count++;
echo "PASS: wings target nested directory creation\n";

// Existing directory is a no-op.
$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'mods', 'file' => false]])),
]);
$target = makeTarget($transport);

$target->ensureDirectory('mods');

if (count($transport->requests) !== 1) {
    throw new RuntimeException('Existing directory should not trigger a create.');
}

$count++;
echo "PASS: wings target existing directory is a no-op\n";

// 8: successful file deletion and missing-file no-op.
$transport = new FakeWingsTransport([
    ok(json_encode([])),
]);
$target = makeTarget($transport);

$target->delete('old.txt');

if (count($transport->requests) !== 1) {
    throw new RuntimeException('Missing file delete should not issue a request.');
}

$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'old.txt', 'file' => true]])),
    new WingsTransportResponse(204, ''),
]);
$target = makeTarget($transport);

$target->delete('old.txt');

$request = $transport->requests[1];

if ($request['method'] !== 'POST') {
    throw new RuntimeException('Expected a delete POST.');
}

if (($request['options']['json'] ?? null) !== ['root' => '/', 'files' => ['old.txt']]) {
    throw new RuntimeException('delete payload mismatch.');
}

$count++;
echo "PASS: wings target file deletion\n";

$transport = new FakeWingsTransport([
    ok(json_encode([['name' => 'data', 'file' => false]])),
]);
$target = makeTarget($transport);

try {
    $target->delete('data');
    throw new RuntimeException('Directory deletion should have been rejected.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Cannot delete directory through file target: data') {
        throw new RuntimeException('Unexpected delete rejection message: ' . $exception->getMessage());
    }
}

$count++;
echo "PASS: wings target refuses directory deletion\n";

// 9: 401 and 403 error handling produce clear, secret-free exceptions.
foreach ([401, 403] as $status) {
    $transport = new FakeWingsTransport([
        new WingsTransportResponse($status, '{"error":"nope"}'),
    ]);
    $target = makeTarget($transport);

    try {
        $target->read('config/server.properties');
        throw new RuntimeException('Expected an exception for HTTP ' . $status . '.');
    } catch (WingsHttpException $exception) {
        if ($exception->getStatusCode() !== $status) {
            throw new RuntimeException('Unexpected status code.');
        }
        assertSecretFree($exception->getMessage(), $status);
    }
}

$count++;
echo "PASS: wings target handles 401/403 authentication errors\n";

// 10: 404 on existence check is just "missing", 404 on read is a clear error.
$transport = new FakeWingsTransport([
    new WingsTransportResponse(404, '{"error":"missing"}'),
]);
$target = makeTarget($transport);

if ($target->exists('config/server.properties') !== false) {
    throw new RuntimeException('404 during existence check should resolve to missing.');
}

$count++;
echo "PASS: wings target treats 404 as missing for existence checks\n";

$transport = new FakeWingsTransport([
    new WingsTransportResponse(404, '{"error":"missing"}'),
]);
$target = makeTarget($transport);

try {
    $target->read('config/server.properties');
    throw new RuntimeException('Expected a read failure.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'File does not exist')) {
        throw new RuntimeException('Unexpected read error message.');
    }
    if (!($exception->getPrevious() instanceof WingsFileNotFoundException)) {
        throw new RuntimeException('Missing underlying not-found exception.');
    }
}

$count++;
echo "PASS: wings target 404 file read surfaces a clear error\n";

// 11: 5xx error handling.
$transport = new FakeWingsTransport([
    new WingsTransportResponse(500, '{"error":"boom"}'),
]);
$target = makeTarget($transport);

try {
    $target->exists('server.properties');
    throw new RuntimeException('Expected an exception for HTTP 500.');
} catch (WingsHttpException $exception) {
    if ($exception->getStatusCode() !== 500) {
        throw new RuntimeException('Unexpected status code.');
    }
    assertSecretFree($exception->getMessage(), 500);
}

$count++;
echo "PASS: wings target handles 5xx node errors\n";

// 409 / 422 / 429 are mapped to a clear WingsHttpException with the status.
foreach ([409, 422, 429] as $status) {
    $transport = new FakeWingsTransport([
        new WingsTransportResponse($status, '{"error":"denied"}'),
    ]);
    $target = makeTarget($transport);

    try {
        $target->read('server.properties');
        throw new RuntimeException('Expected an exception for HTTP ' . $status . '.');
    } catch (WingsHttpException $exception) {
        if ($exception->getStatusCode() !== $status) {
            throw new RuntimeException('Unexpected status code.');
        }
        assertSecretFree($exception->getMessage(), $status);
    }
}

$count++;
echo "PASS: wings target maps 409/422/429 to clear errors\n";

// 12: network/timeout failure is a clear error and never a silent fallback.
$transport = new FakeWingsTransport();
$target = makeTarget($transport);

try {
    $target->exists('server.properties');
    throw new RuntimeException('Expected a connection exception.');
} catch (WingsConnectionException $exception) {
    assertSecretFree($exception->getMessage(), 'connection');
}

$count++;
echo "PASS: wings target network failure is a clear error with no fallback\n";

// 13: no token, address or UUID ever appears in exception messages.
function assertSecretFree(string $message, int|string $context): void
{
    foreach ([DAEMON_KEY, NODE_ADDRESS, SERVER_UUID] as $secret) {
        if (str_contains($message, $secret)) {
            throw new RuntimeException(
                'Secret leaked into a Wings exception message (' . $context . '): ' . $message,
            );
        }
    }
}

$count++;
echo "PASS: no secrets appear in wings exception messages\n";

// 14: the client cannot be pointed at an arbitrary URL, and relative paths are
// strictly validated before any request is issued.
$transport = new FakeWingsTransport([
    ok(json_encode([])),
]);
$target = makeTarget($transport);

foreach (['/etc/passwd', '../up', 'a/../b', "bad\0path", 'C:\\windows\\file'] as $badPath) {
    try {
        $target->exists($badPath);
        throw new RuntimeException('Path should have been rejected: ' . $badPath);
    } catch (InvalidArgumentException $exception) {
        // expected
    }
}

if ($transport->requests !== []) {
    throw new RuntimeException('No request should have been issued for invalid paths.');
}

$count++;
echo "PASS: wings target rejects unsafe relative paths before any request\n";

echo "\n{$count}/{$count} wings server file target tests passed.\n";