<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use InvalidArgumentException;

// Validates and normalizes server-relative file paths before they are sent to any target (local filesystem or Wings).
final class ServerRelativePath
{
    private function __construct()
    {
    }

    public static function normalize(string $path): string
    {
        $normalized = self::assertRelative($path);

        $kept = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $kept[] = $segment;
        }

        if ($kept === []) {
            throw new InvalidArgumentException(
                'A relative server path is required.'
            );
        }

        return implode('/', $kept);
    }

    public static function normalizeDirectory(string $directory): string
    {
        $directory = trim($directory);

        if ($directory === '/') {
            return '/';
        }

        return self::normalize($directory);
    }

    private static function assertRelative(string $path): string
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException(
                'A relative server path is required.'
            );
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException(
                'The server path cannot contain NUL bytes.'
            );
        }

        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException(
                'Absolute server paths are not allowed.'
            );
        }

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new InvalidArgumentException(
                'Windows drive server paths are not allowed.'
            );
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(
                    'Parent traversal is not allowed in server paths.'
                );
            }
        }

        return $normalized;
    }
}