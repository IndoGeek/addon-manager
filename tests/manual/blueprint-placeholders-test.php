<?php

// Guards against Blueprint placeholder collisions. Blueprint's installer runs sed over every file it packages and
// substitutes literal tokens such as {version}, {name} and {target}, so a bare {version} inside our TSX/PHP silently
// becomes the extension version — invalid JavaScript, a failed webpack build, and a blank panel. This suite fails
// whenever a shipped file contains one.

$root = dirname(__DIR__, 2);

// The token names are listed without braces and this suite is skipped by its own walk (see $skipPaths), so the file
// never flags itself for the sample collision it uses below.
$placeholderNames = [
    'identifier',
    'name',
    'author',
    'version',
    'random',
    'timestamp',
    'mode',
    'target',
    'root',
    'webroot',
    'viewcontext',
    'appcontext',
    'engine',
    'fs',
    'is_target',
];

$quoted = array_map(
    static fn (string $name): string => preg_quote($name, '/'),
    $placeholderNames,
);

// {name} plus the modifier ({name!}, {identifier^}) and compound ({root/public}, {fs/private}) forms.
$pattern = '/\{(' . implode('|', $quoted) . ')(?:[!^]|\/[a-z]+)?\}/';

$skipDirs = ['.git', 'node_modules', '.blueprint', 'vendor'];

// Only content the panel actually loads is checked. Prose (README, CHANGELOG, INSTALLATION) legitimately names the
// tokens when explaining them, and Blueprint rewriting a sentence changes nothing that runs.
$scannedExtensions = ['php', 'ts', 'tsx', 'js', 'jsx', 'css', 'yml', 'yaml', 'json'];

// Mirrors what tools/installer.sh packages into the .blueprint archive: the tooling is excluded, so it never reaches
// Blueprint's placeholder pass.
$skipPaths = ['mi', 'tools', 'tests', '.github'];

$offenders = [];

$scanned = 0;

$walk = static function (string $directory) use (
    &$walk,
    &$offenders,
    &$scanned,
    $pattern,
    $root,
    $skipDirs,
    $skipPaths,
    $scannedExtensions,
): void {
    $entries = @scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;
        $relative = ltrim(substr($path, strlen($root)), '/');

        if (is_dir($path)) {
            if (in_array($entry, $skipDirs, true) || in_array($relative, $skipPaths, true)) {
                continue;
            }

            $walk($path);

            continue;
        }

        if (!is_file($path) || in_array($relative, $skipPaths, true)) {
            continue;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, $scannedExtensions, true)) {
            continue;
        }

        $contents = @file_get_contents($path);

        // Binary assets (images, fonts) never carry source tokens.
        if ($contents === false || str_contains($contents, "\0")) {
            continue;
        }

        $scanned++;

        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[0] as [$token, $offset]) {
            $line = substr_count($contents, "\n", 0, $offset) + 1;

            $offenders[] = "{$relative}:{$line}  {$token}";
        }
    }
};

$walk($root);

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

$check(
    $offenders === [],
    'no shipped file contains a Blueprint placeholder token',
);

// A walk that silently read nothing would pass forever, so prove it covered the real source tree.
$check(
    $scanned > 20,
    "the scan covered the shipped source ({$scanned} files)",
);

if ($offenders !== []) {
    echo "\nBlueprint rewrites these tokens while installing, which corrupts the file:\n";

    foreach ($offenders as $offender) {
        echo "  ✗ {$offender}\n";
    }

    echo "\nFix: rename the variable so the literal token never appears (e.g. {version} → {gameVersion}),\n";
    echo "or escape it as !{...} where the text is not code.\n";
}

// The scan must actually be able to see a collision, otherwise a passing run means nothing.
$probe = "const probe = <span key={version}>{version}</span>;\n";

$check(
    preg_match($pattern, $probe) === 1,
    'the scanner detects a real collision in sample JSX',
);

echo "\n{$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
