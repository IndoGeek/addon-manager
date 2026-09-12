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
        ];
    }
}
