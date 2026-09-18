<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\ContentInstallTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerRelativePath;

// Immutable record of one successfully installed modpack for one server.
final class InstallRecord
{
    public const STATUS_INSTALLED = 'installed';

    public const TYPE_MODPACK = 'modpack';

    /** Single-file content (mods, future plugins etc.) — uninstall must
     * never touch the containing directory, only the recorded files. */
    public const TYPE_CONTENT = 'content';

    public function __construct(
        public readonly string $id,
        public readonly string $serverUuid,
        public readonly string $provider,
        public readonly string $projectId,
        public readonly ?string $versionId,
        public readonly string $source,
        public readonly string $displayName,
        public readonly string $version,
        public readonly ?string $minecraftVersion,
        public readonly ?string $loader,
        public readonly ?string $iconUrl,
        public readonly string $installedAt,
        public readonly string $updatedAt,
        public readonly string $status,
        /** @var list<string> */
        public readonly array $createdFiles,
        /** @var list<string> */
        public readonly array $overwrittenFiles,
        public readonly string $contentType = self::TYPE_MODPACK,
        /** Which catalog kind this is: modpack, mod, plugin, datapack,
         * resourcepack, or shader. */
        public readonly string $contentKind = ContentInstallTarget::KIND_MODPACK,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException(
                'An install record id is required.'
            );
        }

        if ($serverUuid === '') {
            throw new InvalidArgumentException(
                'A server uuid is required.'
            );
        }

        if ($provider === '') {
            throw new InvalidArgumentException(
                'A provider is required.'
            );
        }

        if ($source === '') {
            throw new InvalidArgumentException(
                'A source is required.'
            );
        }
    }

    // The unique set of relative paths this install owns, in the order they should be removed (created first, then...
    public function ownedFiles(): array
    {
        return array_values(
            array_unique(
                array_merge($this->createdFiles, $this->overwrittenFiles),
            ),
        );
    }

    // @return array<string, mixed>
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'server_uuid' => $this->serverUuid,
            'provider' => $this->provider,
            'project_id' => $this->projectId,
            'version_id' => $this->versionId,
            'source' => $this->source,
            'display_name' => $this->displayName,
            'version' => $this->version,
            'minecraft_version' => $this->minecraftVersion,
            'loader' => $this->loader,
            'icon_url' => $this->iconUrl,
            'installed_at' => $this->installedAt,
            'updated_at' => $this->updatedAt,
            'status' => $this->status,
            'content_type' => $this->contentType,
            'content_kind' => $this->contentKind,
            'ownership' => [
                'created' => $this->createdFiles,
                'overwritten' => $this->overwrittenFiles,
            ],
        ];
    }

    // @param array<string, mixed> $data
    public static function fromArray(array $data): self
    {
        $ownership = is_array($data['ownership'] ?? null)
            ? $data['ownership']
            : [];

        $created = self::ownedFileList($ownership['created'] ?? []);
        $overwritten = self::ownedFileList($ownership['overwritten'] ?? []);

        return new self(
            id: (string) ($data['id'] ?? ''),
            serverUuid: (string) ($data['server_uuid'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            projectId: (string) ($data['project_id'] ?? ''),
            versionId: self::nullableString($data['version_id'] ?? null),
            source: (string) ($data['source'] ?? ''),
            displayName: (string) ($data['display_name'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            minecraftVersion: self::nullableString($data['minecraft_version'] ?? null),
            loader: self::nullableString($data['loader'] ?? null),
            iconUrl: self::nullableString($data['icon_url'] ?? null),
            installedAt: (string) ($data['installed_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_INSTALLED),
            createdFiles: $created,
            overwrittenFiles: $overwritten,
            contentType: $contentType = self::contentTypeOrDefault(
                $data['content_type'] ?? null,
            ),
            contentKind: self::contentKindFrom(
                $data['content_kind'] ?? null,
                $created,
                $contentType,
            ),
        );
    }

    // The stored kind when it is one we know; otherwise inferred from where
    // the record's files were placed, so records written before the kind was
    // stored still label themselves correctly.
    // @param list<string> $createdFiles
    private static function contentKindFrom(
        mixed $value,
        array $createdFiles,
        string $contentType,
    ): string {
        if (is_string($value) && ContentInstallTarget::supportsKind($value)) {
            return $value;
        }

        if ($contentType === self::TYPE_MODPACK) {
            return ContentInstallTarget::KIND_MODPACK;
        }

        foreach ($createdFiles as $path) {
            $kind = ContentInstallTarget::kindForPath((string) $path);

            if ($kind !== null) {
                return $kind;
            }
        }

        // A content record whose file we cannot place: a mod is the only
        // single-file kind that existed before the others.
        return ContentInstallTarget::DEFAULT_TYPE;
    }

    // Records created before content installs existed have no content_type;
    // anything unrecognized stays a modpack so uninstall keeps its old,
    // more aggressive semantics unless the record explicitly says otherwise.
    private static function contentTypeOrDefault(mixed $value): string
    {
        if ($value === self::TYPE_CONTENT) {
            return self::TYPE_CONTENT;
        }

        return self::TYPE_MODPACK;
    }

    // Validates and normalizes a stored ownership list so that a corrupted or tampered store can never reintroduce unsafe...
    private static function ownedFileList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $path) {
            if (!is_string($path)) {
                continue;
            }

            try {
                $paths[] = ServerRelativePath::normalize($path);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}