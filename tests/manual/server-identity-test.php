<?php

require_once __DIR__ . '/../../app/Services/Server/ServerIdentity.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;

$validUuid = '539fdca8-4a08-4551-a8d2-8ee5475b50d9';

$identity = ServerIdentity::fromUuid($validUuid);

if ($identity->uuid !== $validUuid) {
    throw new RuntimeException(
        'Server UUID was not stored correctly.'
    );
}

echo "PASS: server UUID stored correctly\n";

$secondIdentity = new ServerIdentity($validUuid);

if (!$identity->equals($secondIdentity)) {
    throw new RuntimeException(
        'Equivalent server identities were not equal.'
    );
}

echo "PASS: equivalent identities match\n";

$differentIdentity = ServerIdentity::fromUuid(
    '123e4567-e89b-42d3-a456-426614174000',
);

if ($identity->equals($differentIdentity)) {
    throw new RuntimeException(
        'Different server identities were treated as equal.'
    );
}

echo "PASS: different identities do not match\n";

$invalidUuids = [
    '',
    'not-a-uuid',
    '539fdca8-4a08-4551-a8d2',
    '539fdca8-4a08-6551-a8d2-8ee5475b50d9',
    '539fdca8-4a08-4551-c8d2-8ee5475b50d9',
    '539fdca8-4a08-4551-a8d2-8ee5475b50d90',
];

foreach ($invalidUuids as $invalidUuid) {
    try {
        new ServerIdentity($invalidUuid);

        throw new RuntimeException(
            "Invalid UUID was accepted: {$invalidUuid}"
        );
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "PASS: invalid UUIDs rejected\n";

echo "5/5 server identity tests passed.\n";
