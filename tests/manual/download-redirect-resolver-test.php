<?php

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\RedirectResolver;

require __DIR__ . '/../../app/Services/Download/RedirectResolver.php';

function assertResolves(string $base, string $location, string $expected): void
{
    $actual = RedirectResolver::resolve($base, $location);

    if ($actual !== $expected) {
        throw new RuntimeException(
            "Expected \"{$expected}\" for \"{$location}\", got \"{$actual}\".",
        );
    }

    echo "PASS: {$location} => {$expected}\n";
}

function assertRejected(string $base, string $location): void
{
    try {
        RedirectResolver::resolve($base, $location);

        throw new RuntimeException(
            "Expected rejection for location \"{$location}\".",
        );
    } catch (\InvalidArgumentException) {
        echo "PASS: rejected {$location}\n";
    }
}

$base = 'https://cdn.example/modpack/pack.zip';

assertResolves($base, 'https://other.example/file.zip', 'https://other.example/file.zip');
assertResolves($base, 'http://other.example/file.zip', 'http://other.example/file.zip');
assertResolves($base, '//other.example/file.zip', 'https://other.example/file.zip');
assertResolves($base, '/file.zip', 'https://cdn.example/file.zip');
assertResolves($base, './file.zip', 'https://cdn.example/modpack/file.zip');
assertResolves($base, 'file.zip', 'https://cdn.example/modpack/file.zip');
assertResolves($base, '../file.zip', 'https://cdn.example/file.zip');
assertResolves($base, '../../up.zip', 'https://cdn.example/up.zip');
assertResolves($base, 'a/../b.zip', 'https://cdn.example/modpack/b.zip');
assertResolves($base, '.hidden', 'https://cdn.example/modpack/.hidden');
assertResolves($base, 'sub/dir.zip', 'https://cdn.example/modpack/sub/dir.zip');
assertResolves($base, 'https://cdn.example/modpack/pack.zip?new=1', 'https://cdn.example/modpack/pack.zip?new=1');
assertResolves($base, '?new=1', 'https://cdn.example/modpack/pack.zip?new=1');
assertResolves($base, '#fragment', 'https://cdn.example/modpack/pack.zip');
assertResolves('https://cdn.example/file', 'rel.txt', 'https://cdn.example/rel.txt');
assertResolves('https://cdn.example/dir/', 'rel.txt', 'https://cdn.example/dir/rel.txt');
assertResolves($base, 'https://cdn.example/dir/../../escape.txt', 'https://cdn.example/escape.txt');
assertResolves($base, 'a//double//slash.zip', 'https://cdn.example/modpack/a/double/slash.zip');

assertRejected($base, '');
assertRejected($base, '   ');
assertRejected($base, 'ftp://cdn.example/file.zip');
assertRejected($base, 'file:///etc/passwd');
assertRejected($base, 'https://user:pass@cdn.example/file.zip');
assertRejected($base, 'https://user@cdn.example/file.zip');

echo "\nAll redirect resolver tests passed.\n";