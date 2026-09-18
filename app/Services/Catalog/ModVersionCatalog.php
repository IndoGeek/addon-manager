<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;
use RuntimeException;

// Version catalog for single-file content (mods, plugins, datapacks, resource packs, shaders).
final class ModVersionCatalog
{
    private const API_BASE = 'https://api.modrinth.com/v2';

    private const PROJECT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    /** Every public version of a project, enriched with loader/game-version options and resolvable dependency titles. */
    public function versions(string $project): array
    {
        if (preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            throw new CatalogProviderException(
                'Invalid project parameter.',
            );
        }

        $payload = $this->fetch(
            self::API_BASE
                . '/project/'
                . rawurlencode($project)
                . '/version',
        );

        $dependencyIds = [];

        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                throw new CatalogProviderException(
                    'The catalog provider returned an invalid response.',
                );
            }

            foreach ($this->recommendDependencies($entry) as $dependency) {
                $id = $dependency['project_id'];

                if ($id !== '') {
                    $dependencyIds[$id] = true;
                }
            }
        }

        $projects = $this->resolveProjects(array_keys($dependencyIds));

        $versions = [];

        foreach ($payload as $entry) {
            if (!$this->isPublicVersion($entry)) {
                continue;
            }

            $versions[] = $this->mapVersion(
                $project,
                $entry,
                $projects,
            );
        }

        return [
            'provider' => 'modrinth',
            'project' => $project,
            'versions' => $versions,
        ];
    }

    // @return array<int, array<string, mixed>>
    private function recommendDependencies(array $version): array
    {
        $dependencies = $version['dependencies'] ?? null;

        if (!is_array($dependencies)) {
            return [];
        }

        $recommended = [];

        foreach ($dependencies as $dependency) {
            if (
                !is_array($dependency)
                || !in_array(
                    $dependency['dependency_type'] ?? '',
                    ['required', 'optional'],
                    true,
                )
            ) {
                continue;
            }

            $projectId = $dependency['project_id'] ?? null;

            if (!is_string($projectId) || $projectId === '') {
                continue;
            }

            $recommended[] = [
                'project_id' => $projectId,
                'type' => (string) $dependency['dependency_type'],
            ];
        }

        return $recommended;
    }

    // Dependency entries only carry project ids; batch-resolve the human title, slug, and icon so the UI can render...
    private function resolveProjects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $payload = $this->fetch(
                self::API_BASE . '/projects',
                ['ids' => json_encode(array_values($ids))],
            );
        } catch (CatalogProviderException) {
            // Titles are cosmetic recommendations; a failed lookup must never break the version list itself.
            return [];
        }

        $projects = [];

        foreach ($payload as $project) {
            if (
                is_array($project)
                && is_string($project['id'] ?? null)
                && is_string($project['title'] ?? null)
            ) {
                $slug = $project['slug'] ?? null;
                $icon = $project['icon_url'] ?? null;

                $projects[$project['id']] = [
                    'title' => $project['title'],
                    'slug' => is_string($slug) && $slug !== ''
                        ? $slug
                        : null,
                    'icon_url' => is_string($icon) && $icon !== ''
                        ? $icon
                        : null,
                ];
            }
        }

        return $projects;
    }

    // @return array<string, mixed>
    private function mapVersion(
        string $project,
        array $version,
        array $projects,
    ): array {
        $versionId = is_string($version['id'] ?? null)
            ? $version['id']
            : '';

        $file = $this->primaryFile($version['files'] ?? null);

        $dependencies = [];

        foreach ($this->recommendDependencies($version) as $dependency) {
            $resolved = $projects[$dependency['project_id']] ?? null;

            $dependencies[] = [
                'project_id' => $dependency['project_id'],
                'title' => $resolved['title']
                    ?? $dependency['project_id'],
                'slug' => $resolved['slug'] ?? null,
                'icon_url' => $resolved['icon_url'] ?? null,
                'type' => $dependency['type'],
            ];
        }

        return [
            'version_id' => $versionId,
            'version_number' => is_string($version['version_number'] ?? null)
                ? $version['version_number']
                : '',
            'loaders' => $this->stringList($version['loaders'] ?? []),
            'game_versions' => $this->stringList(
                $version['game_versions'] ?? [],
            ),
            'date_published' => is_string($version['date_published'] ?? null)
                ? $version['date_published']
                : null,
            'downloads' => is_int($version['downloads'] ?? null)
                ? $version['downloads']
                : null,
            'file_size' => is_int($file['size'] ?? null)
                ? $file['size']
                : null,
            'source' => $versionId === ''
                ? 'modrinth://' . $project
                : 'modrinth://' . $project . '@' . $versionId,
            'dependencies' => $dependencies,
        ];
    }

    // @param mixed $files @return array<string, mixed>
    private function primaryFile(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['primary'] ?? false) === true) {
                return $file;
            }
        }

        foreach ($files as $file) {
            if (is_array($file)) {
                return $file;
            }
        }

        return [];
    }

    private function isPublicVersion(array $version): bool
    {
        $status = $version['status'] ?? null;

        if (!is_string($status) || $status === '') {
            return true;
        }

        return !in_array(
            strtolower($status),
            ['draft', 'scheduled', 'unlisted', 'withheld'],
            true,
        );
    }

    // @return array<string>
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $entry): string => is_string($entry)
                    ? $entry
                    : '',
                $value,
            ),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    // @return array<int, array<string, mixed>>
    private function fetch(string $url, array $query = []): array
    {
        try {
            $response = $this->http->get($url, query: $query);
        } catch (ProviderHttpException $exception) {
            throw new CatalogProviderException(
                'Unable to load the versions from Modrinth.',
                0,
                $exception,
            );
        }

        if (!is_array($response->body)) {
            throw new CatalogProviderException(
                'The catalog provider returned an invalid response.',
            );
        }

        return $response->body;
    }
}
