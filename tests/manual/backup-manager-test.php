<?php

require __DIR__ . '/../../app/Services/Deployment/BackupManager.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;

$root = sys_get_temp_dir() . '/modpack-backup-test-' . bin2hex(random_bytes(8));
$server = $root . '/server';
$backup = $root . '/backup';

mkdir($server . '/config', 0750, true);

$originalContents = '{"difficulty":"hard","online-mode":true}';

file_put_contents(
    $server . '/config/server.json',
    $originalContents,
);

$manager = new BackupManager();

try {
    $backupPath = $manager->backup(
        $server,
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
		$server,
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
		$server,
		'../../outside.txt',
		$backup,
	    );

	    throw new RuntimeException(
		'Nested parent traversal was not rejected.'
	    );
	} catch (InvalidArgumentException) {
	    echo "PASS: nested parent traversal rejected\n";
	}

	echo "4/4 tests passed.\n";

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
