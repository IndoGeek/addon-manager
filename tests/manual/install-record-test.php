<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use RuntimeException;

require_once __DIR__ . '/../../app/Services/Server/ServerRelativePath.php';
require_once __DIR__ . '/../../app/Services/Management/InstallRecord.php';

$record = new InstallRecord(
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
    layout: 'direct',
    policy: 'overwrite',
    installedAt: '2026-01-01T00:00:00+00:00',
    updatedAt: '2026-01-01T00:00:00+00:00',
    status: InstallRecord::STATUS_INSTALLED,
    createdFiles: ['mods/a.jar', 'overrides/mods/b.jar'],
    overwrittenFiles: ['config/shared.txt', 'mods/a.jar'],
);

$roundTripped = InstallRecord::fromArray($record->toArray());

if (
    $roundTripped->id !== $record->id
    || $roundTripped->serverUuid !== $record->serverUuid
    || $roundTripped->source !== $record->source
    || $roundTripped->displayName !== $record->displayName
    || $roundTripped->version !== $record->version
    || $roundTripped->minecraftVersion !== $record->minecraftVersion
    || $roundTripped->loader !== $record->loader
    || $roundTripped->layout !== $record->layout
    || $roundTripped->policy !== $record->policy
) {
    throw new RuntimeException('Record did not round trip.');
}

echo "PASS: record.toArray/fromArray round trips.\n";

if (
    $roundTripped->createdFiles !== $record->createdFiles
    || $roundTripped->overwrittenFiles !== $record->overwrittenFiles
) {
    throw new RuntimeException('Ownership did not round trip.');
}

echo "PASS: ownership round trips.\n";

if (
    $roundTripped->ownedFiles()
    !== ['mods/a.jar', 'overrides/mods/b.jar', 'config/shared.txt']
) {
    throw new RuntimeException('Unexpected owned file set.');
}

echo "PASS: ownedFiles merges created and overwritten uniquely.\n";

if ($roundTripped->versionId !== 'v1') {
    throw new RuntimeException('Version id did not round trip.');
}

echo "PASS: nullable version id round trips.\n";

$unpinned = InstallRecord::fromArray([
    'id' => str_repeat('b', 32),
    'server_uuid' => 'server-aaa',
    'provider' => 'curseforge',
    'project_id' => 'pack-b',
    'version_id' => null,
    'source' => 'curseforge://pack-b',
    'display_name' => 'Pack B',
    'version' => '',
    'layout' => 'direct',
    'policy' => 'merge',
    'installed_at' => '2026-01-01T00:00:00+00:00',
    'updated_at' => '2026-01-01T00:00:00+00:00',
    'status' => InstallRecord::STATUS_INSTALLED,
    'ownership' => ['created' => ['mods/b.jar'], 'overwritten' => []],
]);

if ($unpinned->versionId !== null || $unpinned->version !== '') {
    throw new RuntimeException('Unpinned record fields are wrong.');
}

echo "PASS: unpinned record hydrates with null version id.\n";

$hostile = InstallRecord::fromArray([
    'id' => str_repeat('c', 32),
    'server_uuid' => 'server-aaa',
    'provider' => 'modrinth',
    'project_id' => 'pack-c',
    'source' => 'modrinth://pack-c',
    'display_name' => 'Pack C',
    'version' => '1.0.0',
    'layout' => 'direct',
    'policy' => 'overwrite',
    'installed_at' => '2026-01-01T00:00:00+00:00',
    'updated_at' => '2026-01-01T00:00:00+00:00',
    'status' => InstallRecord::STATUS_INSTALLED,
    'ownership' => [
        'created' => ['../escape.txt', '/etc/passwd', 'mods/ok.jar', ''],
        'overwritten' => ['../../../../etc/shadow'],
    ],
]);

if (
    $hostile->ownedFiles()
    !== ['mods/ok.jar']
) {
    throw new RuntimeException(
        'Hostile ownership paths must be dropped on hydration.'
    );
}

echo "PASS: hostile ownership paths are dropped on hydration.\n";

try {
    new InstallRecord(
        id: '',
        serverUuid: 'server-aaa',
        provider: 'modrinth',
        projectId: 'pack',
        versionId: null,
        source: 'modrinth://pack',
        displayName: 'Pack',
        version: '1.0.0',
        minecraftVersion: null,
        loader: null,
        layout: 'direct',
        policy: 'overwrite',
        installedAt: '2026-01-01T00:00:00+00:00',
        updatedAt: '2026-01-01T00:00:00+00:00',
        status: InstallRecord::STATUS_INSTALLED,
        createdFiles: [],
        overwrittenFiles: [],
    );

    throw new RuntimeException('Empty record id must be rejected.');
} catch (\InvalidArgumentException) {
    // Expected.
}

echo "PASS: empty record id is rejected.\n";