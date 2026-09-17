<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

// A provider-rendered long description for a catalog project, already converted to a sanitized HTML fragment safe for...
final readonly class CatalogDescription
{
    // description, so the client can lazy-load them.
    public function __construct(
        public string $provider,
        public string $project,
        public string $html,
    ) {
        if (trim($this->provider) === '') {
            throw new InvalidArgumentException(
                'The description provider is required.',
            );
        }

        if (trim($this->project) === '') {
            throw new InvalidArgumentException(
                'The description project is required.',
            );
        }
    }

    // @return array{provider: string, project: string, html: string}
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'project' => $this->project,
            'html' => $this->html,
        ];
    }

    // @param array<string, mixed> $payload
    public static function fromArray(array $payload): self
    {
        return new self(
            provider: (string) ($payload['provider'] ?? ''),
            project: (string) ($payload['project'] ?? ''),
            html: (string) ($payload['html'] ?? ''),
        );
    }
}
