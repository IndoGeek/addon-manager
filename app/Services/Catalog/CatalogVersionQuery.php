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

    public string $provider;

    public string $project;

    public ?string $gameVersion;

    public ?string $loader;

    public function __construct(
        string $provider = self::DEFAULT_PROVIDER,
        ?string $project = null,
        ?string $gameVersion = null,
        ?string $loader = null,
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
