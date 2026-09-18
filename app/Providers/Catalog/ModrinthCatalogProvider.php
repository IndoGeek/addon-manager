<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\Catalog;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogItem;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogPagination;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogDescription;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogProjectQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\DescriptionSanitizer;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogResult;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSearchQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogUnavailableException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogVersionQuery;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

// Real Modrinth catalog search.
final class ModrinthCatalogProvider implements CatalogProvider
{
    private const API_BASE = 'https://api.modrinth.com/v2';

    private const SEARCH_PATH = '/search';

    private const MAX_UPSTREAM_LIMIT = 100;

    private const URL_SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    // Curated common game versions offered as facet options.
    private const COMMON_GAME_VERSIONS = [
        '1.21.9',
        '1.21.8',
        '1.21.7',
        '1.21.6',
        '1.21.5',
        '1.21.4',
        '1.21.3',
        '1.21.2',
        '1.21.1',
        '1.21',
        '1.20.6',
        '1.20.4',
        '1.20.2',
        '1.20.1',
        '1.20',
        '1.19.4',
        '1.19.2',
        '1.18.2',
        '1.17.1',
        '1.16.5',
    ];

    // Modrinth categories that apply to modpacks.
    private const MODPACK_CATEGORIES = [
        'adventure',
        'combat',
        'creation',
        'decoration',
        'food',
        'magic',
        'optimization',
        'storage',
        'technology',
        'transportation',
        'utility',
        'misc',
    ];

    // Modrinth categories for mods — the exact slugs Modrinth's API uses for
    // project_type "mod" (GET /v2/tag/category). Plugins and datapacks reuse
    // this set upstream. Do not hand-edit without re-checking the live list.
    private const MOD_CATEGORIES = [
        'adventure',
        'cursed',
        'decoration',
        'economy',
        'equipment',
        'food',
        'game-mechanics',
        'library',
        'magic',
        'management',
        'minigame',
        'mobs',
        'optimization',
        'social',
        'storage',
        'technology',
        'transportation',
        'utility',
        'worldgen',
    ];

    // Resource pack categories (GET /v2/tag/category, project_type
    // "resourcepack").
    private const RESOURCEPACK_CATEGORIES = [
        '8x-',
        '16x',
        '32x',
        '48x',
        '64x',
        '128x',
        '256x',
        '512x+',
        'audio',
        'blocks',
        'combat',
        'core-shaders',
        'cursed',
        'decoration',
        'entities',
        'environment',
        'equipment',
        'fonts',
        'gui',
        'items',
        'locale',
        'modded',
        'models',
        'realistic',
        'simplistic',
        'themed',
        'tweaks',
        'utility',
        'vanilla-like',
    ];

    // Shader categories (GET /v2/tag/category, project_type "shader").
    private const SHADER_CATEGORIES = [
        'atmosphere',
        'bloom',
        'cartoon',
        'colored-lighting',
        'cursed',
        'fantasy',
        'foliage',
        'high',
        'low',
        'medium',
        'path-tracing',
        'pbr',
        'potato',
        'realistic',
        'reflections',
        'screenshot',
        'semi-realistic',
        'shadows',
        'vanilla-like',
    ];

    // The upstream project_type value each catalog content type maps to.
    private const CONTENT_TYPE_FACETS = [
        'modpack' => 'modpack',
        'mod' => 'mod',
        'plugin' => 'plugin',
        'datapack' => 'datapack',
        'resourcepack' => 'resourcepack',
        'shader' => 'shader',
    ];

    // Category slugs offered in the filter panel, per content type.
    private const CATEGORIES_BY_CONTENT_TYPE = [
        'modpack' => self::MODPACK_CATEGORIES,
        'mod' => self::MOD_CATEGORIES,
        'plugin' => self::MOD_CATEGORIES,
        'datapack' => self::MOD_CATEGORIES,
        'resourcepack' => self::RESOURCEPACK_CATEGORIES,
        'shader' => self::SHADER_CATEGORIES,
    ];

    // Loader slugs offered per content type. Modrinth does not return a
    // `loaders` array for plugin/datapack/resourcepack/shader search hits —
    // the loader is a category there — so this list and KNOWN_LOADERS below
    // must stay in sync.
    private const LOADERS_BY_CONTENT_TYPE = [
        'modpack' => ['fabric', 'forge', 'quilt', 'neoforge', 'liteloader', 'rift'],
        'mod' => ['fabric', 'forge', 'quilt', 'neoforge', 'liteloader', 'rift'],
        'plugin' => [
            'paper',
            'spigot',
            'bukkit',
            'purpur',
            'folia',
            'sponge',
            'bungeecord',
            'velocity',
            'waterfall',
            'geyser',
            'fabric',
            'forge',
            'quilt',
            'neoforge',
        ],
        'datapack' => ['datapack', 'fabric', 'forge', 'quilt', 'neoforge'],
        'resourcepack' => ['minecraft'],
        'shader' => ['iris', 'optifine'],
    ];

    // The Modrinth URL path segment used to link a project.
    private const PROJECT_URL_SEGMENTS = [
        'modpack' => 'modpack',
        'mod' => 'mod',
        'plugin' => 'plugin',
        'datapack' => 'datapack',
        'resourcepack' => 'resourcepack',
        'shader' => 'shader',
    ];

    // Content types that only exist on the client. Their search must not
    // restrict to server-side projects, and client-only hits must not be
    // dropped, or the catalog would come back nearly empty.
    private const CLIENT_ONLY_CONTENT_TYPES = [
        'resourcepack' => true,
        'shader' => true,
    ];

    // Every loader slug this provider understands, used to split loader tags
    // out of a project's category list.
    // @var array<string, true>
    private const KNOWN_LOADERS = [
        'fabric' => true,
        'forge' => true,
        'quilt' => true,
        'neoforge' => true,
        'liteloader' => true,
        'rift' => true,
        'datapack' => true,
        'paper' => true,
        'spigot' => true,
        'bukkit' => true,
        'purpur' => true,
        'folia' => true,
        'sponge' => true,
        'bungeecord' => true,
        'velocity' => true,
        'waterfall' => true,
        'geyser' => true,
        'minecraft' => true,
        'iris' => true,
        'optifine' => true,
    ];

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {
    }

    public function name(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    public function available(): bool
    {
        return true;
    }

    public function state(): string
    {
        return 'available';
    }

    public function developmentOnly(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    // @return array{query: bool, game_versions: bool, loaders: bool, categories: bool, environment: bool, sort: bool}
    public function capabilities(): array
    {
        return [
            'query' => true,
            'game_versions' => true,
            'loaders' => true,
            'categories' => true,
            'environment' => true,
            'sort' => true,
            'content_types' => array_keys(self::CONTENT_TYPE_FACETS),
        ];
    }

    // @return array{game_versions: array<int, string>, loaders: array<int, string>, categories: array<int, string>...
    public function facets(string $contentType = 'modpack'): array
    {
        return [
            'game_versions' => self::COMMON_GAME_VERSIONS,
            'loaders' => self::LOADERS_BY_CONTENT_TYPE[$contentType]
                ?? array_keys(self::KNOWN_LOADERS),
            'categories' => self::CATEGORIES_BY_CONTENT_TYPE[$contentType]
                ?? self::MODPACK_CATEGORIES,
            'environments' => CatalogSearchQuery::ENVIRONMENT_VALUES,
        ];
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $payload = $this->fetch($query);

        return $this->mapPayload($query, $payload);
    }

    // @return array<int, CatalogVersion>
    public function versions(CatalogVersionQuery $query): array
    {
        $payload = $this->fetchVersions($query);

        $versions = [];

        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if (!$this->isPublicVersion($entry)) {
                continue;
            }

            if ($this->versionIsClientOnly($entry)) {
                continue;
            }

            $versions[] = $this->mapVersion($query, $entry);
        }

        return $versions;
    }

    public function project(CatalogProjectQuery $query): CatalogItem
    {
        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/project/'
                    . rawurlencode($query->project),
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $this->mapProject($response->body, $query);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    public function description(CatalogProjectQuery $query): CatalogDescription
    {
        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/project/'
                    . rawurlencode($query->project),
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            // Modrinth serves the body as Markdown; the rendered HTML endpoint
            // (/project/:slug/body) would be a second request, so convert the
            // common Markdown constructs here after escaping, then sanitize.
            $body = is_string($response->body['body'] ?? null)
                ? $response->body['body']
                : '';

            $html = (new DescriptionSanitizer())->sanitize(
                $this->markdownToHtml($body),
            );

            return new CatalogDescription(
                provider: $this->name(),
                project: $query->project,
                html: $html,
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // Minimal, escape-first Markdown-to-HTML conversion covering the constructs Modrinth project bodies actually use:...
    private function markdownToHtml(string $markdown): string
    {
        $escape = static fn (string $text): string =>
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $html = [];
        $inCode = false;
        $listOpen = false;

        $inline = static function (string $text) use ($escape): string {
            // Inline HTML (e.g. <summary>…</summary> or <b>…</b> between
            // words) must survive as real tags, exactly like on Modrinth.
            // Split the line into tag / non-tag segments and escape only the
            // non-tag parts, so injected markup never slips through either.
            $segments = preg_split(
                '/(<[^<>]+>)/',
                $text,
                -1,
                PREG_SPLIT_DELIM_CAPTURE,
            ) ?: [$text];

            $text = '';

            foreach ($segments as $index => $segment) {
                if ($index % 2 === 1) {
                    $text .= $segment;

                    continue;
                }

                $text .= $escape($segment);
            }

            // Images then links; URL already escaped by $escape, so quotes
            // inside cannot break out of the attribute.
            $text = preg_replace(
                '/!\[([^\]]*)\]\(([^)\s]+)\)/',
                '<img src="$2" alt="$1">',
                $text,
            ) ?? $text;

            $text = preg_replace(
                '/\[([^\]]+)\]\(([^)\s]+)\)/',
                '<a href="$2">$1</a>',
                $text,
            ) ?? $text;

            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text)
                ?? $text;
            $text = preg_replace('/(^|\W)\*([^*]+)\*/', '$1<em>$2</em>', $text)
                ?? $text;
            $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text)
                ?? $text;

            return $text;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                if ($inCode) {
                    $html[] = '</code></pre>';
                    $inCode = false;
                } else {
                    $html[] = '<pre><code>';
                    $inCode = true;
                }

                continue;
            }

            if ($inCode) {
                $html[] = $escape($line);

                continue;
            }

            // Modrinth bodies legitimately embed raw HTML blocks (details/
            // summary disclosures, images, divs, multi-tag lines like
            // '<h3 align="center">title</h3>'). Any line that is delimited
            // as one or more tags passes through untouched; the sanitizer
            // downstream enforces the safety rules.
            if ($trimmed !== ''
                && str_starts_with($trimmed, '<')
                && str_ends_with($trimmed, '>')
            ) {
                if ($listOpen) {
                    $html[] = '</ul>';
                    $listOpen = false;
                }

                $html[] = $trimmed;

                continue;
            }

            if ($trimmed === '') {
                if ($listOpen) {
                    $html[] = '</ul>';
                    $listOpen = false;
                }

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                if ($listOpen) {
                    $html[] = '</ul>';
                    $listOpen = false;
                }

                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>'
                    . $inline($m[2])
                    . '</h' . $level . '>';

                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1) {
                if (!$listOpen) {
                    $html[] = '<ul>';
                    $listOpen = true;
                }

                $html[] = '<li>' . $inline($m[1]) . '</li>';

                continue;
            }

            if (str_starts_with($trimmed, '&gt;')
                || str_starts_with($trimmed, '>')
            ) {
                if ($listOpen) {
                    $html[] = '</ul>';
                    $listOpen = false;
                }

                $html[] = '<blockquote>' . $inline(ltrim($trimmed, '> '))
                    . '</blockquote>';

                continue;
            }

            if ($listOpen) {
                $html[] = '</ul>';
                $listOpen = false;
            }

            $html[] = '<p>' . $inline($trimmed) . '</p>';
        }

        if ($inCode) {
            $html[] = '</code></pre>';
        }

        if ($listOpen) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    // @return array<int, array<string, mixed>>
    private function fetchVersions(CatalogVersionQuery $query): array
    {
        $parameters = [];

        if ($query->gameVersions !== []) {
            $parameters['game_versions'] = json_encode($query->gameVersions);
        }

        if ($query->loaders !== []) {
            $parameters['loaders'] = json_encode($query->loaders);
        }

        try {
            $response = $this->http->get(
                self::API_BASE
                    . '/project/'
                    . rawurlencode($query->project)
                    . '/version',
                query: $parameters,
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $response->body;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // A version is public unless the upstream explicitly marks it as a non-public status (drafts, scheduled, or unlisted...
    private function isPublicVersion(array $version): bool
    {
        $status = $this->stringOrNull($version['status'] ?? null);

        if ($status === null) {
            return true;
        }

        return !in_array(
            strtolower($status),
            ['draft', 'scheduled', 'unlisted', 'withheld'],
            true,
        );
    }

    // Whether a search hit can only run on the client.
    private function hitIsClientOnly(array $hit): bool
    {
        return $this->stringOrNull($hit['server_side'] ?? null)
            === 'unsupported';
    }

    // Whether a version only runs on the client.
    private function versionIsClientOnly(array $version): bool
    {
        return $this->stringOrNull($version['environment'] ?? null)
            === 'client_only';
    }

    // @param array<string, mixed> $version
    private function mapVersion(
        CatalogVersionQuery $query,
        array $version,
    ): CatalogVersion {
        $versionId = $this->stringOrNull($version['id'] ?? null) ?? '';

        return new CatalogVersion(
            provider: $this->name(),
            projectId: $query->project,
            projectSlug: null,
            projectName: null,
            versionId: $versionId,
            versionNumber: $this->stringOrNull($version['version_number'] ?? null)
                ?? '',
            versionName: $this->stringOrNull($version['name'] ?? null),
            gameVersions: $this->stringList($version['game_versions'] ?? []),
            loaders: $this->stringList($version['loaders'] ?? []),
            datePublished: $this->stringOrNull($version['date_published'] ?? null),
            dateModified: $this->stringOrNull($version['date_modified'] ?? null),
            downloads: $this->intOrNull($version['downloads'] ?? null),
            source: $versionId === ''
                ? ''
                : 'modrinth://' . $query->project . '@' . $versionId,
            fileSize: $this->primaryFileSize($version),
        );
    }

    // Modrinth may advertise several files per version; the primary one is what the installer downloads.
    private function primaryFileSize(array $version): ?int
    {
        $files = $version['files'] ?? null;

        if (!is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (($file['primary'] ?? false) === true) {
                return $this->intOrNull($file['size'] ?? null);
            }
        }

        foreach ($files as $file) {
            if (is_array($file) && isset($file['size'])) {
                return $this->intOrNull($file['size']);
            }
        }

        return null;
    }

    // @return array<string, mixed>
    private function fetch(CatalogSearchQuery $query): array
    {
        $limit = min(
            $query->limit,
            self::MAX_UPSTREAM_LIMIT,
        );

        $parameters = [
            'facets' => $this->buildFacets($query),
            'index' => $query->sort->value,
            'limit' => $limit,
            'offset' => $query->offset(),
        ];

        if ($query->query !== null) {
            $parameters['query'] = $query->query;
        }

        try {
            $response = $this->http->get(
                self::API_BASE . self::SEARCH_PATH,
                query: $parameters,
            );

            if (!is_array($response->body)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            return $response->body;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (CatalogProviderException $exception) {
            throw $exception;
        } catch (ProviderHttpException $exception) {
            throw $this->requestFailure($exception);
        }
    }

    // Serializes the validated filters into Modrinth facet groups.
    private function buildFacets(CatalogSearchQuery $query): string
    {
        $clientOnly = isset(
            self::CLIENT_ONLY_CONTENT_TYPES[$query->contentType],
        );

        $facets = [
            [
                'project_type:'
                    . (self::CONTENT_TYPE_FACETS[$query->contentType]
                        ?? 'modpack'),
            ],
        ];

        // Server-installable content defaults to server-side projects.
        // Client-only content types have no server-side representation, so
        // the group is skipped there (and replaced by the user's own
        // environment selection below).
        if (!$clientOnly && $query->environments === []) {
            $facets[] = [
                'server_side:required',
                'server_side:optional',
            ];
        }

        // "Client"/"Server" are Modrinth side facets, not categories; each
        // selection expands to the side groups it means.
        foreach ($query->environments as $environment) {
            foreach (self::environmentFacets($environment) as $group) {
                $facets[] = $group;
            }
        }

        if ($query->gameVersions !== []) {
            $facets[] = array_map(
                static fn (string $version): string =>
                    'versions:' . $version,
                $query->gameVersions,
            );
        }

        if ($query->loaders !== []) {
            $facets[] = array_map(
                static fn (string $loader): string =>
                    'categories:' . $loader,
                $query->loaders,
            );
        }

        if ($query->categories !== []) {
            $facets[] = array_map(
                static fn (string $category): string =>
                    'categories:' . $category,
                $query->categories,
            );
        }

        return json_encode($facets);
    }

    // Modrinth facet groups for one environment selection.
    // @return array<int, array<int, string>>
    private static function environmentFacets(string $environment): array
    {
        $client = ['client_side:required', 'client_side:optional'];
        $server = ['server_side:required', 'server_side:optional'];

        return match ($environment) {
            'client' => [$client],
            'server' => [$server],
            'client-and-server' => [$client, $server],
            default => [],
        };
    }

    // @param array<string, mixed> $payload
    private function mapPayload(
        CatalogSearchQuery $query,
        array $payload,
    ): CatalogResult {
        $hits = $payload['hits'] ?? null;

        if (!is_array($hits)) {
            throw new CatalogProviderException(
                'The modpack catalog provider returned an invalid response.',
            );
        }

        $total = $this->totalHits($payload['total_hits'] ?? null);

        // Client-only content types legitimately return client-only hits;
        // for everything else they are filtered out (and the upstream total
        // stays authoritative, so pagination is unaffected).
        $skipClientOnly = !isset(
            self::CLIENT_ONLY_CONTENT_TYPES[$query->contentType],
        );

        $items = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                throw new CatalogProviderException(
                    'The modpack catalog provider returned an invalid response.',
                );
            }

            if ($skipClientOnly && $this->hitIsClientOnly($hit)) {
                continue;
            }

            $items[] = $this->mapHit($hit, $query->contentType);
        }

        // The upstream total_hits stays authoritative even when this page
        // contains fewer mapped items than raw hits (client-only entries are
        // filtered above). Collapsing the total to offset+count here used to
        // shrink the whole catalog to a single page whenever one hit was
        // filtered out.

        return new CatalogResult(
            items: $items,
            pagination: new CatalogPagination(
                $query->page,
                $query->limit,
                $total,
            ),
            provider: $this->name(),
            sort: $query->sort->value,
            appliedQuery: $query->query,
            appliedGameVersions: $query->gameVersions,
            appliedLoaders: $query->loaders,
            appliedCategories: $query->categories,
            appliedEnvironments: $query->environments,
        );
    }

    // @param array<string, mixed> $hit
    private function mapHit(array $hit, string $contentType): CatalogItem
    {
        $projectId = $this->stringOrNull($hit['project_id'] ?? null)
            ?? $this->stringOrNull($hit['id'] ?? null)
            ?? '';

        $slug = $this->validSlug($hit['slug'] ?? null);

        $categories = $this->stringList($hit['categories'] ?? []);

        // Search hits carry no `loaders` array for any project type:
        // Modrinth mixes the loader slugs into `categories`, and
        // `display_categories` is a curated subset of those (it holds plain
        // tags too, e.g. "library", which must not become a loader). Split
        // the real loader slugs out of the category list instead.
        $loaders = array_values(array_filter(
            $categories,
            static fn (string $category): bool =>
                isset(self::KNOWN_LOADERS[$category]),
        ));

        $tags = array_values(array_diff($categories, $loaders));

        $sourceId = $slug ?? ($this->validSlug($projectId) ?? '');

        return new CatalogItem(
            provider: $this->name(),
            providerProjectId: $projectId,
            slug: $slug,
            name: $this->stringOrNull($hit['title'] ?? null) ?? '',
            summary: $this->stringOrNull($hit['description'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->stringOrNull($hit['icon_url'] ?? null),
            ),
            projectUrl: $sourceId === ''
                ? null
                : 'https://modrinth.com/'
                    . (self::PROJECT_URL_SEGMENTS[$contentType]
                        ?? 'modpack')
                    . '/'
                    . $sourceId,
            downloads: $this->intOrNull($hit['downloads'] ?? null),
            follows: $this->intOrNull($hit['follows'] ?? null),
            categories: $tags,
            gameVersions: $this->stringList($hit['versions'] ?? []),
            loaders: $loaders,
            latestVersion: $this->stringOrNull($hit['latest_version'] ?? null),
            source: 'modrinth://' . $sourceId,
            author: $this->stringOrNull($hit['author'] ?? null),
            updatedAt: $this->stringOrNull($hit['date_modified'] ?? null),
            bannerUrl: $this->galleryImage($hit),
            environment: $this->environmentFlags(
                $this->stringOrNull($hit['client_side'] ?? null),
                $this->stringOrNull($hit['server_side'] ?? null),
            ),
        );
    }

    // @param array<string, mixed> $project
    private function mapProject(
        array $project,
        CatalogProjectQuery $query,
    ): CatalogItem
    {
        $projectId = $this->stringOrNull($project['id'] ?? null) ?? '';

        $slug = $this->validSlug($project['slug'] ?? null);

        $categories = $this->stringList($project['categories'] ?? []);

        $loaders = $this->stringList($project['loaders'] ?? []);

        if ($loaders === []) {
            $loaders = array_values(array_filter(
                $categories,
                static fn (string $category): bool =>
                    isset(self::KNOWN_LOADERS[$category]),
            ));
        }

        $tags = array_values(array_diff($categories, $loaders));

        $sourceId = $slug ?? ($this->validSlug($projectId) ?? '');

        // The project endpoint reports its canonical type; use it for the
        // public link so a mod no longer points at a /modpack/ URL.
        $projectType = $this->stringOrNull($project['project_type'] ?? null);

        return new CatalogItem(
            provider: $this->name(),
            providerProjectId: $projectId,
            slug: $slug,
            name: $this->stringOrNull($project['title'] ?? null) ?? '',
            summary: $this->stringOrNull($project['description'] ?? null),
            iconUrl: $this->validImageUrl(
                $this->stringOrNull($project['icon_url'] ?? null),
            ),
            projectUrl: $sourceId === ''
                ? null
                : 'https://modrinth.com/'
                    . ($projectType !== null
                        ? (self::PROJECT_URL_SEGMENTS[$projectType]
                            ?? 'modpack')
                        : 'modpack')
                    . '/'
                    . $sourceId,
            downloads: $this->intOrNull($project['downloads'] ?? null),
            follows: $this->intOrNull($project['followers'] ?? null),
            categories: $tags,
            gameVersions: $this->stringList($project['game_versions'] ?? []),
            loaders: $loaders,
            latestVersion: null,
            source: 'modrinth://' . $sourceId,
            author: $this->stringOrNull($project['author'] ?? null),
            updatedAt: $this->stringOrNull($project['updated'] ?? null),
            bannerUrl: $this->galleryImage($project),
            environment: $this->environmentFlags(
                $this->stringOrNull($project['client_side'] ?? null),
                $this->stringOrNull($project['server_side'] ?? null),
            ),
        );
    }

    // Best "banner" image available for a project: the featured gallery item when present, otherwise the first gallery entry...
    private function galleryImage(array $data): ?string
    {
        foreach (['featured_gallery', 'gallery'] as $key) {
            $entries = $data[$key] ?? null;

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $url = $this->validImageUrl(
                    $this->stringOrNull($entry['url'] ?? null)
                        ?? $this->stringOrNull($entry['raw_url'] ?? null),
                );

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    // Derive a client/server environment label from the Modrinth side flags, falling back to "client-and-server" when the...
    private function environmentFlags(?string $clientSide, ?string $serverSide): ?string
    {
        if ($clientSide === 'unsupported' && $serverSide === 'unsupported') {
            return null;
        }

        if ($serverSide === 'unsupported') {
            return 'client';
        }

        if ($clientSide === 'unsupported') {
            return 'server';
        }

        return 'client-and-server';
    }

    private function totalHits(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private function requestFailure(
        ProviderHttpException $exception,
    ): CatalogUnavailableException|CatalogProviderException {
        $status = $exception->status();

        if ($status === 429) {
            return new CatalogUnavailableException(
                'The modpack catalog provider is rate limited. Please try again later.',
            );
        }

        if ($status !== null && $status >= 500) {
            return new CatalogUnavailableException(
                'The modpack catalog provider is temporarily unavailable. Please try again later.',
            );
        }

        if ($status !== null && $status >= 400) {
            return new CatalogProviderException(
                'The modpack catalog provider rejected the request.',
            );
        }

        return new CatalogUnavailableException(
            'Unable to reach the modpack catalog provider. Please try again later.',
        );
    }

    private function validSlug(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        if (preg_match(self::URL_SLUG_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function validImageUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        return $url;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    // @return array<string>
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    fn (mixed $entry): string => $this->stringOrNull($entry) ?? '',
                    $value,
                ),
                static fn (string $entry): bool => $entry !== '',
            ),
        );
    }
}
