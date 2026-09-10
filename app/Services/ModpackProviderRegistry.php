<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Providers\ModpackProvider;

final class ModpackProviderRegistry
{
    /**
     * @param ModpackProvider[] $providers
     */
    public function __construct(
        private array $providers,
    ) {}

    public function resolve(string $source): ModpackProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($source)) {
                return $provider;
            }
        }

        throw new InvalidArgumentException(
            "No provider supports source: {$source}"
        );
    }
}
