<?php

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerTargetResolver;

require_once __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/ServerIdentity.php';
require_once __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/ServerFileTargetFactory.php';
require_once __DIR__ . '/../../app/Services/Server/ServerTargetResolver.php';

$serverRoot = sys_get_temp_dir()
    . '/modpack-installer-resolver-test-'
    . bin2hex(random_bytes(4));

if (!mkdir($serverRoot, 0750, true) && !is_dir($serverRoot)) {
    throw new RuntimeException(
        'Unable to create temporary server root.'
    );
}

try {
    $server = ServerIdentity::fromUuid(
        '539fdca8-4a08-4551-a8d2-8ee5475b50d9'
    );

    $factory = new ServerFileTargetFactory($serverRoot);
    $resolver = new ServerTargetResolver($factory);

    $target = $resolver->resolve($server);

    if (!$target instanceof ServerFileTarget) {
        throw new RuntimeException(
            'Resolver did not return a ServerFileTarget.'
        );
    }

    echo "✓ Resolver returns ServerFileTarget\n";

    if (!$target instanceof LocalFilesystemServerFileTarget) {
        throw new RuntimeException(
            'Resolver did not return LocalFilesystemServerFileTarget.'
        );
    }

    echo "✓ Resolver uses factory-created local target\n";

    $target->write('resolver-test.txt', 'resolver works');

    if (!$target->exists('resolver-test.txt')) {
        throw new RuntimeException(
            'Resolver-created target cannot access server root.'
        );
    }

    echo "✓ Resolver-created target works\n";

    if ($target->read('resolver-test.txt') !== 'resolver works') {
        throw new RuntimeException(
            'Resolver-created target returned unexpected contents.'
        );
    }

    echo "✓ Resolver-created target reads correctly\n";

    $secondServer = ServerIdentity::fromUuid(
        '123e4567-e89b-42d3-a456-426614174000'
    );

    $secondTarget = $resolver->resolve($secondServer);

    if (!$secondTarget instanceof ServerFileTarget) {
        throw new RuntimeException(
            'Resolver failed for a second server identity.'
        );
    }

    echo "✓ Resolver accepts server identities\n";

    echo "\n5/5 tests passed.\n";
} finally {
    $testFile = $serverRoot . '/resolver-test.txt';

    if (is_file($testFile)) {
        unlink($testFile);
    }

    if (is_dir($serverRoot)) {
        rmdir($serverRoot);
    }
}
