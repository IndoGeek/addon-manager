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
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationOrchestrator;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationWorkspace;
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
    serverFileTarget: $target,
);

$result = $orchestrator->install(
    archivePath: $package->archivePath,
);

if ($result->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected 2 installed files.',
    );
}

if ($result->createdCount() !== 2) {
    throw new RuntimeException(
        'Expected 2 new files in fresh install.',
    );
}

echo "PASS: installation completed\n";

if (!is_file($serverRoot . '/mods/example-mod.jar')) {
    throw new RuntimeException(
        'Example mod was not installed.',
    );
}

echo "PASS: mod file installed\n";

if (!is_file($serverRoot . '/config.txt')) {
    throw new RuntimeException(
        'Config file was not installed.',
    );
}

echo "PASS: config file installed\n";

file_put_contents(
    $serverRoot . '/config.txt',
    'preserve-this-content',
);

$reinstall = $orchestrator->install(
    archivePath: $package->archivePath,
);

if ($reinstall->totalFiles() !== 2) {
    throw new RuntimeException(
        'Expected 2 files on reinstall.',
    );
}

if ($reinstall->createdCount() !== 0) {
    throw new RuntimeException(
        'Reinstall should not create new files.',
    );
}

if ($reinstall->overwrittenCount() !== 2) {
    throw new RuntimeException(
        'Expected 2 overwritten files on reinstall.',
    );
}

echo "PASS: reinstall overwrites existing files by default\n";

if (
    file_get_contents($serverRoot . '/config.txt')
    === 'preserve-this-content'
) {
    throw new RuntimeException(
        'Existing config was not overwritten.',
    );
}

echo "PASS: existing config was overwritten\n";

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

echo "6/6 installation API tests passed.\n";