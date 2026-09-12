<?php

require __DIR__ . '/../../app/Services/Download/Downloader.php';
require __DIR__ . '/../../app/Services/Download/DownloadManager.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;

$temporaryRoot = sys_get_temp_dir() . '/modpack-download-test';

$manager = new DownloadManager(
    $temporaryRoot,
    1024 * 1024,
);

$tests = [
    'empty URL' => [
        'url' => '',
        'shouldReject' => true,
    ],
    'ftp scheme' => [
        'url' => 'ftp://example.com/modpack.zip',
        'shouldReject' => true,
    ],
    'localhost' => [
        'url' => 'http://localhost/modpack.zip',
        'shouldReject' => true,
    ],
    'loopback IPv4' => [
        'url' => 'http://127.0.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'private IPv4' => [
        'url' => 'http://10.0.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'private IPv4 2' => [
        'url' => 'http://192.168.1.1/modpack.zip',
        'shouldReject' => true,
    ],
    'link-local IPv4' => [
        'url' => 'http://169.254.169.254/modpack.zip',
        'shouldReject' => true,
    ],
    'loopback IPv6' => [
        'url' => 'http://[::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'private IPv6' => [
        'url' => 'http://[fc00::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'CGNAT IPv4' => [
        'url' => 'http://100.64.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'shared address space IPv4' => [
        'url' => 'http://192.0.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'documentation IPv4' => [
        'url' => 'http://192.0.2.1/modpack.zip',
        'shouldReject' => true,
    ],
    'benchmark IPv4' => [
        'url' => 'http://198.18.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'documentation IPv4 2' => [
        'url' => 'http://198.51.100.1/modpack.zip',
        'shouldReject' => true,
    ],
    'documentation IPv4 3' => [
        'url' => 'http://203.0.113.1/modpack.zip',
        'shouldReject' => true,
    ],
    'multicast IPv4' => [
        'url' => 'http://224.0.0.1/modpack.zip',
        'shouldReject' => true,
    ],
    'link-local IPv6' => [
        'url' => 'http://[fe80::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'multicast IPv6' => [
        'url' => 'http://[ff02::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'NAT64 prefix IPv6' => [
        'url' => 'http://[64:ff9b::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'benchmark IPv6' => [
        'url' => 'http://[2001:db8::1]/modpack.zip',
        'shouldReject' => true,
    ],
    'IPv4-mapped loopback IPv6' => [
        'url' => 'http://[::ffff:127.0.0.1]/modpack.zip',
        'shouldReject' => true,
    ],
    'credentials in URL' => [
        'url' => 'http://user:pass@example.com/modpack.zip',
        'shouldReject' => true,
    ],
    'port out of range' => [
        'url' => 'http://example.com:70000/modpack.zip',
        'shouldReject' => true,
    ],
];

$passed = 0;

foreach ($tests as $name => $test) {
    try {
        $manager->download($test['url']);

        $rejected = false;
    } catch (Throwable) {
        $rejected = true;
    }

    if ($rejected === $test['shouldReject']) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
    }
}

$leftovers = [];

if (is_dir($temporaryRoot)) {
    $entries = scandir($temporaryRoot);

    foreach ($entries ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $leftovers[] = $entry;
        }
    }
}

if ($leftovers !== []) {
    throw new RuntimeException(
        'Rejected downloads left temporary files: '
        . implode(',', $leftovers),
    );
}

echo "PASS: no temporary files left behind after rejection\n";
$passed++;

echo "\n{$passed}/" . (count($tests) + 1) . " tests passed.\n";

if ($passed !== count($tests) + 1) {
    exit(1);
}