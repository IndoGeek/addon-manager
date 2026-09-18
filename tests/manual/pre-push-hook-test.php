<?php

// Functional tests for the pre-push hook: configurable checks, and the version bump derived from pushed commit messages.

$repoRoot = dirname(__DIR__, 2);
$hookSource = $repoRoot . '/tools/hooks/pre-push';
$miSource = $repoRoot . '/mi';

if (!file_exists($hookSource) || !file_exists($miSource)) {
    throw new RuntimeException('hook or mi is missing from the checkout.');
}

// The hook drives real git repositories, so a missing git is an environment gap rather than a failure.
exec('git --version 2>/dev/null', $gitProbe, $gitStatus);

if ($gitStatus !== 0) {
    echo "SKIP: git is not available — the pre-push hook cannot be exercised here.\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/modpack-prepush-test-' . bin2hex(random_bytes(8));

if (!mkdir($root, 0750, true)) {
    throw new RuntimeException('Unable to create the test root.');
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

$run = static function (array $argv, string $cwd, array $env = []): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // getenv() carries PATH and friends through; $_ENV is empty under the usual variables_order setting.
    $process = proc_open($argv, $descriptors, $pipes, $cwd, array_merge(getenv(), $env));

    if (!is_resource($process)) {
        return [-1, ''];
    }

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out . $err];
};

$git = static function (string $repo, string ...$args) use ($run): array {
    return $run(array_merge(['git'], $args), $repo);
};

$matches = static function (string $haystack, string $needle): bool {
    return str_contains($haystack, $needle);
};

$tempRepo = static function (string $name, string $version = '0.47.0') use ($root, $hookSource, $miSource, $git): string {
    $dir = $root . '/' . $name;
    mkdir($dir . '/components/utils', 0750, true);
    mkdir($dir . '/tests/manual', 0750, true);
    mkdir($dir . '/tools/hooks', 0750, true);
    mkdir($dir . '/.git/hooks', 0750, true);

    file_put_contents($dir . '/conf.yml', "name: \"Addon Manager\"\nversion: \"{$version}\"\nidentifier: \"modpackinstaller\"\n");
    file_put_contents($dir . '/components/utils/constants.ts', "export const EXTENSION_VERSION = \"{$version}\";\n");
    file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## {$version} — 2026-01-01\n");

    // A clone carries the hook in tools/hooks and links it into .git/hooks — mi hooks (install|remove) needs both.
    file_put_contents($dir . '/tools/hooks/pre-push', (string) file_get_contents($hookSource));
    chmod($dir . '/tools/hooks/pre-push', 0755);
    symlink($dir . '/tools/hooks/pre-push', $dir . '/.git/hooks/pre-push');

    copy($miSource, $dir . '/mi');
    chmod($dir . '/mi', 0755);

    // The checks stage is off by default in these repos so only the bump runs unless a test asks for it.
    $git($dir, 'init', '-q');
    $git($dir, 'config', 'user.email', 'test@example.com');
    $git($dir, 'config', 'user.name', 'Test');
    $git($dir, 'config', 'mi.hooks.checks', 'false');
    $git($dir, 'commit', '-q', '--allow-empty', '-m', 'chore: initial commit');

    return $dir;
};

// A terse wrapper around the real hook: it runs from the repo root and reports the exit code plus the output.
$push = static function (string $dir, string $localSha, string $remoteSha, array $env = [], array $config = []) use ($run): array {
    $stdin = [['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    if ($config !== []) {
        // This is how `git -c key=value push` hands an override to the hook for a single command.
        $pairs = [];

        foreach ($config as $key => $value) {
            $pairs[] = "'{$key}'='{$value}'";
        }

        $env['GIT_CONFIG_PARAMETERS'] = implode(' ', $pairs);
    }

    // getenv() carries PATH and friends through; $_ENV is empty under the usual variables_order setting.
    $process = proc_open([$dir . '/.git/hooks/pre-push'], $stdin, $pipes, $dir, array_merge(getenv(), $env));

    if (!is_resource($process)) {
        return [-1, ''];
    }

    $ref = 'refs/heads/main';
    fwrite($pipes[0], "{$ref} {$localSha} {$ref} {$remoteSha}\n");
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out . $err];
};

$commit = static function (string $dir, string $message, string $file = 'src.php') use ($git): string {
    file_put_contents($dir . '/' . $file, "<?php // {$message} " . bin2hex(random_bytes(4)) . "\n");
    $git($dir, 'add', $file);
    $git($dir, 'commit', '-q', '-m', $message);
    [$status, $sha] = $git($dir, 'rev-parse', 'HEAD');

    return trim($sha);
};

$version = static function (string $dir): string {
    preg_match('/version: "([^"]+)"/', (string) file_get_contents($dir . '/conf.yml'), $m);

    return $m[1] ?? '';
};

$changelog = static function (string $dir): string {
    return (string) file_get_contents($dir . '/CHANGELOG.md');
};

$head = static function (string $dir) use ($git): string {
    [$status, $sha] = $git($dir, 'rev-parse', 'HEAD');

    return trim($sha);
};

try {
    // --- feat → minor -------------------------------------------------------------------------------------
    $repo = $tempRepo('feat');
    $base = $head($repo);
    $sha = $commit($repo, 'feat: loader selector for curseforge');
    [$status, $out] = $push($repo, $sha, $base);

    $check($status === 1, 'a feat push stops so the bump can be committed');
    $check($version($repo) === '0.48.0', 'feat bumps the minor version (0.47.0 → 0.48.0)');
    $check($matches($out, '0.47.0 → 0.48.0'), 'the bump is reported in the hook output');
    $check($matches($changelog($repo), '### Features'), 'the changelog section is Features for a feat commit');
    $check($matches($changelog($repo), '- loader selector for curseforge'), 'the changelog uses the commit description');
    $check($matches($out, 'git commit --amend --no-edit'), 'the hook explains how to fold the bump');
    $check($matches($out, 'git -c mi.hooks.bump=false push'), 'the hook explains the per-push bypass');

    // Re-pushing the same commit must not bump it a second time.
    [$status, $out] = $push($repo, $sha, $base);
    $check($status === 0, 're-pushing an already versioned commit is allowed through');
    $check($version($repo) === '0.48.0', 're-pushing does not bump again');
    $check($matches($out, 'already versioned'), 'the hook says the commit was already versioned');

    // --- folding the bump with --amend --no-edit ----------------------------------------------------------
    $git($repo, 'commit', '-q', '--amend', '--no-edit');
    $amended = $head($repo);
    [$status, $out] = $push($repo, $amended, $base);

    $check($status === 0, 'the amended commit pushes through');
    $check($version($repo) === '0.48.0', 'an amend that already carries the version is not bumped again');
    $check($matches($out, 'was amended and already carries the version'), 'the hook reports the amend it detected');

    // --- fix → patch --------------------------------------------------------------------------------------
    $fix = $tempRepo('fix', '0.48.0');
    $base = $head($fix);
    $sha = $commit($fix, 'fix: wrong file counts on single-file updates');
    [$status, $out] = $push($fix, $sha, $base);

    $check($status === 1, 'a fix push stops for the bump');
    $check($version($fix) === '0.48.1', 'fix bumps the patch version');
    $check($matches($changelog($fix), '### Fixes'), 'the changelog section is Fixes for a fix commit');

    // --- chore → major ------------------------------------------------------------------------------------
    $chore = $tempRepo('chore', '0.48.1');
    $base = $head($chore);
    $sha = $commit($chore, 'chore: tidy the toolbar css');
    [$status, $out] = $push($chore, $sha, $base);

    $check($status === 1, 'a chore push stops for the bump');
    $check($version($chore) === '1.0.0', 'chore bumps the major version and resets minor/patch');
    $check($matches($changelog($chore), '### Changed'), 'the changelog section is Changed for a chore commit');

    // --- case insensitivity -------------------------------------------------------------------------------
    $case = $tempRepo('case', '1.2.3');
    $base = $head($case);
    $sha = $commit($case, 'FEAT(UI): Uppercase Subject');
    [$status, $out] = $push($case, $sha, $base);

    $check($status === 1, 'an uppercase FEAT push still bumps');
    $check($version($case) === '1.3.0', 'FEAT: is matched case-insensitively');
    $check($matches($changelog($case), 'Uppercase Subject'), 'the description keeps its original casing');

    $caseFix = $tempRepo('case-fix', '1.2.3');
    $base = $head($caseFix);
    $sha = $commit($caseFix, 'Fix: Something Broken');
    [$status, $out] = $push($caseFix, $sha, $base);

    $check($version($caseFix) === '1.2.4', 'Fix: is matched case-insensitively');

    $caseChore = $tempRepo('case-chore', '1.2.3');
    $base = $head($caseChore);
    $sha = $commit($caseChore, 'ChOrE: Mixed Case');
    [$status, $out] = $push($caseChore, $sha, $base);

    $check($version($caseChore) === '2.0.0', 'ChOrE: is matched case-insensitively');

    // --- commits that must not move the version -----------------------------------------------------------
    $plain = $tempRepo('plain', '0.47.0');
    $base = $head($plain);
    $sha = $commit($plain, 'docs: mention the new hook toggles');
    [$status, $out] = $push($plain, $sha, $base);

    $check($status === 0, 'a commit without a feat/fix/chore type pushes through');
    $check($version($plain) === '0.47.0', 'a non-conventional commit does not bump');
    $check($matches($out, 'no version applied'), 'the hook reports that nothing was versioned');

    // A commit that only writes the version files is the bump itself — it must never feed the bump again.
    $release = $tempRepo('release', '0.47.0');
    $base = $head($release);

    foreach (['conf.yml', 'CHANGELOG.md', 'components/utils/constants.ts'] as $file) {
        file_put_contents($release . '/' . $file, (string) file_get_contents($release . '/' . $file) . "\n");
    }

    $git($release, 'add', 'conf.yml', 'CHANGELOG.md', 'components/utils/constants.ts');
    $git($release, 'commit', '-q', '-m', 'chore: release 0.47.1');
    $sha = $head($release);

    [$status, $out] = $push($release, $sha, $base);
    $check($status === 0, 'a version-only commit pushes through');
    $check($version($release) === '0.47.0', 'a version-only commit does not trigger another bump');

    // Two fixes behind a merge commit: every versioned commit counts once and the merge itself counts for nothing.
    $merge = $tempRepo('merge', '0.47.0');
    $base = $head($merge);
    $git($merge, 'checkout', '-q', '-b', 'side');
    $commit($merge, 'fix: a side fix', 'side.php');
    $git($merge, 'checkout', '-q', '-');
    $commit($merge, 'fix: a mainline fix', 'main.php');
    $git($merge, 'merge', '-q', '--no-ff', '-m', "Merge branch 'side'", 'side');
    $sha = $head($merge);
    [$status, $out] = $push($merge, $sha, $base);

    $check($status === 1, 'a push with two fixes behind a merge bumps');
    $check($version($merge) === '0.47.2', 'each fix bumps once and the merge commit is skipped');
    $check($matches($out, 'version bumped from 2 commit(s)'), 'the hook counts both versioned commits');

    // --- toggles ------------------------------------------------------------------------------------------
    $off = $tempRepo('bump-off');
    $git($off, 'config', 'mi.hooks.bump', 'false');
    $base = $head($off);
    $sha = $commit($off, 'feat: should not be versioned');
    [$status, $out] = $push($off, $sha, $base);

    $check($status === 0, 'mi.hooks.bump=false lets the push through untouched');
    $check($version($off) === '0.47.0', 'mi.hooks.bump=false disables versioning');
    $check($matches($out, 'version bump off'), 'the hook says the bump is off');

    $envOff = $tempRepo('bump-env-off');
    $base = $head($envOff);
    $sha = $commit($envOff, 'feat: still should not be versioned');
    [$status, $out] = $push($envOff, $sha, $base, ['MI_SKIP_BUMP' => '1']);

    $check($status === 0, 'MI_SKIP_BUMP=1 lets the push through untouched');
    $check($version($envOff) === '0.47.0', 'MI_SKIP_BUMP=1 disables versioning');
    $check($matches($out, 'MI_SKIP_BUMP=1'), 'the hook names the reason it skipped');

    $flagOff = $tempRepo('bump-flag-off');
    $base = $head($flagOff);
    $sha = $commit($flagOff, 'feat: skipped by the per-push flag');
    [$status, $out] = $push($flagOff, $sha, $base, [], ['mi.hooks.bump' => 'false']);

    $check($status === 0, 'a per-command override lets the push through');
    $check($version($flagOff) === '0.47.0', 'the git -c override disables versioning for that push only');
    $check($git($flagOff, 'config', '--get', 'mi.hooks.bump')[1] === '', 'the override does not persist in the repository config');

    // --- the toggles as the CLI writes them ----------------------------------------------------------------
    $toggles = $tempRepo('toggles');

    [$status, $out] = $run([$toggles . '/mi', 'hooks', 'checks', 'off'], $toggles);
    [, $cfg] = $git($toggles, 'config', '--get', 'mi.hooks.checks');
    $check($status === 0 && trim($cfg) === 'false', 'mi hooks checks off writes mi.hooks.checks=false');

    [$status, $out] = $run([$toggles . '/mi', 'hooks', 'bump', 'off'], $toggles);
    [, $cfg] = $git($toggles, 'config', '--get', 'mi.hooks.bump');
    $check($status === 0 && trim($cfg) === 'false', 'mi hooks bump off writes mi.hooks.bump=false');

    [$status, $out] = $run([$toggles . '/mi', 'hooks'], $toggles);
    $check($status === 0, 'mi hooks reports the hook state');
    $check($matches($out, 'pre-push hook'), 'mi hooks names the hook');
    $check($matches($out, 'version bump') && $matches($out, 'off'), 'mi hooks shows a disabled toggle as off');
    $check($matches($out, 'mi.hooks.bump=false'), 'mi hooks shows how to skip one push');

    [$status, $out] = $run([$toggles . '/mi', 'hooks', 'bump', 'maybe'], $toggles);
    $check($status !== 0, 'mi hooks rejects a value that is neither on nor off');

    [$status, $out] = $run([$toggles . '/mi', 'hooks', 'checks', 'on'], $toggles);
    [, $cfg] = $git($toggles, 'config', '--get', 'mi.hooks.checks');
    $check($status === 0 && trim($cfg) === 'true', 'mi hooks checks on turns the checks back on');

    [$status, $out] = $run([$toggles . '/mi', 'hooks', 'remove'], $toggles);
    $check($status === 0 && !file_exists($toggles . '/.git/hooks/pre-push'), 'mi hooks remove unlinks the hook');

    // --- the checks stage ---------------------------------------------------------------------------------
    $stub = "#!/usr/bin/env bash\necho \"STUB-MI \$*\"\nexit \"\${STUB_MI_EXIT:-0}\"\n";

    $checks = $tempRepo('checks-on');
    file_put_contents($checks . '/mi', $stub);
    chmod($checks . '/mi', 0755);
    $git($checks, 'config', 'mi.hooks.checks', 'true');
    $base = $head($checks);
    $sha = $commit($checks, 'docs: no bump here');
    [$status, $out] = $push($checks, $sha, $base);

    $check($status === 0, 'a push passes when the checks pass');
    $check($matches($out, 'STUB-MI check'), 'the checks stage runs mi check by default');
    $check($matches($out, 'all checks passed'), 'the hook reports passing checks');

    [$status, $out] = $push($checks, $sha, $base, ['STUB_MI_EXIT' => '7']);
    $check($status === 7, 'a failing check stops the push with its exit code');
    $check($matches($out, 'checks failed'), 'the hook explains the failed checks');
    $check($matches($out, 'mi hooks checks off'), 'the failure text points at the toggle');

    [$status, $out] = $push($checks, $sha, $base, ['STUB_MI_EXIT' => '7', 'MI_SKIP_CHECKS' => '1']);
    $check($status === 0, 'MI_SKIP_CHECKS=1 bypasses a failing check');
    $check(!$matches($out, 'STUB-MI'), 'MI_SKIP_CHECKS=1 does not run the checks at all');

    $git($checks, 'config', 'mi.hooks.checks', 'false');
    [$status, $out] = $push($checks, $sha, $base, ['STUB_MI_EXIT' => '7']);
    $check($status === 0, 'mi.hooks.checks=false bypasses a failing check');
    $check($matches($out, 'checks disabled'), 'the hook says the checks are disabled');

    // --- through git itself, exactly as `mi hooks install` wires it up -------------------------------------
    $real = $tempRepo('real-push');
    $bare = $root . '/real-push-origin.git';
    mkdir($bare, 0750, true);
    $git($bare, 'init', '-q', '--bare');
    $git($real, 'remote', 'add', 'origin', $bare);
    $git($real, 'branch', '-M', 'main');

    // Point the link at the shipped hook file, exactly as `mi hooks install` does in a real clone.
    unlink($real . '/.git/hooks/pre-push');
    symlink($hookSource, $real . '/.git/hooks/pre-push');

    $commit($real, 'feat: pushed through real git');
    [$status, $out] = $git($real, 'push', 'origin', 'main');

    $check($status !== 0, 'git itself stops the push after a bump');
    $check($version($real) === '0.48.0', 'the version is bumped during a real git push');
    $check($matches($out, 'version bumped from 1 commit(s)'), 'git surfaces the hook summary');

    [$stageStatus, $staged] = $git($real, 'diff', '--cached', '--name-only');
    $check($stageStatus === 0 && $matches($staged, 'conf.yml'), 'the bump is staged for the amend');

    $git($real, 'commit', '-q', '--amend', '--no-edit');
    [$status, $out] = $git($real, 'push', 'origin', 'main');

    $check($status === 0, 'the amended commit pushes through real git');
    $check($version($real) === '0.48.0', 'the second push does not bump a third time');

    [, $remoteConf] = $git($real, 'show', 'origin/main:conf.yml');
    $check($matches($remoteConf, 'version: "0.48.0"'), 'the pushed commit carries the bump');

    // git runs the hook for --dry-run as well, so a previewed push must leave the version alone.
    $commit($real, 'feat: previewed but not pushed');
    [$status, $out] = $git($real, 'push', '--dry-run', 'origin', 'main');

    $check($status === 0, 'git push --dry-run is not stopped by the hook');
    $check($version($real) === '0.48.0', 'git push --dry-run does not write a version');
    $check($matches($out, 'dry run — no version applied'), 'the hook says it saw a dry run');

    // A tag points at a commit the branch push already covers, so it is never versioned on its own.
    $git($real, 'tag', 'v0.48.0');
    [$status, $out] = $git($real, 'push', 'origin', 'v0.48.0');

    $check($status === 0, 'pushing a tag is not stopped by the hook');
    $check($version($real) === '0.48.0', 'a tag push does not bump the version');

    // --- refs the hook must ignore ------------------------------------------------------------------------
    $deleting = $tempRepo('delete');
    $base = $head($deleting);
    [$status, $out] = $push($deleting, str_repeat('0', 40), $base);
    $check($status === 0, 'deleting a branch is not versioned');

    $first = $tempRepo('first-push');
    $sha = $commit($first, 'feat: the very first push');
    [$status, $out] = $push($first, $sha, str_repeat('0', 40));
    $check($status === 1, 'a brand new remote still versions its tip commit');
    $check($version($first) === '0.48.0', 'a first push bumps from the tip commit only');

    $empty = $tempRepo('nothing-new');
    $sha = $head($empty);
    [$status, $out] = $push($empty, $sha, $sha);
    $check($status === 0, 'pushing nothing new is a no-op');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $remove($path . '/' . $entry);
        }

        @rmdir($path);
    };

    $remove($root);
}

echo "\n{$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
