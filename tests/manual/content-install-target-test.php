<?php

// Tests for the single-file content install targets: - every content type maps to its own server directory (dat...

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix =
        'Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $projectRoot
        . '/app/'
        . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\ContentInstallTarget;

function passTarget(string $name): void
{
    echo "PASS: {$name}\n";
}

// ── Directory mapping ─────────────────────────────────────────────────

$expected = [
    'mod' => 'mods',
    'plugin' => 'plugins',
    'datapack' => 'world/datapacks',
    'resourcepack' => 'resourcepacks',
    'shader' => 'shaderpacks',
];

foreach ($expected as $type => $directory) {
    $target = ContentInstallTarget::for($type);

    if ($target['directory'] !== $directory) {
        throw new RuntimeException(
            "{$type} must install into {$directory}, got "
                . $target['directory'] . '.',
        );
    }

    if ($target['extensions'] === []) {
        throw new RuntimeException("{$type} has no allowed extensions.");
    }
}

passTarget('every content type maps to its own server directory');

if (ContentInstallTarget::for('datapack')['directory'] !== 'world/datapacks') {
    throw new RuntimeException('Datapacks must land in world/datapacks.');
}

passTarget('datapacks install into world/datapacks');

// The default content type stays a mod for older clients.
if (ContentInstallTarget::DEFAULT_TYPE !== 'mod') {
    throw new RuntimeException('The default content type must stay "mod".');
}

if (!ContentInstallTarget::supports('mod')
    || !ContentInstallTarget::supports('shader')
) {
    throw new RuntimeException('Known content types must be supported.');
}

if (ContentInstallTarget::supports('world-texture')) {
    throw new RuntimeException('Unknown content types must be rejected.');
}

try {
    ContentInstallTarget::for('executable');
    throw new RuntimeException('Unsupported content type accepted.');
} catch (InvalidArgumentException) {
    passTarget('unsupported content type rejected');
}

// ── Filename encoding ─────────────────────────────────────────────────

$modName = ContentInstallTarget::filename(
    'sodium-fabric-0.6.0.jar',
    "Xaero's Minimap",
    '1.20.1',
    'fabric',
    ContentInstallTarget::for('mod')['extensions'],
);

if ($modName !== 'xaero-s-minimap-fabric-1-20-1.jar') {
    throw new RuntimeException("Unexpected mod filename: {$modName}");
}

passTarget('mod filename encodes name, loader and Minecraft version');

$pluginName = ContentInstallTarget::filename(
    'EssentialsX-2.20.1.jar',
    'EssentialsX',
    '1.21',
    'paper',
    ContentInstallTarget::for('plugin')['extensions'],
);

if ($pluginName !== 'essentialsx-paper-1-21.jar') {
    throw new RuntimeException("Unexpected plugin filename: {$pluginName}");
}

passTarget('plugin filename keeps the jar extension');

$datapackName = ContentInstallTarget::filename(
    'terralith-2.5.zip',
    'Terralith',
    '1.21.1',
    'datapack',
    ContentInstallTarget::for('datapack')['extensions'],
);

if ($datapackName !== 'terralith-datapack-1-21-1.zip') {
    throw new RuntimeException("Unexpected datapack filename: {$datapackName}");
}

passTarget('datapack filename keeps the zip extension');

$shaderName = ContentInstallTarget::filename(
    'ComplementaryReimagined_r5.5.zip',
    'Complementary Shaders',
    '1.21.1',
    'iris',
    ContentInstallTarget::for('shader')['extensions'],
);

if ($shaderName !== 'complementary-shaders-iris-1-21-1.zip') {
    throw new RuntimeException("Unexpected shader filename: {$shaderName}");
}

passTarget('shader filename keeps the zip extension');

// An upstream extension outside the content type's allow-list falls back to its primary one instead of leaking ...
$forced = ContentInstallTarget::filename(
    'pack.jar',
    'Some Pack',
    '1.20.1',
    'minecraft',
    ContentInstallTarget::for('resourcepack')['extensions'],
);

if ($forced !== 'some-pack-minecraft-1-20-1.zip') {
    throw new RuntimeException("Unexpected fallback filename: {$forced}");
}

passTarget('disallowed extension falls back to the content type default');

// A missing loader/Minecraft version still yields a usable filename.
$bare = ContentInstallTarget::filename(
    'Mod.jar',
    'Just A Mod',
    null,
    null,
    ContentInstallTarget::for('mod')['extensions'],
);

if ($bare !== 'just-a-mod.jar') {
    throw new RuntimeException("Unexpected bare filename: {$bare}");
}

passTarget('filename works without a loader or Minecraft version');

// ── Dependency destinations ───────────────────────────────────────────

$dependencies = [
    // [main type, dependency file, expected directory]
    ['mod', 'fabric-api-0.92.0.jar', 'mods'],
    ['plugin', 'Vault.jar', 'plugins'],
    ['datapack', 'terralith-2.5.zip', 'world/datapacks'],
    ['resourcepack', 'better-leaves.zip', 'resourcepacks'],
    ['shader', 'shader-pack.zip', 'shaderpacks'],
    // Cross-type dependencies stay usable: jars belong with mods.
    ['shader', 'iris-1.7.3.jar', 'mods'],
    ['datapack', 'fabric-api-0.92.0.jar', 'mods'],
    ['resourcepack', 'sodium-0.6.0.jar', 'mods'],
];

foreach ($dependencies as [$mainType, $dependencyFile, $expectedDirectory]) {
    $resolved = ContentInstallTarget::forDependency(
        ContentInstallTarget::for($mainType),
        $dependencyFile,
    );

    if ($resolved['directory'] !== $expectedDirectory) {
        throw new RuntimeException(
            "{$dependencyFile} alongside a {$mainType} must go to "
                . "{$expectedDirectory}, got {$resolved['directory']}.",
        );
    }
}

passTarget('dependency files land in the right directory');

// A jar dependency of zip-packaged content must keep the jar extension.
$jarDependency = ContentInstallTarget::filename(
    'iris-1.7.3.jar',
    'Iris',
    '1.20.1',
    'iris',
    ContentInstallTarget::forDependency(
        ContentInstallTarget::for('shader'),
        'iris-1.7.3.jar',
    )['extensions'],
);

if ($jarDependency !== 'iris-iris-1-20-1.jar') {
    throw new RuntimeException(
        "Unexpected dependency filename: {$jarDependency}",
    );
}

passTarget('jar dependencies keep the jar extension');

// ── Content kinds ─────────────────────────────────────────────────────

// Every target reports the kind a card should show for it.
foreach ($expected as $type => $directory) {
    if (ContentInstallTarget::for($type)['kind'] !== $type) {
        throw new RuntimeException("{$type} must report its own kind.");
    }
}

passTarget('every content type reports its kind');

$kindPaths = [
    'mods/sodium.jar' => 'mod',
    'plugins/vault.jar' => 'plugin',
    'world/datapacks/terralith.zip' => 'datapack',
    'resourcepacks/pack.zip' => 'resourcepack',
    'shaderpacks/bsl.zip' => 'shader',
];

foreach ($kindPaths as $path => $kind) {
    if (ContentInstallTarget::kindForPath($path) !== $kind) {
        throw new RuntimeException("{$path} must map to the {$kind} kind.");
    }
}

if (ContentInstallTarget::kindForPath('server.properties') !== null) {
    throw new RuntimeException('Unknown paths must not map to a kind.');
}

// A jar dependency of zip-packaged content is a mod, and says so.
if (
    ContentInstallTarget::forDependency(
        ContentInstallTarget::for('shader'),
        'iris-1.7.3.jar',
    )['kind'] !== 'mod'
) {
    throw new RuntimeException('A jar dependency must report the mod kind.');
}

if (!ContentInstallTarget::supportsKind('modpack')
    || !ContentInstallTarget::supportsKind('datapack')
    || ContentInstallTarget::supportsKind('texture')
) {
    throw new RuntimeException('Kind validation is wrong.');
}

passTarget('dependency kinds and kind validation are correct');

echo "\nAll content install target tests passed.\n";
