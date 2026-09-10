<?php

require __DIR__ . '/../../app/Services/Download/DownloadManager.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;

$manager = new DownloadManager(
    sys_get_temp_dir() . '/modpack-download-test',
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

echo "\n{$passed}/" . count($tests) . " tests passed.\n";

if ($passed !== count($tests)) {
    exit(1);
}
