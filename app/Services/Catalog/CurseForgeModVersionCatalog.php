<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

// Version catalog for CurseForge single-file content (mods).
//
// CurseForge has no Modrinth-style version objects: a file IS the version. It
// also publishes loader and Minecraft-version facts in one mixed gameVersions
// array, and its dependencies as numeric mod ids, so this normalizes all of it
// into the same shape ModVersionCatalog returns — the version window, the
// install path and the uninstall path are shared between both providers.
final class CurseForgeModVersionCatalog
{
    private const API_BASE = 'https://api.curseforge.com/v1';

    private const API_KEY_HEADER = 'X-Api-Key';

    private const PROJECT_PATTERN = '/^\d{1,12}$/';

    private const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)*$/';

    // CurseForge serves files in pages; three pages is plenty for the two
    // dropdowns and keeps one open of the version window cheap.
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 3;

    // File statuses visible to the public (Approved, Released).
    private const PUBLIC_FILE_STATUSES = [4, 10];

    // Loader display names that appear inside a file's gameVersions array.
    private const LOADER_DISPLAY_NAMES = [
        'Forge' => 'forge',
        'Fabric' => 'fabric',
        'Quilt' => 'quilt',
        'NeoForge' => 'neoforge',
        'LiteLoader' => 'liteloader',
        'Cauldron' => 'cauldron',
    ];

    // Dependency relation types that are worth recommending; everything else
    // (embedded libraries, tools, incompatibilities) is not a user choice.
    private const RELATION_REQUIRED = 3;

    private const RELATION_OPTIONAL = 2;

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly ?string $apiKey,
    ) {}

    /**
     * Every installable file of a CurseForge mod, enriched with the loader and
     * game-version options plus resolvable dependency titles.
     *
     * @return array{provider: string, project: string, versions: array<int, array<string, mixed>>}
     */
    public function versions(string $project): array
    {
        $this->assertConfigured();

        if (preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            throw new CatalogProviderException(
                'Invalid project parameter.',
            );
        }

        $distributionBlocked = false;

        $files = $this->files($project, $distributionBlocked);

        $dependencyIds = [];

        foreach ($files as $file) {
            foreach ($this->recommendDependencies($file) as $dependency) {
                $dependencyIds[$dependency['project_id']] = true;
            }
        }

        $projects = $this->resolveProjects(array_keys($dependencyIds));

        $versions = [];

        foreach ($files as $file) {
            $versions[] = $this->mapVersion($project, $file, $projects);
        }

        return [
            'provider' => 'curseforge',
            'project' => $project,
            'versions' => $versions,
            // CurseForge nulls every downloadUrl when an author turns off
            // third-party downloads (allowModDistribution=false), which is
            // common for plugins. The list is then legitimately empty, but
            // "no versions available" would be misleading: the files exist,
            // they just cannot be fetched automatically.
            'unavailable_reason' => $versions === [] && $distributionBlocked
                ? 'distribution_disabled'
                : null,
        ];
    }

    // Every publicly downloadable file of the project, newest first. Files
    // CurseForge refuses to serve (distribution disabled, withheld, removed)
    // are dropped rather than offered as a version that can never install.
    // @return array<int, array<string, mixed>>
    private function files(string $project, bool &$distributionBlocked): array
    {
        $files = [];
        $index = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $payload = $this->fetch(
                self::API_BASE . '/mods/' . $project . '/files',
                [
                    'index' => $index,
                    'pageSize' => self::PAGE_SIZE,
                ],
            );

            $entries = $payload['data'] ?? null;

            if (!is_array($entries)) {
                throw new CatalogProviderException(
                    'The catalog provider returned an invalid response.',
                );
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ($this->isInstallable($entry)) {
                    $files[] = $entry;

                    continue;
                }

                // Public file with no download URL: the author disabled
                // automated distribution for this project.
                if (
                    $this->isPubliclyListed($entry)
                    && $this->downloadUrl($entry) === null
                ) {
                    $distributionBlocked = true;
                }
            }

            $count = count($entries);

            if ($count < self::PAGE_SIZE) {
                break;
            }

            $index += self::PAGE_SIZE;
        }

        usort(
            $files,
            static fn (array $left, array $right): int =>
                strcmp(
                    (string) ($right['fileDate'] ?? ''),
                    (string) ($left['fileDate'] ?? ''),
                ),
        );

        return $files;
    }

    private function isInstallable(array $file): bool
    {
        return $this->isPubliclyListed($file) && $this->downloadUrl($file) !== null;
    }

    // Whether the file is visible to the public, regardless of whether
    // CurseForge is willing to hand out a download URL for it.
    private function isPubliclyListed(array $file): bool
    {
        if (($file['isAvailable'] ?? true) === false) {
            return false;
        }

        $status = $file['fileStatus'] ?? null;

        if (
            is_int($status)
            && !in_array($status, self::PUBLIC_FILE_STATUSES, true)
        ) {
            return false;
        }

        return true;
    }

    private function downloadUrl(array $file): ?string
    {
        $url = $file['downloadUrl'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    // @return array<int, array{project_id: string, type: string}>
    private function recommendDependencies(array $file): array
    {
        $dependencies = $file['dependencies'] ?? null;

        if (!is_array($dependencies)) {
            return [];
        }

        $recommended = [];

        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            $relation = $this->intOrNull(
                $dependency['relationType'] ?? null,
            );

            $type = match ($relation) {
                self::RELATION_REQUIRED => 'required',
                self::RELATION_OPTIONAL => 'optional',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $modId = $this->intOrNull($dependency['modId'] ?? null);

            if ($modId === null || $modId <= 0) {
                continue;
            }

            $recommended[] = [
                'project_id' => (string) $modId,
                'type' => $type,
            ];
        }

        return $recommended;
    }

    // Dependency entries only carry numeric mod ids; batch-resolve the human
    // name, slug and icon so the UI can render them as proper cards and build
    // install sources. A failed lookup is cosmetic only.
    // @param array<int, string> $ids
    // @return array<string, array{title: string, slug: ?string, icon_url: ?string}>
    private function resolveProjects(array $ids): array
    {
        $numeric = [];

        foreach ($ids as $id) {
            if (preg_match(self::PROJECT_PATTERN, $id) === 1) {
                $numeric[] = (int) $id;
            }
        }

        if ($numeric === []) {
            return [];
        }

        try {
            $response = $this->http->post(
                self::API_BASE . '/mods',
                ['modIds' => array_values(array_unique($numeric))],
                $this->headers(),
            );
        } catch (ProviderHttpException | CatalogProviderException) {
            return [];
        }

        $entries = is_array($response->body)
            ? ($response->body['data'] ?? null)
            : null;

        if (!is_array($entries)) {
            return [];
        }

        $projects = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = $this->intOrNull($entry['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $logo = is_array($entry['logo'] ?? null) ? $entry['logo'] : [];

            $projects[(string) $id] = [
                'title' => $this->stringOrNull($entry['name'] ?? null)
                    ?? (string) $id,
                'slug' => $this->stringOrNull($entry['slug'] ?? null),
                'icon_url' => $this->stringOrNull(
                    $logo['thumbnailUrl'] ?? null,
                ) ?? $this->stringOrNull($logo['url'] ?? null),
            ];
        }

        return $projects;
    }

    // @param array<string, array{title: string, slug: ?string, icon_url: ?string}> $projects
    // @return array<string, mixed>
    private function mapVersion(
        string $project,
        array $file,
        array $projects,
    ): array {
        $fileId = $this->intOrNull($file['id'] ?? null);

        [$gameVersions, $loaders] = $this->parseGameVersions(
            $file['gameVersions'] ?? [],
        );

        $dependencies = [];

        foreach ($this->recommendDependencies($file) as $dependency) {
            $resolved = $projects[$dependency['project_id']] ?? null;

            $dependencies[] = [
                'project_id' => $dependency['project_id'],
                'title' => $resolved['title'] ?? $dependency['project_id'],
                'slug' => $resolved['slug'] ?? null,
                'icon_url' => $resolved['icon_url'] ?? null,
                'type' => $dependency['type'],
            ];
        }

        $displayName = $this->stringOrNull($file['displayName'] ?? null)
            ?? (string) ($file['fileName'] ?? '');

        $fileName = (string) ($file['fileName'] ?? '');

        return [
            'version_id' => $fileId === null ? '' : (string) $fileId,
            'version_number' => $displayName !== ''
                ? $displayName
                : $fileName,
            'loaders' => $loaders,
            'game_versions' => $gameVersions,
            'date_published' => $this->stringOrNull($file['fileDate'] ?? null),
            'downloads' => $this->intOrNull($file['downloadCount'] ?? null),
            'file_size' => $this->intOrNull($file['fileLength'] ?? null),
            'source' => $fileId === null
                ? 'curseforge://' . $project
                : 'curseforge://' . $project . '@' . $fileId,
            'dependencies' => $dependencies,
        ];
    }

    // A file's gameVersions mixes Minecraft versions with loader names, so the
    // two are split apart: the version window needs them as separate options.
    // @return array{0: array<int, string>, 1: array<int, string>}
    private function parseGameVersions(mixed $values): array
    {
        $gameVersions = [];
        $loaders = [];

        if (!is_array($values)) {
            return [$gameVersions, $loaders];
        }

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            if (preg_match(self::VERSION_PATTERN, $value) === 1) {
                $gameVersions[] = $value;

                continue;
            }

            $loader = self::LOADER_DISPLAY_NAMES[$value] ?? null;

            if ($loader !== null) {
                $loaders[] = $loader;
            }
        }

        return [
            array_values(array_unique($gameVersions)),
            array_values(array_unique($loaders)),
        ];
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new CatalogProviderException(
                'CurseForge is not available. Please add your API key to the extension settings.',
            );
        }
    }

    // Header LINES, not a name => value map: the HTTP client hands this
    // straight to CURLOPT_HTTPHEADER, which only understands "Name: value"
    // strings. A map would be sent as a bare value and the API key would
    // silently never leave the panel (CurseForge answers 403).
    // @return array<int, string>
    private function headers(): array
    {
        return [self::API_KEY_HEADER . ': ' . (string) $this->apiKey];
    }

    // @return array<string, mixed>
    private function fetch(string $url, array $query = []): array
    {
        try {
            $response = $this->http->get(
                $url,
                query: $query,
                headers: $this->headers(),
            );
        } catch (ProviderHttpException $exception) {
            throw new CatalogProviderException(
                'Unable to load the versions from CurseForge.',
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

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
