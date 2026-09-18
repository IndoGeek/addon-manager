<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallRecord;
use RuntimeException;

require_once __DIR__ . '/../../app/Services/Server/ServerRelativePath.php';
require_once __DIR__ . '/../../app/Services/Installation/ContentInstallTarget.php';
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
    iconUrl: 'https://cdn.modrinth.com/pack-a.png',
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
    || $roundTripped->iconUrl !== $record->iconUrl
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

if ($unpinned->iconUrl !== null) {
    throw new RuntimeException('Legacy records must hydrate with a null icon url.');
}

echo "PASS: legacy records hydrate with a null icon url.\n";

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
        iconUrl: null,
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

// ── Content kind ──────────────────────────────────────────────────────

// A record written with an explicit kind round trips it.
$datapackRecord = new InstallRecord(
    id: str_repeat('b', 32),
    serverUuid: 'server-aaa',
    provider: 'modrinth',
    projectId: 'terralith',
    versionId: 'v1',
    source: 'modrinth://terralith@v1',
    displayName: 'Terralith',
    version: '2.5',
    minecraftVersion: '1.21.1',
    loader: 'datapack',
    iconUrl: null,
    installedAt: '2026-01-01T00:00:00+00:00',
    updatedAt: '2026-01-01T00:00:00+00:00',
    status: InstallRecord::STATUS_INSTALLED,
    createdFiles: ['world/datapacks/terralith-datapack-1-21-1.zip'],
    overwrittenFiles: [],
    contentType: InstallRecord::TYPE_CONTENT,
    contentKind: 'datapack',
);

if (
    InstallRecord::fromArray($datapackRecord->toArray())->contentKind
    !== 'datapack'
) {
    throw new RuntimeException('Content kind did not round trip.');
}

echo "PASS: content kind round trips.\n";

// Records written before the kind existed infer it from where the files were placed.
$legacyKinds = [
    'mods/a.jar' => 'mod',
    'plugins/vault.jar' => 'plugin',
    'world/datapacks/pack.zip' => 'datapack',
    'resourcepacks/pack.zip' => 'resourcepack',
    'shaderpacks/pack.zip' => 'shader',
];

foreach ($legacyKinds as $path => $expectedKind) {
    $legacy = InstallRecord::fromArray([
        'id' => str_repeat('c', 32),
        'server_uuid' => 'server-aaa',
        'provider' => 'modrinth',
        'project_id' => 'legacy',
        'source' => 'modrinth://legacy@v1',
        'display_name' => 'Legacy',
        'version' => '1.0.0',
        'installed_at' => '2026-01-01T00:00:00+00:00',
        'updated_at' => '2026-01-01T00:00:00+00:00',
        'content_type' => 'content',
        'ownership' => ['created' => [$path], 'overwritten' => []],
    ]);

    if ($legacy->contentKind !== $expectedKind) {
        throw new RuntimeException(
            "Legacy record at {$path} must report {$expectedKind}, got "
                . $legacy->contentKind . '.',
        );
    }
}

echo "PASS: legacy records infer their kind from the installed path.\n";

// A modpack record without a stored kind stays a modpack.
if ($roundTripped->contentKind !== 'modpack') {
    throw new RuntimeException('Modpack records must report the modpack kind.');
}

echo "PASS: modpack records report the modpack kind.\n";

// An unrecognized stored kind falls back to inference instead of a label nobody understands.
$unknown = InstallRecord::fromArray([
    'id' => str_repeat('d', 32),
    'server_uuid' => 'server-aaa',
    'provider' => 'modrinth',
    'project_id' => 'weird',
    'source' => 'modrinth://weird@v1',
    'display_name' => 'Weird',
    'version' => '1.0.0',
    'installed_at' => '2026-01-01T00:00:00+00:00',
    'updated_at' => '2026-01-01T00:00:00+00:00',
    'content_type' => 'content',
    'content_kind' => 'executable',
    'ownership' => [
        'created' => ['plugins/weird.jar'],
        'overwritten' => [],
    ],
]);

if ($unknown->contentKind !== 'plugin') {
    throw new RuntimeException('An unknown stored kind must be inferred.');
}

echo "PASS: unrecognized content kinds fall back to inference.\n";