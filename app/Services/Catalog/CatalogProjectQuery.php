<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

/**
 * A validated, provider-agnostic request for the normalized details of a
 * single catalog project. Used by the details UI when the browsing context is
 * not available (for example when opening a project straight from the
 * installed-modpacks manager).
 */
final readonly class CatalogProjectQuery
{
    public const DEFAULT_PROVIDER = 'modrinth';

    public const PROJECT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public const SLUG_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public string $provider;

    public string $project;

    public function __construct(
        string $provider = self::DEFAULT_PROVIDER,
        ?string $project = null,
    ) {
        $this->provider = trim($provider);

        if (preg_match(self::SLUG_PATTERN, $this->provider) !== 1) {
            throw new InvalidArgumentException(
                'Invalid catalog provider.',
            );
        }

        if ($project === null || trim($project) === '') {
            throw new InvalidArgumentException(
                'The project parameter is required.',
            );
        }

        $project = trim($project);

        if (preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            throw new InvalidArgumentException(
                'Invalid project parameter.',
            );
        }

        $this->project = $project;
    }
}