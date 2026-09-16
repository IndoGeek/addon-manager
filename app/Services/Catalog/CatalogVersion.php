<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * A normalized catalog version/release. Fields are nullable where a provider
 * has no equivalent. The "source" field is the canonical installable source
 * for this exact version (for example modrinth://slug@versionId), which the
 * installation providers resolve directly.
 */
final readonly class CatalogVersion
{
    /**
     * @param array<string> $gameVersions
     * @param array<string> $loaders
     */
    public function __construct(
        public string $provider,
        public string $projectId,
        public ?string $projectSlug,
        public ?string $projectName,
        public string $versionId,
        public string $versionNumber,
        public ?string $versionName,
        public array $gameVersions,
        public array $loaders,
        public ?string $datePublished,
        public ?string $dateModified,
        public ?int $downloads,
        public string $source,
        public ?int $fileSize,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'project_id' => $this->projectId,
            'project_slug' => $this->projectSlug,
            'project_name' => $this->projectName,
            'version_id' => $this->versionId,
            'version_number' => $this->versionNumber,
            'version_name' => $this->versionName,
            'game_versions' => $this->gameVersions,
            'loaders' => $this->loaders,
            'date_published' => $this->datePublished,
            'date_modified' => $this->dateModified,
            'downloads' => $this->downloads,
            'source' => $this->source,
            'file_size' => $this->fileSize,
        ];
    }

    /**
     * Rebuilds a version from toArray() output (cache hydration).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['provider'] ?? ''),
            (string) ($data['project_id'] ?? ''),
            isset($data['project_slug']) && is_string($data['project_slug'])
                ? $data['project_slug']
                : null,
            isset($data['project_name']) && is_string($data['project_name'])
                ? $data['project_name']
                : null,
            (string) ($data['version_id'] ?? ''),
            (string) ($data['version_number'] ?? ''),
            isset($data['version_name']) && is_string($data['version_name'])
                ? $data['version_name']
                : null,
            is_array($data['game_versions'] ?? null)
                ? array_values($data['game_versions'])
                : [],
            is_array($data['loaders'] ?? null)
                ? array_values($data['loaders'])
                : [],
            isset($data['date_published']) && is_string($data['date_published'])
                ? $data['date_published']
                : null,
            isset($data['date_modified']) && is_string($data['date_modified'])
                ? $data['date_modified']
                : null,
            isset($data['downloads']) && is_int($data['downloads'])
                ? $data['downloads']
                : null,
            (string) ($data['source'] ?? ''),
            isset($data['file_size']) && is_int($data['file_size'])
                ? $data['file_size']
                : null,
        );
    }
}
