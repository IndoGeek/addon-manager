<?php

// Functional tests for the admin install history store: - count()/page() drive the settings page's 10-per-page paginator...

require __DIR__ . '/../../app/Services/Management/InstallHistoryStore.php';

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallHistoryStore;

$root = sys_get_temp_dir()
    . '/modpack-history-test-'
    . bin2hex(random_bytes(8));

if (!mkdir($root, 0750, true)) {
    throw new RuntimeException('Unable to create test directory.');
}

$pass = 0;
$fail = 0;

$check = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;

        echo "PASS: {$label}\n";

        return;
    }

    $fail++;

    echo "FAIL: {$label}\n";
};

try {
    $store = new InstallHistoryStore($root);

    $check($store->count() === 0, 'an empty store counts zero entries');
    $check($store->all(0) === [], 'an empty store lists no entries');
    $check($store->page(1, 10) === [], 'an empty store has an empty first page');

    for ($i = 1; $i <= 25; $i++) {
        $store->append([
            'action' => 'install',
            'modpack' => "Pack {$i}",
        ]);
    }

    $check($store->count() === 25, 'count() reports every appended entry');

    $first = $store->page(1, 10);

    $check(count($first) === 10, 'the first page holds a full page of entries');
    $check(
        ($first[0]['modpack'] ?? '') === 'Pack 25',
        'the first page starts at the newest entry',
    );
    $check(
        ($first[9]['modpack'] ?? '') === 'Pack 16',
        'the first page ends ten entries back',
    );

    $second = $store->page(2, 10);

    $check(count($second) === 10, 'the second page holds a full page of entries');
    $check(
        ($second[0]['modpack'] ?? '') === 'Pack 15',
        'the second page continues where the first ended',
    );

    $last = $store->page(3, 10);

    $check(count($last) === 5, 'the final page holds the remaining entries');
    $check(
        ($last[4]['modpack'] ?? '') === 'Pack 1',
        'the final page ends at the oldest entry',
    );

    $check($store->page(4, 10) === [], 'a page past the end is empty');
    $check($store->page(1, 0) !== [], 'a non-positive page size still returns entries');

    // Pages must partition the history exactly: no entry duplicated, none dropped.
    $seen = [];

    foreach ([1, 2, 3] as $page) {
        foreach ($store->page($page, 10) as $entry) {
            $seen[] = $entry['modpack'] ?? '';
        }
    }

    $check(count($seen) === 25, 'the pages cover every entry exactly once');
    $check(count(array_unique($seen)) === 25, 'no entry appears on two pages');

    // The store keeps a bounded backlog, so the paginator must follow the trimmed count.
    for ($i = 26; $i <= 215; $i++) {
        $store->append([
            'action' => 'update',
            'modpack' => "Pack {$i}",
        ]);
    }

    $check($store->count() === 200, 'the oldest entries are trimmed once the cap is hit');
    $check(
        ($store->page(1, 10)[0]['modpack'] ?? '') === 'Pack 215',
        'the newest entry survives the cap',
    );
    $check(
        ($store->page(20, 10)[9]['modpack'] ?? '') === 'Pack 16',
        'the last page of a capped history holds the oldest surviving entry',
    );
} finally {
    foreach (glob($root . '/*') ?: [] as $leftover) {
        if (is_file($leftover)) {
            @unlink($leftover);
        }
    }

    @rmdir($root);
}

echo "\n{$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
