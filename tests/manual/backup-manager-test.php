<?php

require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir() . '/modpack-backup-test-' . bin2hex(random_bytes(8));
$server = $root . '/server';
$backup = $root . '/backup';

mkdir($server . '/config', 0750, true);

$originalContents = '{"difficulty":"hard","online-mode":true}';

file_put_contents(
    $server . '/config/server.json',
    $originalContents,
);

$serverTarget = new LocalFilesystemServerFileTarget($server);
$manager = new BackupManager($serverTarget);

try {
    $backupPath = $manager->backup(
        'config/server.json',
        $backup,
    );

    if (!is_file($backupPath)) {
        throw new RuntimeException(
            'Backup file was not created.'
        );
    }

    $backupContents = file_get_contents($backupPath);

    if ($backupContents !== $originalContents) {
        throw new RuntimeException(
            'Backup contents do not match the original file.'
        );
    }

    if (!is_file($server . '/config/server.json')) {
        throw new RuntimeException(
            'Original server file was unexpectedly removed.'
        );
    }

    echo "PASS: existing file backed up\n";
    echo "PASS: original server file preserved\n";

    try {
        $manager->backup(
            '../outside.txt',
            $backup,
        );

        throw new RuntimeException(
            'Parent traversal was not rejected.'
        );
    } catch (InvalidArgumentException) {
        echo "PASS: parent traversal rejected\n";
    }

    try {
        $manager->backup(
            '../../outside.txt',
            $backup,
        );

        throw new RuntimeException(
            'Nested parent traversal was not rejected.'
        );
    } catch (InvalidArgumentException) {
        echo "PASS: nested parent traversal rejected\n";
    }

    try {
        $manager->backup(
            '/etc/passwd',
            $backup,
        );

        throw new RuntimeException(
            'Absolute path was not rejected.'
        );
    } catch (InvalidArgumentException) {
        echo "PASS: absolute path rejected\n";
    }

    try {
        $manager->backup(
            'C:/Windows/System32/test.txt',
            $backup,
        );

        throw new RuntimeException(
            'Windows path was not rejected.'
        );
    } catch (InvalidArgumentException) {
        echo "PASS: Windows path rejected\n";
    }

    echo "6/6 tests passed.\n";
} finally {
    if (is_dir($root)) {
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
    }
}
