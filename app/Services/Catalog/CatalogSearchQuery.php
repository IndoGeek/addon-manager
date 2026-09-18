<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

// A validated, provider-agnostic catalog search request.
final readonly class CatalogSearchQuery
{
    public const DEFAULT_PROVIDER = 'modrinth';

    public const DEFAULT_PAGE = 1;

    public const DEFAULT_LIMIT = 20;

    public const MIN_LIMIT = 1;

    public const MAX_LIMIT = 50;

    public const MAX_PAGE = 10_000;

    public const MAX_QUERY_LENGTH = 128;

    public const MAX_FILTER_VALUES = 32;

    public const SLUG_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const VERSION_PATTERN = '/^[0-9A-Za-z._-]{1,32}$/';

    // Supported environment values.
    public const ENVIRONMENT_VALUES = [
        'client',
        'server',
        'client-and-server',
    ];

    // Content types a catalog search can target. The provider maps each to
    // its upstream project-type facet ('modpack', 'mod', ...).
    public const CONTENT_TYPE_VALUES = [
        'modpack',
        'mod',
        'plugin',
        'datapack',
        'resourcepack',
        'shader',
    ];

    public const DEFAULT_CONTENT_TYPE = 'modpack';

    public string $provider;

    public ?string $query;

    /** @var array<string> */
    public array $gameVersions;

    /** @var array<string> */
    public array $loaders;

    /** @var array<string> */
    public array $categories;

    /** @var array<string> */
    public array $environments;

    public string $contentType;

    public CatalogSort $sort;

    public int $page;

    public int $limit;

    // @param array|string|null $gameVersion Compatible with scalar callers.
    public function __construct(
        string $provider = self::DEFAULT_PROVIDER,
        ?string $query = null,
        array|string|null $gameVersion = null,
        array|string|null $loader = null,
        array|string|null $category = null,
        array|string|null $environments = null,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
        CatalogSort $sort = CatalogSort::RELEVANCE,
        int $page = self::DEFAULT_PAGE,
        int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->provider = trim($provider);

        if (preg_match(self::SLUG_PATTERN, $this->provider) !== 1) {
            throw new InvalidArgumentException(
                'Invalid catalog provider.',
            );
        }

        $this->query = $this->normalizeOptionalText(
            $query,
            self::MAX_QUERY_LENGTH,
        );

        $this->gameVersions = $this->normalizeFilterValues(
            $gameVersion,
            self::VERSION_PATTERN,
            'Invalid catalog game version.',
            false,
        );

        $this->loaders = $this->normalizeFilterValues(
            $loader,
            self::SLUG_PATTERN,
            'Invalid catalog loader.',
            true,
        );

        $this->categories = $this->normalizeFilterValues(
            $category,
            self::SLUG_PATTERN,
            'Invalid catalog category.',
            true,
        );

        $this->environments = $this->normalizeFilterValues(
            $environments,
            self::SLUG_PATTERN,
            'Invalid catalog environment.',
            true,
        );

        foreach ($this->environments as $environment) {
            if (!in_array($environment, self::ENVIRONMENT_VALUES, true)) {
                throw new InvalidArgumentException(
                    'Invalid catalog environment.',
                );
            }
        }

        $this->contentType = trim($contentType);

        if (!in_array($this->contentType, self::CONTENT_TYPE_VALUES, true)) {
            throw new InvalidArgumentException(
                'Invalid catalog content type.',
            );
        }

        $this->sort = $sort;

        if ($page < self::DEFAULT_PAGE || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException(
                'The requested page is out of range.',
            );
        }

        $this->page = $page;

        if (
            $limit < self::MIN_LIMIT
            || $limit > self::MAX_LIMIT
        ) {
            throw new InvalidArgumentException(
                'Invalid catalog page size.',
            );
        }

        $this->limit = $limit;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    // Whether any non-default filter besides the query text is active.
    public function hasActiveFilters(): bool
    {
        return $this->gameVersions !== []
            || $this->loaders !== []
            || $this->categories !== []
            || $this->environments !== [];
    }

    // @return array<string, array<int, string>>
    public function appliedFilters(): array
    {
        return [
            'game_versions' => $this->gameVersions,
            'loaders' => $this->loaders,
            'categories' => $this->categories,
            'environments' => $this->environments,
            'content_type' => $this->contentType,
        ];
    }

    // @param array|string|null $value @return array<string>
    private function normalizeFilterValues(
        array|string|null $value,
        string $pattern,
        string $message,
        bool $toLower,
    ): array {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        $normalized = [];

        foreach ($values as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException($message);
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if ($toLower) {
                $entry = strtolower($entry);
            }

            if (
                strlen($entry) > 32
                || preg_match($pattern, $entry) !== 1
            ) {
                throw new InvalidArgumentException($message);
            }

            $normalized[$entry] = true;
        }

        $normalized = array_keys($normalized);

        if (count($normalized) > self::MAX_FILTER_VALUES) {
            throw new InvalidArgumentException(
                'Too many catalog filter values.',
            );
        }

        return $normalized;
    }

    private function normalizeOptionalText(
        ?string $value,
        int $maxLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                'The catalog query is too long.',
            );
        }

        return $value;
    }
}