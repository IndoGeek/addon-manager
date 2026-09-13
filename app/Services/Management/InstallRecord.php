<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerRelativePath;

/**
 * Immutable record of one successfully installed modpack for one server.
 *
 * The record is owned by the extension (stored outside any Pterodactyl core
 * table) and carries everything needed to later update or uninstall the pack
 * without trusting the client: the normalized source, the resolved version,
 * and the ownership manifest of relative paths deployed by the engine.
 */
final class InstallRecord
{
    public const STATUS_INSTALLED = 'installed';

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
        public readonly string $layout,
        public readonly string $policy,
        public readonly string $installedAt,
        public readonly string $updatedAt,
        public readonly string $status,
        /** @var list<string> */
        public readonly array $createdFiles,
        /** @var list<string> */
        public readonly array $overwrittenFiles,
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

    /**
     * The unique set of relative paths this install owns, in the order they
     * should be removed (created first, then overwritten).
     *
     * @return list<string>
     */
    public function ownedFiles(): array
    {
        return array_values(
            array_unique(
                array_merge($this->createdFiles, $this->overwrittenFiles),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
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
            'layout' => $this->layout,
            'policy' => $this->policy,
            'installed_at' => $this->installedAt,
            'updated_at' => $this->updatedAt,
            'status' => $this->status,
            'ownership' => [
                'created' => $this->createdFiles,
                'overwritten' => $this->overwrittenFiles,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
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
            layout: (string) ($data['layout'] ?? ''),
            policy: (string) ($data['policy'] ?? ''),
            installedAt: (string) ($data['installed_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_INSTALLED),
            createdFiles: $created,
            overwrittenFiles: $overwritten,
        );
    }

    /**
     * Validates and normalizes a stored ownership list so that a corrupted or
     * tampered store can never reintroduce unsafe paths into the engine.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
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