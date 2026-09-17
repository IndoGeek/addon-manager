<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

// A single normalized catalog entry.
final readonly class CatalogItem
{
    // @param array<string> $categories @param array<string> $gameVersions @param array<string> $loaders
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

    // @return array<string, mixed>
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

    // Rebuilds an item from toArray() output (cache hydration).
    public static function fromArray(array $data): self
    {
        $stringOrNull = static function ($value): ?string {
            return is_string($value) && $value !== '' ? $value : null;
        };

        $intOrNull = static function ($value): ?int {
            return is_int($value) ? $value : null;
        };

        $listOrNull = static function ($value): array {
            return is_array($value) ? array_values($value) : [];
        };

        return new self(
            (string) ($data['provider'] ?? ''),
            (string) ($data['provider_project_id'] ?? ''),
            $stringOrNull($data['slug'] ?? null),
            (string) ($data['name'] ?? ''),
            $stringOrNull($data['summary'] ?? null),
            $stringOrNull($data['icon_url'] ?? null),
            $stringOrNull($data['project_url'] ?? null),
            $intOrNull($data['downloads'] ?? null),
            $intOrNull($data['follows'] ?? null),
            $listOrNull($data['categories'] ?? null),
            $listOrNull($data['game_versions'] ?? null),
            $listOrNull($data['loaders'] ?? null),
            $stringOrNull($data['latest_version'] ?? null),
            (string) ($data['source'] ?? ''),
            $stringOrNull($data['author'] ?? null),
            $stringOrNull($data['updated_at'] ?? null),
            $stringOrNull($data['banner_url'] ?? null),
            $stringOrNull($data['environment'] ?? null),
        );
    }
}
