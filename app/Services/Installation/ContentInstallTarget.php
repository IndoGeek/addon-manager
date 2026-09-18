<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;

// Where each single-file catalog content type lands on the server, and how its downloaded file is named.
final class ContentInstallTarget
{
    public const DEFAULT_TYPE = 'mod';

    // The kind of a full modpack install; single-file kinds are the keys of TYPES below.
    public const KIND_MODPACK = 'modpack';

    // @var array<string, array{kind: string, directory: string, extensions: array<int, string>, label: string}>
    private const TYPES = [
        'mod' => [
            'kind' => 'mod',
            'directory' => 'mods',
            'extensions' => ['jar', 'litemod'],
            'label' => 'mod',
        ],
        'plugin' => [
            'kind' => 'plugin',
            'directory' => 'plugins',
            'extensions' => ['jar'],
            'label' => 'plugin',
        ],
        'datapack' => [
            'kind' => 'datapack',
            'directory' => 'world/datapacks',
            'extensions' => ['zip'],
            'label' => 'datapack',
        ],
        'resourcepack' => [
            'kind' => 'resourcepack',
            'directory' => 'resourcepacks',
            'extensions' => ['zip'],
            'label' => 'resource pack',
        ],
        'shader' => [
            'kind' => 'shader',
            'directory' => 'shaderpacks',
            'extensions' => ['zip'],
            'label' => 'shader',
        ],
    ];

    public static function supports(string $contentType): bool
    {
        return isset(self::TYPES[$contentType]);
    }

    // @return array{kind: string, directory: string, extensions: array<int, string>, label: string}
    public static function for(string $contentType): array
    {
        if (!self::supports($contentType)) {
            throw new InvalidArgumentException(
                'Unsupported content type.',
            );
        }

        return self::TYPES[$contentType];
    }

    // The kind of content a recorded file belongs to, inferred from where it was placed.
    public static function kindForPath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach (self::TYPES as $kind => $definition) {
            if (str_starts_with($path, $definition['directory'] . '/')) {
                return $kind;
            }
        }

        return null;
    }

    // Whether a kind is one this extension knows how to install.
    public static function supportsKind(string $kind): bool
    {
        return $kind === self::KIND_MODPACK
            || self::supports($kind);
    }

    // Where a recommended *dependency* file belongs, which is not always the same place as the content the user pic...
    public static function forDependency(
        array $mainTarget,
        string $filename,
    ): array {
        // Mod and plugin installs are jar-packaged, and so are all of their dependencies.
        if (!in_array('zip', $mainTarget['extensions'], true)) {
            return $mainTarget;
        }

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION),
        );

        if (!in_array($extension, ['jar', 'litemod'], true)) {
            return $mainTarget;
        }

        return self::for('mod');
    }

    // Builds the on-server file name from the user's selections, e.g.
    public static function filename(
        string $originalFilename,
        string $displayName,
        ?string $mcVersion,
        ?string $loader,
        array $extensions,
    ): string {
        $fallback = $extensions[0] ?? 'jar';

        $extension = strtolower(
            pathinfo($originalFilename, PATHINFO_EXTENSION),
        );

        if ($extension === '' || !in_array($extension, $extensions, true)) {
            $extension = $fallback;
        }

        $slug = static function (string $value): string {
            $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';

            return strtolower(trim($value, '-'));
        };

        $parts = array_filter([
            $slug($displayName),
            $loader !== null ? $slug($loader) : null,
            $mcVersion !== null ? $slug($mcVersion) : null,
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        if ($parts === []) {
            // The display name is validated non-empty upstream, but a safe fallback beats an empty filename.
            $parts = ['content'];
        }

        return implode('-', $parts) . '.' . $extension;
    }
}
