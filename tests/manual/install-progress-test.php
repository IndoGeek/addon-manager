<?php

// Functional tests for installation progress reporting + raised caps: - InstallProgressStore round-trips state, expires...

require __DIR__ . '/../../app/Services/Download/Downloader.php';
require __DIR__ . '/../../app/Services/Download/StageReporter.php';
require __DIR__ . '/../../app/Services/Download/ConcurrentDownloader.php';
require __DIR__ . '/../../app/Services/Download/DownloadManager.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentOperation.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentPlan.php';
require __DIR__ . '/../../app/Services/Deployment/DeploymentExecutor.php';
require __DIR__ . '/../../app/Services/Server/ServerFileTarget.php';
require __DIR__ . '/../../app/Services/Server/LocalFilesystemServerFileTarget.php';
require __DIR__ . '/../../app/Services/Installation/InstallProgressStore.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentExecutor;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentOperation;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Deployment\DeploymentPlan;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\DownloadManager;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallProgressStore;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\LocalFilesystemServerFileTarget;

$root = sys_get_temp_dir()
    . '/modpack-progress-test-'
    . bin2hex(random_bytes(8));

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

$store = new InstallProgressStore($root . '/progress');

$store->set('token-1', [
    'phase' => 'download',
    'percent' => 41,
    'indeterminate' => false,
]);

$state = $store->get('token-1');

$check(
    $state !== null
        && $state['phase'] === 'download'
        && $state['percent'] === 41,
    'progress store round-trips state',
);

$store->set('token-1', [
    'phase' => 'deploy',
    'percent' => 91,
    'indeterminate' => false,
    'deployed_files' => 3,
    'total_files' => 5,
]);

$state = $store->get('token-1');

$check(
    $state['phase'] === 'deploy'
        && $state['deployed_files'] === 3
        && $state['total_files'] === 5,
    'progress store overwrites updates in place',
);

$check(
    $store->get('absent-token') === null,
    'progress store returns null for unknown tokens',
);

$store->set('expiring-token', ['phase' => 'starting', 'percent' => 1], 1);

sleep(2);

$check(
    $store->get('expiring-token') === null
        && !is_file($root . '/progress/expiring-token.json'),
    'progress store expires and prunes stale files',
);

$server = $root . '/server/minecraft';
$workspace = $root . '/workspace';

mkdir($server, 0777, true);
mkdir($workspace, 0777, true);

$manager = new DownloadManager($root . '/tmp');

$property = new ReflectionProperty(
    DownloadManager::class,
    'maxDownloadBytes',
);

$check(
    $property->getValue($manager) === 10_737_418_240,
    'DownloadManager default cap is 10 GiB',
);

$executor = new DeploymentExecutor(
    new LocalFilesystemServerFileTarget($server),
);

$property = new ReflectionProperty(
    DeploymentExecutor::class,
    'maxFileBytes',
);

$check(
    $property->getValue($executor) === 10_737_418_240,
    'DeploymentExecutor default per-file cap is 10 GiB',
);

foreach (['a.txt', 'b.txt', 'c.txt'] as $file) {
    file_put_contents($workspace . '/' . $file, 'payload-' . $file);
}

$operations = [
    new DeploymentOperation(
        relativePath: 'a.txt',
        source: $workspace . '/a.txt',
        destination: '',
        overwrite: false,
    ),
    new DeploymentOperation(
        relativePath: 'b.txt',
        source: $workspace . '/b.txt',
        destination: '',
        overwrite: false,
    ),
    new DeploymentOperation(
        relativePath: 'c.txt',
        source: $workspace . '/c.txt',
        destination: '',
        overwrite: false,
    ),
];

$barometer = null;

$executor = new DeploymentExecutor(
    new LocalFilesystemServerFileTarget($server),
);

$executor->setProgressCallback(
    static function (int $deployed, int $total) use (&$barometer): void {
        $barometer = [$deployed, $total];
    },
);

$executor->execute(new DeploymentPlan($operations));

$check(
    $barometer === [3, 3],
    'deployment progress callback reports (deployed, total) to completion',
);

$check(
    is_file($server . '/a.txt')
        && is_file($server . '/b.txt')
        && is_file($server . '/c.txt'),
    'progress-reporting deployment still writes every file',
);

@exec('rm -rf ' . escapeshellarg($root));

echo "\n$pass passed, $fail failed\n";

exit($fail === 0 ? 0 : 1);