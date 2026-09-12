<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

/**
 * A validated, provider-agnostic catalog search request. Every field is
 * normalized and bounded here so providers never receive hostile input and
 * the API layer can build it from scalar request parameters safely.
 */
final readonly class CatalogSearchQuery
{
    public const DEFAULT_PROVIDER = 'modrinth';

    public const DEFAULT_PAGE = 1;

    public const DEFAULT_LIMIT = 20;

    public const MIN_LIMIT = 1;

    public const MAX_LIMIT = 50;

    public const MAX_PAGE = 10_000;

    public const MAX_QUERY_LENGTH = 128;

    public const SLUG_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const VERSION_PATTERN = '/^[0-9A-Za-z._-]{1,32}$/';

    public string $provider;

    public ?string $query;

    public ?string $gameVersion;

    public ?string $loader;

    public ?string $category;

    public CatalogSort $sort;

    public int $page;

    public int $limit;

    public function __construct(
        string $provider = self::DEFAULT_PROVIDER,
        ?string $query = null,
        ?string $gameVersion = null,
        ?string $loader = null,
        ?string $category = null,
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

        $this->gameVersion = $this->validateOptional(
            $gameVersion,
            self::VERSION_PATTERN,
            'Invalid catalog game version.',
            false,
        );

        $this->loader = $this->validateOptional(
            $loader,
            self::SLUG_PATTERN,
            'Invalid catalog loader.',
            true,
        );

        $this->category = $this->validateOptional(
            $category,
            self::SLUG_PATTERN,
            'Invalid catalog category.',
            true,
        );

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

    private function validateOptional(
        ?string $value,
        string $pattern,
        string $message,
        bool $toLower,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($toLower) {
            $value = strtolower($value);
        }

        if (strlen($value) > 32 || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}