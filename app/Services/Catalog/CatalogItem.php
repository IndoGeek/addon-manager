<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * A single normalized catalog entry. Fields are nullable where a provider
 * has no equivalent, so every provider can populate the same contract.
 */
final readonly class CatalogItem
{
    /**
     * @param array<string> $categories
     * @param array<string> $gameVersions
     * @param array<string> $loaders
     */
    public function __construct(
        public string $provider,
        public string $providerProjectId,
        public ?string $slug,
        public string $name,
        public ?string $summary,
        public ?string $iconUrl,
        public ?string $projectUrl,
        public ?int $downloads,
        public ?int $follows,
        public array $categories,
        public array $gameVersions,
        public array $loaders,
        public ?string $latestVersion,
        public string $source,
        public ?string $author,
        public ?string $updatedAt,
        public ?string $bannerUrl,
        public ?string $environment,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_project_id' => $this->providerProjectId,
            'slug' => $this->slug,
            'name' => $this->name,
            'summary' => $this->summary,
            'icon_url' => $this->iconUrl,
            'project_url' => $this->projectUrl,
            'downloads' => $this->downloads,
            'follows' => $this->follows,
            'categories' => $this->categories,
            'game_versions' => $this->gameVersions,
            'loaders' => $this->loaders,
            'latest_version' => $this->latestVersion,
            'source' => $this->source,
            'author' => $this->author,
            'updated_at' => $this->updatedAt,
            'banner_url' => $this->bannerUrl,
            'environment' => $this->environment,
        ];
    }
}