<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\OwnershipRemover;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;
use RuntimeException;

require_once __DIR__ . '/../../app/Services/Server/ServerRelativePath.php';
require_once __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Management/OwnershipRemover.php';

$root = sys_get_temp_dir()
    . '/modpack-installer-remover-'
    . bin2hex(random_bytes(8));

if (!mkdir($root, 0750, true)) {
    throw new RuntimeException('Unable to create test root.');
}

try {
    $target = new LocalFilesystemServerFileTarget($root);

    $target->ensureDirectory('mods');
    $target->ensureDirectory('config');
    $target->ensureDirectory('world');

    $target->write('mods/owned.jar', 'owned');
    $target->write('mods/also-owned.jar', 'owned');
    $target->write('config/owned.txt', 'owned');
    $target->write('mods/keep.jar', 'keep');
    $target->write('world/data.dat', 'keep');

    $remover = new OwnershipRemover($target);

    $outcome = $remover->remove([
        'mods/owned.jar',
        'config/owned.txt',
        'mods/not-present.jar',
        'mods/also-owned.jar',
    ]);

    if ($outcome['errors'] !== []) {
        throw new RuntimeException(
            'Unexpected removal errors: ' . implode('; ', $outcome['errors'])
        );
    }

    if ($target->exists('mods/owned.jar')) {
        throw new RuntimeException('Owned file was not removed.');
    }

    if ($target->exists('config/owned.txt')) {
        throw new RuntimeException('Nested owned file was not removed.');
    }

    if ($target->exists('mods/also-owned.jar')) {
        throw new RuntimeException('Owned file was not removed.');
    }

    if (!$target->exists('mods/keep.jar')) {
        throw new RuntimeException('Unrelated file was removed.');
    }

    if (!$target->exists('world/data.dat')) {
        throw new RuntimeException('Unrelated nested file was removed.');
    }

    if (!$target->isDirectory('mods')) {
        throw new RuntimeException('Directory must never be removed.');
    }

    if (count($outcome['deleted']) !== 3) {
        throw new RuntimeException(
            'Expected three deleted files.'
        );
    }

    if (count($outcome['missing']) !== 1) {
        throw new RuntimeException(
            'Expected exactly one missing file.'
        );
    }

    echo "PASS: removes owned files only and tolerates missing paths.\n";

    $second = $remover->remove(['mods/keep.jar']);

    if (count($second['deleted']) !== 1) {
        throw new RuntimeException(
            'Second removal did not delete the owned file.'
        );
    }

    echo "PASS: idempotent removal of remaining files.\n";

    $hostile = $remover->remove(['../escape', '/etc/passwd', 'world']);

    if (count($hostile['deleted']) !== 0) {
        throw new RuntimeException(
            'Hostile paths must never be deleted.'
        );
    }

    if (count($hostile['errors']) !== 3) {
        throw new RuntimeException(
            'Expected three errors for the hostile and directory paths.'
        );
    }

    echo "PASS: hostile and directory paths are rejected.\n";

    // Content semantics (mods): pruneEmptyDirs=false must leave the parent directory in place even when the removed...
    $target->ensureDirectory('plugins');
    $target->write('plugins/only-mod.jar', 'mod');

    $content = $remover->remove(['plugins/only-mod.jar'], pruneEmptyDirs: false);

    if (
        count($content['deleted']) !== 1
        || $content['errors'] !== []
    ) {
        throw new RuntimeException('Content removal failed.');
    }

    if (!$target->isDirectory('plugins')) {
        throw new RuntimeException(
            'Content removal must never prune the parent directory.'
        );
    }

    echo "PASS: content removal leaves the parent directory in place.\n";

    // Modpack semantics (default) still prune emptied parents.
    $target->ensureDirectory('mods-deep/nested');
    $target->write('mods-deep/nested/last.jar', 'modpack');

    $modpack = $remover->remove(['mods-deep/nested/last.jar']);

    if (
        count($modpack['deleted']) !== 1
        || $target->isDirectory('mods-deep')
    ) {
        throw new RuntimeException(
            'Default removal must prune emptied parent directories.'
        );
    }

    echo "PASS: modpack removal still prunes emptied parents.\n";
} finally {
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($root);
}