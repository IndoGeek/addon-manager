<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

use InvalidArgumentException;

// Resolves an HTTP redirect Location header against the request URL using RFC 3986 reference resolution.
final class RedirectResolver
{
    private function __construct()
    {
    }

    public static function resolve(string $baseUrl, string $location): string
    {
        $base = self::parseAbsoluteUrl($baseUrl);

        if (trim($location) === '') {
            throw new InvalidArgumentException(
                'The download server redirected without a valid location.',
            );
        }

        $reference = self::parseReference($location);

        $scheme = $reference['scheme'] ?? $base['scheme'];
        $authority = $reference['authority'] ?? $base['authority'];

        $path = $reference['path'] ?? '';

        if (
            !isset($reference['authority'])
            && !isset($reference['scheme'])
        ) {
            $path = self::mergePaths($base['path'], $path);
        }

        if ($path !== '') {
            $path = self::normalizePath($path);
        }

        if (!isset($reference['query']) && $reference['scheme'] === null) {
            $query = $base['query'] ?? '';
        } else {
            $query = $reference['query'] ?? '';
        }

        $url = $scheme . '://' . $authority . $path;

        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    // @return array{scheme: string, authority: string, path: string, query: ?string}
    private static function parseAbsoluteUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'The download URL is invalid.',
            );
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (!is_string($scheme) || !is_string($host)) {
            throw new InvalidArgumentException(
                'The download URL is invalid.',
            );
        }

        return [
            'scheme' => strtolower($scheme),
            'authority' => self::authorityFromParts($parts),
            'path' => $parts['path'] ?? '/',
            'query' => isset($parts['query']) ? (string) $parts['query'] : null,
        ];
    }

    // @return array{scheme: ?string, authority: ?string, path: ?string, query: ?string}
    private static function parseReference(string $location): array
    {
        if (str_starts_with($location, '//')) {
            return self::parseProtocolRelativeReference($location);
        }

        $parts = parse_url($location);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'The download server redirected to an invalid location.',
            );
        }

        if (isset($parts['scheme'])) {
            $scheme = strtolower((string) $parts['scheme']);

            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException(
                    'The download server redirected to a non-HTTP location.',
                );
            }
        } else {
            $scheme = null;
        }

        return [
            'scheme' => $scheme,
            'authority' => isset($parts['host'])
                ? self::authorityFromParts($parts)
                : null,
            'path' => isset($parts['path'])
                ? (string) $parts['path']
                : null,
            'query' => isset($parts['query'])
                ? (string) $parts['query']
                : null,
        ];
    }

    // Network-path references inherit the scheme of the base URL.
    private static function parseProtocolRelativeReference(
        string $location,
    ): array {
        $relative = substr($location, 2);

        $slashPosition = strpos($relative, '/');

        if ($slashPosition === false) {
            $authority = $relative;
            $rest = '';
        } else {
            $authority = substr($relative, 0, $slashPosition);
            $rest = substr($relative, $slashPosition);
        }

        $parts = parse_url('http://' . $authority);

        if ($parts === false || !isset($parts['host'])) {
            throw new InvalidArgumentException(
                'The download server redirected to an invalid location.',
            );
        }

        $query = null;

        if ($rest !== '') {
            $questionPosition = strpos($rest, '?');

            if ($questionPosition !== false) {
                $query = substr($rest, $questionPosition + 1);
                $rest = substr($rest, 0, $questionPosition);
            }
        }

        return [
            'scheme' => null,
            'authority' => self::authorityFromParts($parts),
            'path' => $rest === ''
                ? null
                : self::normalizePath($rest),
            'query' => $query === '' ? null : $query,
        ];
    }

    // @param array<string, mixed> $parts parse_url() result.
    private static function authorityFromParts(array $parts): string
    {
        $host = $parts['host'];

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'Redirect locations cannot contain credentials.',
            );
        }

        $authority = $host;

        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private static function mergePaths(string $basePath, string $referencePath): string
    {
        if ($referencePath === '') {
            return $basePath;
        }

        if (str_starts_with($referencePath, '/')) {
            return self::normalizePath($referencePath);
        }

        if (str_ends_with($basePath, '/')) {
            $baseDirectory = $basePath;
        } else {
            $baseDirectory = rtrim(dirname($basePath), '/') . '/';
        }

        return self::normalizePath($baseDirectory . $referencePath);
    }

    // Removes "." and resolves ".." segments, ensuring the normalized path stays rooted at "/".
    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $kept = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($kept !== []) {
                    array_pop($kept);
                }
                continue;
            }

            $kept[] = $segment;
        }

        $normalized = '/' . implode('/', $kept);

        if ($normalized === '/') {
            return '/';
        }

        return $normalized;
    }
}