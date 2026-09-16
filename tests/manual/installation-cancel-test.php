<?php

/**
 * Functional tests for the installation cancellation flow:
 *   - InstallationCancelledException is a distinct RuntimeException
 *   - DeploymentExecutor aborts mid-plan with the unwrapped cancellation
 *     exception and stops before writing further files
 *   - ArchiveExtractor aborts mid-archive and removes its partial output
 *   - DownloadManager's cancel checker does not disturb URL validation,
 *     and rejected downloads still leave nothing behind
 */

require __DIR__ . '/../../app/Services/Installation/InstallationCancelledException.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentException.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveValidator.php';
require __DIR__ . '/../../app/Services/Archive/ArchiveExtractor.php';
require __DIR__ . '/../../app/Services/Download/Downloader.php';
require __DIR__ . '/../../app/Services/Download/ConcurrentDownloader.php';
require __DIR__ . '/../../app/Services/Download/DownloadManager.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveExtractor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir() . '/modpack-cancel-test-' . bin2hex(random_bytes(8));
$workspace = $root . '/workspace';
$serverRoot = $root . '/server';

mkdir($workspace . '/mods', 0750, true);
mkdir($serverRoot . '/mods', 0750, true);

$pass = 0;
$fail = 0;

$check = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
};

// 1. Exception contract
$exception = new InstallationCancelledException('Installation cancelled.');

$check(
    $exception instanceof RuntimeException,
    'InstallationCancelledException extends RuntimeException'
);
$check(
    $exception->getMessage() === 'Installation cancelled.',
    'InstallationCancelledException carries its message'
);

// 2 + 3. DeploymentExecutor cancellation
$sources = [];

foreach (['alpha.jar', 'bravo.jar', 'charlie.jar'] as $name) {
    $source = $workspace . '/mods/' . $name;

    file_put_contents($source, "contents of $name");

    $sources[$name] = $source;
}

$plan = new DeploymentPlan(array_map(
    static fn (string $name, string $source): DeploymentOperation =>
        new DeploymentOperation(
            relativePath: 'mods/' . $name,
            source: $source,
            destination: '',
            overwrite: false,
        ),
    array_keys($sources),
    array_values($sources),
));

$executor = new DeploymentExecutor(
    new LocalFilesystemServerFileTarget($serverRoot),
);

// 2. Cancel before the very first file: nothing is written
$executor->setCancelChecker(static fn (): bool => true);

try {
    $executor->execute($plan);
    $check(false, 'always-cancelling deploy did not abort');
} catch (InstallationCancelledException) {
    $check(true, 'always-cancelling deploy aborts with unwrapped exception');
} catch (DeploymentException) {
    $check(false, 'cancellation was wrapped in DeploymentException');
}

$check(
    !is_file($serverRoot . '/mods/alpha.jar'),
    'cancel-before-first-file leaves nothing deployed'
);

// 3. Cancel between files: earlier files persist, the rest never run
$cancelled = false;

$executor->setCancelChecker(static function () use (&$cancelled): bool {
    if ($cancelled) {
        return true;
    }

    $cancelled = true;

    return false;
});

try {
    $executor->execute($plan);
    $check(false, 'mid-plan cancel did not abort');
} catch (InstallationCancelledException) {
    $check(true, 'mid-plan cancel aborts with unwrapped exception');
} catch (DeploymentException $deploymentException) {
    $check(false, 'mid-plan cancellation was wrapped: ' . $deploymentException->getMessage());
}

$check(
    is_file($serverRoot . '/mods/alpha.jar'),
    'file deployed before the cancel point persists'
);
$check(
    !is_file($serverRoot . '/mods/bravo.jar'),
    'file after the cancel point is not written'
);
$check(
    !is_file($serverRoot . '/mods/charlie.jar'),
    'later files are not written after cancellation'
);

// 4. ArchiveExtractor cancellation + partial output cleanup
$zipPath = $root . '/sample.zip';
$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create ZIP for extractor cancel test.');
}

foreach (['mods/a.jar', 'mods/b.jar', 'config/c.json'] as $entry) {
    $zip->addFromString($entry, 'payload ' . $entry);
}

$zip->close();

$extractionRoot = $root . '/extraction';
$extractor = new ArchiveExtractor($extractionRoot);

$extractor->setCancelChecker(static fn (): bool => true);

try {
    $extractor->extract($zipPath);
    $check(false, 'always-cancelling extract did not abort');
} catch (InstallationCancelledException) {
    $check(true, 'always-cancelling extract aborts with cancellation exception');
}

$extractionLeftovers = [];

if (is_dir($extractionRoot)) {
    foreach (scandir($extractionRoot) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $extractionLeftovers[] = $entry;
        }
    }
}

$check(
    $extractionLeftovers === [],
    'cancelled extraction removes its partial destination'
);

// 5. DownloadManager cancel checker is orthogonal to URL validation
$manager = new DownloadManager(
    $root . '/downloads',
    1024 * 1024,
);

$manager->setCancelChecker(static fn (): bool => false);

$rejected = false;

try {
    $manager->download('ftp://example.com/modpack.zip');
} catch (Throwable) {
    $rejected = true;
}

$check($rejected, 'URL validation still rejects with a cancel checker attached');

$leftovers = [];

if (is_dir($root . '/downloads')) {
    foreach (scandir($root . '/downloads') ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $leftovers[] = $entry;
        }
    }
}

$check(
    $leftovers === [],
    'rejected download leaves no temporary files with a cancel checker attached'
);

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
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($root);
}

echo "\n{$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    exit(1);
}