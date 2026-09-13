<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

/**
 * A validated, provider-agnostic request for the exact versions/releases of a
 * single catalog project. Mirrors the catalog search validation rules so the
 * API layer can build it from strict scalar parameters.
 */
final readonly class CatalogVersionQuery
{
    public const DEFAULT_PROVIDER = 'modrinth';

    public const PROJECT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public const VERSION_PATTERN = '/^[0-9A-Za-z._-]{1,32}$/';

    public const SLUG_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const MAX_FILTER_VALUES = 16;

    public string $provider;

    public string $project;

    /** @var array<string> */
    public array $gameVersions;

    /** @var array<string> */
    public array $loaders;

    /**
     * @param array|string|null $gameVersion Compatible with scalar callers.
     * @param array|string|null $loader      Compatible with scalar callers.
     */
    public function __construct(
        string $provider = self::DEFAULT_PROVIDER,
        ?string $project = null,
        array|string|null $gameVersion = null,
        array|string|null $loader = null,
    ) {
        $this->provider = trim($provider);

        if (preg_match(self::SLUG_PATTERN, $this->provider) !== 1) {
            throw new InvalidArgumentException(
                'Invalid catalog provider.',
            );
        }

        if ($project === null || trim($project) === '') {
            throw new InvalidArgumentException(
                'The catalog version project is required.',
            );
        }

        $project = trim($project);

        if (preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            throw new InvalidArgumentException(
                'Invalid catalog version project.',
            );
        }

        $this->project = $project;

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
    }

    /**
     * @param array|string|null $value
     *
     * @return array<string>
     */
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
}