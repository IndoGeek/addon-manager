<?php

require __DIR__ . '/../../app/Services/Provider/ProviderHttpException.php';
require __DIR__ . '/../../app/Services/Provider/ProviderHttpResponse.php';
require __DIR__ . '/../../app/Services/Provider/ProviderHttpClient.php';
require __DIR__ . '/../../app/Services/Provider/CurlProviderHttpClient.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\CurlProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

// A tiny PHP built-in server lets the HTTP client be exercised hermeticly on
// the loopback interface (the provider client performs no SSRF filtering, so
// loopback addresses are reachable here by design).
$router = sys_get_temp_dir()
    . '/modpack-provider-router-'
    . bin2hex(random_bytes(8))
    . '.php';

file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/ok') {
    header('Content-Type: application/json');
    echo '{"hello":"world"}';
    return;
}

if ($path === '/big') {
    header('Content-Type: application/octet-stream');
    echo str_repeat('x', 20 * 1024 * 1024);
    return;
}

http_response_code(404);
echo '{}';
PHP);

$port = random_int(20000, 59999);

$process = proc_open(
    [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        $router,
    ],
    [],
    $pipes,
);

if (!is_resource($process)) {
    throw new RuntimeException('Unable to start the test HTTP server.');
}

try {
    $ready = false;

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }

        usleep(50_000);
    }

    if (!$ready) {
        throw new RuntimeException('Test HTTP server did not become ready.');
    }

    $base = 'http://127.0.0.1:' . $port;

    $client = new CurlProviderHttpClient(maxResponseBytes: 4096);

    $response = $client->get($base . '/ok');

    if ($response->status !== 200) {
        throw new RuntimeException('Unexpected OK status.');
    }

    if (($response->body['hello'] ?? null) !== 'world') {
        throw new RuntimeException('Unexpected JSON body.');
    }

    echo "PASS: small JSON response parsed\n";

    try {
        $client->get($base . '/big');
        throw new RuntimeException('Oversized response was not rejected.');
    } catch (ProviderHttpException $exception) {
        if (!str_contains($exception->getMessage(), 'too large')) {
            throw new RuntimeException(
                'Unexpected oversize error message: '
                . $exception->getMessage(),
            );
        }
    }

    echo "PASS: oversized response rejected\n";

    try {
        $client->get('ftp://127.0.0.1' . ':' . $port . '/ok');
        throw new RuntimeException('FTP URL was not rejected.');
    } catch (InvalidArgumentException) {
        echo "PASS: non-HTTP provider URL rejected\n";
    }

    try {
        $client->get('http://user:pass@127.0.0.1:' . $port . '/ok');
        throw new RuntimeException('Credentialed URL was not rejected.');
    } catch (InvalidArgumentException) {
        echo "PASS: credentialed provider URL rejected\n";
    }
} finally {
    proc_terminate($process);
    @unlink($router);
}

echo "\nAll provider HTTP limit tests passed.\n";