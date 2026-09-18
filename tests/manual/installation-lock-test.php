<?php

require __DIR__ . '/../../app/Services/Installation/InstallationLockedException.php';
require __DIR__ . '/../../app/Services/Installation/InstallationLock.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLock;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationLockedException;

$temporaryRoot = sys_get_temp_dir()
    . '/modpack-lock-test-'
    . bin2hex(random_bytes(8));

$lock = new InstallationLock(
    temporaryRoot: $temporaryRoot,
    acquireTimeoutSeconds: 2,
    staleTimeoutSeconds: 900,
);

$serverId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

$first = $lock->acquire($serverId, 'token-one');

if (!is_dir($first)) {
    throw new RuntimeException('Lock directory was not created.');
}

echo "PASS: first lock acquired\n";

try {
    $lock->acquire($serverId, 'token-two');
    throw new RuntimeException('Second lock was not rejected.');
} catch (InstallationLockedException) {
    echo "PASS: concurrent acquisition rejected\n";
}

try {
    $lock->release($first, 'wrong-token');
    throw new RuntimeException('Release with wrong token was not rejected.');
} catch (InstallationLockedException) {
    echo "PASS: release with wrong token rejected\n";
}

if (!is_dir($first)) {
    throw new RuntimeException('Wrong-token release removed the lock.');
}

$lock->release($first, 'token-one');

if (is_dir($first)) {
    throw new RuntimeException('Lock was not released.');
}

echo "PASS: lock released with matching token\n";

// A lock left behind must stop a fresh acquire but survive release attempts from a token it does not hold.
mkdir($first, 0750, true);

try {
    $lock->acquire($serverId, 'other');
    throw new RuntimeException(
        'Acquire after manual re-creation was not rejected.',
    );
} catch (InstallationLockedException) {
    echo "PASS: orphaned fresh lock blocks acquisition\n";
}

// Simulate a stale lock that has aged past the stale window.
touch($first, time() - 1800);

$reacquired = $lock->acquire($serverId, 'fresh-token');

if ($reacquired !== $first) {
    throw new RuntimeException('Stale lock path differs from re-acquired path.');
}

echo "PASS: stale lock reclaimed and re-acquired\n";

$lock->release($first, 'fresh-token');

if (is_dir($first)) {
    throw new RuntimeException('Re-acquired lock was not released.');
}

echo "PASS: re-acquired lock released\n";

// Invalid server identifiers are rejected before any filesystem work.
try {
    $lock->acquire('../escape', 'token');
    throw new RuntimeException('Invalid identifier was accepted.');
} catch (InvalidArgumentException) {
    echo "PASS: invalid identifier rejected\n";
}

try {
    $lock->acquire($serverId, '');
    throw new RuntimeException('Empty token was accepted.');
} catch (InvalidArgumentException) {
    echo "PASS: empty token rejected\n";
}

// Different servers never contend on the same lock.
$other = $lock->acquire(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'token-b',
);
$lock->release($other, 'token-b');

echo "PASS: different servers lock independently\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $temporaryRoot,
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

rmdir($temporaryRoot);

echo "\nAll installation lock tests passed.\n";