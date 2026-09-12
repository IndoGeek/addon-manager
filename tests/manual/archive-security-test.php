<?php

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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveExtractor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive\ArchiveValidator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

$temporaryRoot = sys_get_temp_dir()
    . '/modpack-archive-security-'
    . bin2hex(random_bytes(8));

mkdir($temporaryRoot, 0750, true);

$validator = new ArchiveValidator();

function buildZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function assertRejected(string $name, callable $run): void
{
    try {
        $run();
        throw new RuntimeException("Expected rejection: {$name}");
    } catch (InvalidArgumentException) {
        pass($name);
    }
}

// Duplicate normalization: identical names, and file/directory collisions.
$duplicatePath = $temporaryRoot . '/duplicates.zip';

// libzip/ZipArchive deduplicates same-name entries, so build a genuinely
// duplicate entry archive with Python's zipfile instead.
$duplicateCode = sprintf(
    "import zipfile, sys\nz = zipfile.ZipFile(sys.argv[1], 'w')\nz.writestr('config/a.txt', 'first')\nz.writestr('config/a.txt', 'second')\nz.close()\n",
);

exec(
    sprintf(
        'python3 -W ignore -c %s %s',
        escapeshellarg($duplicateCode),
        escapeshellarg($duplicatePath),
    ),
    $duplicateOutput,
    $duplicateStatus,
);

if ($duplicateStatus !== 0 || !is_file($duplicatePath)) {
    throw new RuntimeException('Failed to build duplicate archive.');
}

assertRejected('exact duplicate entries rejected', function () use (
    $duplicatePath,
    $validator,
): void {
    $validator->validate($duplicatePath);
});

$collisionPath = $temporaryRoot . '/collision.zip';
buildZip($collisionPath, [
    'config' => 'file version',
    'config/child.txt' => 'child',
]);

assertRejected('file/directory collisions rejected', function () use (
    $collisionPath,
    $validator,
): void {
    $validator->validate($collisionPath);
});

// Symlink entries stored with ZIP -y carry the 0120000 type bits.
$symlinkPath = $temporaryRoot . '/symlink.zip';
$symlinkTree = $temporaryRoot . '/symlink-tree';

mkdir($symlinkTree, 0750, true);
file_put_contents($symlinkTree . '/real.txt', 'payload');
symlink('real.txt', $symlinkTree . '/link.txt');

$zipCommand = sprintf(
    'cd %s && zip -y -q %s link.txt real.txt',
    escapeshellarg($symlinkTree),
    escapeshellarg($symlinkPath),
);

exec($zipCommand, $zipOutput, $zipStatus);

if ($zipStatus !== 0 || !is_file($symlinkPath)) {
    throw new RuntimeException('Failed to build symlink archive.');
}

assertRejected('symlink entries rejected', function () use (
    $symlinkPath,
    $validator,
): void {
    $validator->validate($symlinkPath);
});

// The extractor must refuse the same archives without leaving any
// extraction directories behind.
$extractor = new ArchiveExtractor($temporaryRoot . '/extractions');

foreach ([$duplicatePath, $collisionPath, $symlinkPath] as $badArchive) {
    assertRejected("extractor rejects unsafe archive: " . basename($badArchive), function () use (
        $badArchive,
        $extractor,
    ): void {
        $extractor->extract($badArchive);
    });
}

if (!is_dir($temporaryRoot . '/extractions')) {
    pass('extractor leaves no extraction root behind after rejection');
} elseif (array_diff(scandir($temporaryRoot . '/extractions'), ['.', '..']) === []) {
    pass('extractor leaves no extraction directories behind after rejection');
} else {
    throw new RuntimeException(
        'Extractor left extraction directories behind after rejection.',
    );
}

// A configured entropy limit is enforced consistently by extraction.
$lowLimitExtractor = new ArchiveExtractor(
    $temporaryRoot . '/low-limit',
    maxExtractedBytes: 128,
    maxEntryBytes: 64,
);

$bigPath = $temporaryRoot . '/big.zip';
buildZip($bigPath, [
    'config/large.txt' => str_repeat('x', 512),
]);

assertRejected('low archive limits rejected by extractor', function () use (
    $bigPath,
    $lowLimitExtractor,
): void {
    $lowLimitExtractor->extract($bigPath);
});

// Workspace cleanup is best-effort: an unwritable inner directory must not
// let cleanup throw.
$workspaceManager = new InstallationWorkspace($temporaryRoot . '/workspaces');

$workspace = $temporaryRoot . '/workspaces/doomed';
mkdir($workspace . '/locked', 0750, true);
file_put_contents($workspace . '/a.txt', 'a');
file_put_contents($workspace . '/locked/b.txt', 'b');
chmod($workspace . '/locked', 0000);

$thrown = false;

try {
    $workspaceManager->cleanup($workspace);
} catch (Throwable $exception) {
    $thrown = true;
    $message = $exception->getMessage();
}

chmod($workspace . '/locked', 0750);

if ($thrown) {
    throw new RuntimeException(
        'Workspace cleanup threw: ' . ($message ?? 'unknown'),
    );
}

pass('workspace cleanup never throws');

// Confirm a normally writable workspace is fully removed by cleanup.
$normalWorkspace = $temporaryRoot . '/workspaces/normal';
mkdir($normalWorkspace . '/deep', 0750, true);
file_put_contents($normalWorkspace . '/deep/c.txt', 'c');

$workspaceManager->cleanup($normalWorkspace);

if (is_dir($normalWorkspace)) {
    throw new RuntimeException('Normal workspace was not removed.');
}

pass('normal workspace fully removed by cleanup');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $temporaryRoot,
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

@rmdir($temporaryRoot);

echo "\nAll archive security tests passed.\n";