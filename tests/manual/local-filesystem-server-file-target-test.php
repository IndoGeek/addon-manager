<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Tests\Manual;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;

require_once __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require_once __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';

$root = sys_get_temp_dir()
    . '/modpack-installer-server-target-'
    . bin2hex(random_bytes(8));

if (!mkdir($root, 0750, true)) {
    throw new RuntimeException('Unable to create test root.');
}

try {
    $target = new LocalFilesystemServerFileTarget($root);

    if (!$target instanceof ServerFileTarget) {
        throw new RuntimeException(
            'Local filesystem target does not implement ServerFileTarget.'
        );
    }

    $target->ensureDirectory('mods');

    if (!$target->isDirectory('mods')) {
        throw new RuntimeException(
            'mods directory was not created.'
        );
    }

    $target->write(
        'mods/example.jar',
        'test-content',
    );

    if (!$target->exists('mods/example.jar')) {
        throw new RuntimeException(
            'Written file does not exist.'
        );
    }

    if ($target->read('mods/example.jar') !== 'test-content') {
        throw new RuntimeException(
            'Read contents do not match written contents.'
        );
    }

    $target->write(
        'config/example.txt',
        'nested-content',
    );

    if ($target->read('config/example.txt') !== 'nested-content') {
        throw new RuntimeException(
            'Nested file contents do not match.'
        );
    }

    $target->delete('mods/example.jar');

    if ($target->exists('mods/example.jar')) {
        throw new RuntimeException(
            'File was not deleted.'
        );
    }

    $invalidPaths = [
        '../escape.txt',
        'nested/../../escape.txt',
        '/etc/passwd',
        '\\etc\\passwd',
        'C:/Windows/System32/test.txt',
        'C:\\Windows\\System32\\test.txt',
        "mods/\0evil.txt",
    ];

    foreach ($invalidPaths as $path) {
        try {
            $target->exists($path);

            throw new RuntimeException(
                "Invalid path was accepted: {$path}"
            );
        } catch (RuntimeException $exception) {
            if (
                !str_contains(
                    $exception->getMessage(),
                    'not allowed'
                )
                && !str_contains(
                    $exception->getMessage(),
                    'NUL'
                )
            ) {
                throw $exception;
            }
        }
    }

    echo "Local filesystem server file target test passed.\n";
} finally {
    if (is_dir($root)) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }
}
