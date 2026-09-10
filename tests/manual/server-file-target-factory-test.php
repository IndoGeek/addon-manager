<?php

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;

require_once __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/ServerIdentity.php';
require_once __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/ServerFileTargetFactory.php';

$serverRoot = sys_get_temp_dir() . '/modpack-installer-factory-test-' . bin2hex(random_bytes(4));

if (!mkdir($serverRoot, 0750, true) && !is_dir($serverRoot)) {
    throw new RuntimeException('Unable to create temporary server root.');
}

try {
    $server = ServerIdentity::fromUuid(
        '539fdca8-4a08-4551-a8d2-8ee5475b50d9'
    );

    $factory = new ServerFileTargetFactory($serverRoot);

    $target = $factory->forServer($server);

    if (!$target instanceof ServerFileTarget) {
        throw new RuntimeException(
            'Factory did not return a ServerFileTarget.'
        );
    }

    echo "✓ Factory returns ServerFileTarget\n";

    if (!$target instanceof LocalFilesystemServerFileTarget) {
        throw new RuntimeException(
            'Factory did not return LocalFilesystemServerFileTarget.'
        );
    }

    echo "✓ Factory returns local filesystem target\n";

    $target->write('factory-test.txt', 'factory works');

    if (!$target->exists('factory-test.txt')) {
        throw new RuntimeException(
            'Factory-created target cannot access the server root.'
        );
    }

    echo "✓ Factory-created target works\n";

    if ($target->read('factory-test.txt') !== 'factory works') {
        throw new RuntimeException(
            'Factory-created target returned unexpected file contents.'
        );
    }

    echo "✓ Factory-created target reads files correctly\n";

    $secondServer = ServerIdentity::fromUuid(
        '123e4567-e89b-42d3-a456-426614174000'
    );

    $secondTarget = $factory->forServer($secondServer);

    if (!$secondTarget instanceof ServerFileTarget) {
        throw new RuntimeException(
            'Factory failed for a second server identity.'
        );
    }

    echo "✓ Factory accepts server identities\n";

    echo "\n5/5 tests passed.\n";
} finally {
    if (is_file($serverRoot . '/factory-test.txt')) {
        unlink($serverRoot . '/factory-test.txt');
    }

    if (is_dir($serverRoot)) {
        rmdir($serverRoot);
    }
}
