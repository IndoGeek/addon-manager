<?php

require __DIR__ . '/../../app/Services/Installation/PackageLayout.php';
require __DIR__ . '/../../app/Services/Installation/PackageRootResolver.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageLayout;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;

$root = sys_get_temp_dir()
    . '/modpack-package-layout-test-'
    . bin2hex(random_bytes(8));

mkdir($root, 0750, true);
mkdir($root . '/overrides', 0750, true);
mkdir($root . '/overrides/mods', 0750, true);

file_put_contents(
    $root . '/manifest.json',
    '{}',
);

file_put_contents(
    $root . '/overrides/mods/example.jar',
    'test',
);

$resolver = new PackageRootResolver();

$direct = $resolver->resolve(
    $root,
    PackageLayout::DIRECT,
);

if ($direct !== realpath($root)) {
    throw new RuntimeException(
        'DIRECT layout resolved to the wrong directory.'
    );
}

echo "PASS: DIRECT layout resolved\n";

$overrides = $resolver->resolve(
    $root,
    PackageLayout::OVERRIDES,
);

if ($overrides !== realpath($root . '/overrides')) {
    throw new RuntimeException(
        'OVERRIDES layout resolved to the wrong directory.'
    );
}

echo "PASS: OVERRIDES layout resolved\n";

$failed = false;

try {
    $resolver->resolve(
        $root . '/missing',
        PackageLayout::DIRECT,
    );
} catch (InvalidArgumentException) {
    $failed = true;
}

if (!$failed) {
    throw new RuntimeException(
        'Missing workspace was not rejected.'
    );
}

echo "PASS: missing workspace rejected\n";

$failed = false;

$emptyRoot = $root . '/empty';
mkdir($emptyRoot, 0750, true);

try {
    $resolver->resolve(
        $emptyRoot,
        PackageLayout::OVERRIDES,
    );
} catch (RuntimeException) {
    $failed = true;
}

if (!$failed) {
    throw new RuntimeException(
        'Missing overrides directory was not rejected.'
    );
}

echo "PASS: missing overrides directory rejected\n";

echo "4/4 package layout tests passed.\n";

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
