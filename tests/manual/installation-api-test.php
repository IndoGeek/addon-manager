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

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\MockModpackProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\BackupManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlanner;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPolicy;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageLayout;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\PackageRootResolver;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTargetFactory;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerIdentity;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerTargetResolver;

$root = sys_get_temp_dir()
    . '/modpack-api-test-'
    . bin2hex(random_bytes(8));

$serverUuid =
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

$serverRoot = $root . '/' . $serverUuid;

mkdir($serverRoot, 0750, true);

$provider = new MockModpackProvider();

$package = $provider->getPackage(
    'mock://example-pack',
);

if (!is_file($package->archivePath)) {
    throw new RuntimeException(
        'Mock package archive does not exist.',
    );
}

echo "PASS: mock package resolved\n";

$identity = ServerIdentity::fromUuid(
    $serverUuid,
);

$factory = new ServerFileTargetFactory($root);

$resolver = new ServerTargetResolver($factory);

$target = $resolver->resolve($identity);


echo "PASS: server target resolved\n";

$temporaryRoot = $root . '/temporary';

$orchestrator = new InstallationOrchestrator(
    workspaceManager: new InstallationWorkspace(
        $temporaryRoot,
    ),
    planner: new DeploymentPlanner($target),
    backupManager: new BackupManager($target),
    executor: new DeploymentExecutor($target),
    temporaryRoot: $temporaryRoot,
    packageRootResolver: new PackageRootResolver(),
    serverFileTarget: $target,
);

$preview = $orchestrator->preview(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::OVERWRITE,
    layout: PackageLayout::DIRECT,
);

if ($preview->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected 2 files in installation preview.',
    );
}

echo "PASS: preview returned 2 files\n";

if ($preview->createdCount() !== 2) {
    throw new RuntimeException(
        'Expected 2 new files in fresh preview.',
    );
}

echo "PASS: preview reports 2 new files\n";

if (is_file($serverRoot . '/config.txt')) {
    throw new RuntimeException(
        'Preview modified the server filesystem.',
    );
}

echo "PASS: preview does not modify server\n";

file_put_contents(
    $serverRoot . '/config.txt',
    'original-content',
);

$overwritePreview = $orchestrator->preview(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::OVERWRITE,
    layout: PackageLayout::DIRECT,
);

if ($overwritePreview->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected 2 files in overwrite preview.',
    );
}

if ($overwritePreview->createdCount() !== 1) {
    throw new RuntimeException(
        'Expected 1 new file in overwrite preview.',
    );
}

if ($overwritePreview->overwrittenCount() !== 1) {
    throw new RuntimeException(
        'Expected 1 overwritten file in overwrite preview.',
    );
}

echo "PASS: overwrite preview counts are correct\n";

$result = $orchestrator->install(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::OVERWRITE,
    layout: PackageLayout::DIRECT,
);

if ($result->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected 2 installed files.',
    );
}

echo "PASS: installation completed\n";

if (!is_file($serverRoot . '/mods/example-mod.jar')) {
    throw new RuntimeException(
        'Example mod was not installed.',
    );
}

echo "PASS: mod file installed\n";

if (
    file_get_contents($serverRoot . '/config.txt')
    === 'original-content'
) {
    throw new RuntimeException(
        'Existing config was not overwritten.',
    );
}

echo "PASS: existing config was overwritten\n";

file_put_contents(
    $serverRoot . '/config.txt',
    'preserve-this-content',
);

$skipPreview = $orchestrator->preview(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::SKIP_EXISTING,
    layout: PackageLayout::DIRECT,
);

if ($skipPreview->totalFiles() !== 0) {
    throw new RuntimeException(
        'Expected no files in skip-existing preview when all exist.',
    );
}

echo "PASS: skip-existing preview is correct\n";

$orchestrator->install(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::SKIP_EXISTING,
    layout: PackageLayout::DIRECT,
);

if (
    file_get_contents($serverRoot . '/config.txt')
    !== 'preserve-this-content'
) {
    throw new RuntimeException(
        'Skip-existing modified an existing file.',
    );
}

echo "PASS: skip-existing preserved file\n";

file_put_contents(
    $serverRoot . '/config.txt',
    'create-only-content',
);

$createOnlyPreview = $orchestrator->preview(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::CREATE_ONLY,
    layout: PackageLayout::DIRECT,
);

if ($createOnlyPreview->totalFiles() !== 0) {
    throw new RuntimeException(
        'Expected no files in create-only preview when all exist.',
    );
}

echo "PASS: create-only preview is correct\n";

$orchestrator->install(
    archivePath: $package->archivePath,
    policy: DeploymentPolicy::CREATE_ONLY,
    layout: PackageLayout::DIRECT,
);

if (
    file_get_contents($serverRoot . '/config.txt')
    !== 'create-only-content'
) {
    throw new RuntimeException(
        'Create-only modified an existing file.',
    );
}

echo "PASS: create-only preserved file\n";

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

echo "11/11 installation API tests passed.\n";
