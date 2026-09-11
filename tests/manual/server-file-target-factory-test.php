<?php

require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/ServerIdentity.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTargetFactory.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;

$root = sys_get_temp_dir()
    . '/modpack-server-factory-'
    . bin2hex(random_bytes(8));

$serverA = $root . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$serverB = $root . '/bbbbbbbb-bbbb-4bbb-9bbb-bbbbbbbbbbbb';

mkdir($serverA, 0750, true);
mkdir($serverB, 0750, true);

file_put_contents($serverA . '/server.txt', 'server-a');
file_put_contents($serverB . '/server.txt', 'server-b');

$factory = new ServerFileTargetFactory($root);

$identityA = ServerIdentity::fromUuid(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
);

$identityB = ServerIdentity::fromUuid(
    'bbbbbbbb-bbbb-4bbb-9bbb-bbbbbbbbbbbb',
);

$targetA = $factory->forServer($identityA);
$targetB = $factory->forServer($identityB);

if ($targetA->read('server.txt') !== 'server-a') {
    throw new RuntimeException(
        'Server A target resolved to the wrong server root.'
    );
}

echo "PASS: server A target resolved correctly\n";

if ($targetB->read('server.txt') !== 'server-b') {
    throw new RuntimeException(
        'Server B target resolved to the wrong server root.'
    );
}

echo "PASS: server B target resolved correctly\n";

if ($targetA->read('server.txt') === $targetB->read('server.txt')) {
    throw new RuntimeException(
        'Different server identities resolved to the same target.'
    );
}

echo "PASS: server targets are isolated\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ),
    RecursiveIteratorIterator::CHILD_FIRST,
);

foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}

rmdir($root);

echo "3/3 server factory tests passed.\n";
