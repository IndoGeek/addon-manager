<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecordStore;
use RuntimeException;

require_once __DIR__ . '/../../app/Services/Server/ServerRelativePath.php';
require_once __DIR__ . '/../../app/Services/Installation/ContentInstallTarget.php';
require_once __DIR__ . '/../../app/Services/Management/InstallRecord.php';
require_once __DIR__ . '/../../app/Services/Management/InstallRecordStore.php';

$directory = sys_get_temp_dir()
    . '/modpack-installer-store-'
    . bin2hex(random_bytes(8));

if (!mkdir($directory, 0750, true)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    $store = new InstallRecordStore($directory);

    $recordA = new InstallRecord(
        id: str_repeat('a', 32),
        serverUuid: 'server-aaa',
        provider: 'modrinth',
        projectId: 'pack-a',
        versionId: 'v1',
        source: 'modrinth://pack-a@v1',
        displayName: 'Pack A',
        version: '1.0.0',
        minecraftVersion: '1.20.1',
        loader: 'fabric',
        iconUrl: 'https://cdn.example/pack-a.png',
        installedAt: '2026-01-01T00:00:00+00:00',
        updatedAt: '2026-01-01T00:00:00+00:00',
        status: InstallRecord::STATUS_INSTALLED,
        createdFiles: ['mods/a.jar', 'config/a.toml'],
        overwrittenFiles: ['config/shared.txt'],
    );

    $store->save($recordA);

    if ($store->find('server-aaa', str_repeat('a', 32)) === null) {
        throw new RuntimeException('Saved record was not found.');
    }

    if ($store->find('server-aaa', str_repeat('b', 32)) !== null) {
        throw new RuntimeException('Foreign id must not be found.');
    }

    if ($store->find('server-bbb', str_repeat('a', 32)) !== null) {
        throw new RuntimeException('Other-server record must not be found.');
    }

    if ($store->findOneBySource('server-aaa', 'modrinth://pack-a@v1') === null) {
        throw new RuntimeException('Record was not found by source.');
    }

    $records = $store->all('server-aaa');

    if (count($records) !== 1) {
        throw new RuntimeException(
            'Unexpected record count for server-aaa.'
        );
    }

    if ($store->all('server-bbb') !== []) {
        throw new RuntimeException(
            'Empty server must have no records.'
        );
    }

    echo "PASS: store returns created record.\n";

    $recordB = new InstallRecord(
        id: str_repeat('c', 32),
        serverUuid: 'server-aaa',
        provider: 'curseforge',
        projectId: 'pack-b',
        versionId: null,
        source: 'curseforge://pack-b',
        displayName: 'Pack B',
        version: '2.1.0',
        minecraftVersion: '1.21',
        loader: null,
        iconUrl: null,
        installedAt: '2026-02-01T00:00:00+00:00',
        updatedAt: '2026-02-01T00:00:00+00:00',
        status: InstallRecord::STATUS_INSTALLED,
        createdFiles: ['mods/b.jar'],
        overwrittenFiles: [],
    );

    $store->save($recordB);

    $records = $store->all('server-aaa');

    if (count($records) !== 2) {
        throw new RuntimeException(
            'Expected two records for server-aaa.'
        );
    }

    if ($records[0]->id !== str_repeat('c', 32)) {
        throw new RuntimeException(
            'Records must be returned newest first.'
        );
    }

    echo "PASS: store lists records newest first.\n";

    $store->save($recordB);

    $records = $store->all('server-aaa');

    if (count($records) !== 2) {
        throw new RuntimeException(
            'Re-save must not duplicate a record.'
        );
    }

    echo "PASS: store upserts without duplicating.\n";

    $store->delete('server-aaa', str_repeat('c', 32));

    if ($store->find('server-aaa', str_repeat('c', 32)) !== null) {
        throw new RuntimeException(
            'Deleted record must not be found.'
        );
    }

    if ($store->find('server-aaa', str_repeat('a', 32)) === null) {
        throw new RuntimeException(
            'Unrelated record must be preserved after delete.'
        );
    }

    echo "PASS: store deletes one record only.\n";

    $recordC = new InstallRecord(
        id: str_repeat('d', 32),
        serverUuid: 'server-ccc',
        provider: 'modrinth',
        projectId: 'pack-c',
        versionId: 'v9',
        source: 'modrinth://pack-c@v9',
        displayName: 'Pack C',
        version: '9.0.0',
        minecraftVersion: '1.21.1',
        loader: 'forge',
        iconUrl: null,
        installedAt: '2026-03-01T00:00:00+00:00',
        updatedAt: '2026-03-01T00:00:00+00:00',
        status: InstallRecord::STATUS_INSTALLED,
        createdFiles: ['mods/c.jar'],
        overwrittenFiles: [],
    );

    $store->save($recordC);

    if ($store->all('server-bbb') !== []) {
        throw new RuntimeException(
            'Unrelated servers must stay isolated.'
        );
    }

    echo "PASS: store isolates servers.\n";

    $reopened = new InstallRecordStore($directory);

    $persisted = $reopened->find('server-aaa', str_repeat('a', 32));

    if ($persisted === null) {
        throw new RuntimeException(
            'Record did not survive a reopen of the store.'
        );
    }

    if ($persisted->ownedFiles()
        !== ['mods/a.jar', 'config/a.toml', 'config/shared.txt']
    ) {
        throw new RuntimeException(
            'Owned files did not survive a round trip.'
        );
    }

    echo "PASS: store persists across reopen.\n";

    $guardDirectory = $directory . '/guarded';

    if (!mkdir($guardDirectory, 0750, true)) {
        throw new RuntimeException('Unable to create guarded directory.');
    }

    $guardFile = $guardDirectory . '/unrelated.txt';

    if (@file_put_contents($guardFile, 'keep') === false) {
        throw new RuntimeException('Unable to write guard file.');
    }

    if (is_file($guardFile) && file_get_contents($guardFile) !== 'keep') {
        throw new RuntimeException('Guard file was modified.');
    }

    echo "PASS: store left unrelated files untouched.\n";

    $corruptPath = $directory . '/installs.json';
    $corruptStore = new InstallRecordStore($directory);

    $corruptStore->save($recordA);

    if (@file_put_contents($corruptPath, '{not-json') === false) {
        throw new RuntimeException('Unable to corrupt store.');
    }

    try {
        $corruptStore->all('server-aaa');
        throw new RuntimeException(
            'Corrupted store must be rejected.'
        );
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'corrupted') === false) {
            throw new RuntimeException(
                'Unexpected corruption message: ' . $exception->getMessage()
            );
        }
    }

    echo "PASS: store rejects a corrupted file.\n";
} finally {
    @unlink($directory . '/installs.json.lock');
    @unlink($directory . '/installs.json');
    @rmdir($directory . '/guarded');

    foreach (glob($directory . '/*') ?: [] as $leftover) {
        if (is_file($leftover)) {
            @unlink($leftover);
        }
    }

    @rmdir($directory);
}