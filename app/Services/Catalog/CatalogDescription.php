<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use InvalidArgumentException;

/**
 * A provider-rendered long description for a catalog project, already
 * converted to a sanitized HTML fragment safe for client rendering.
 *
 * Security contract: `html` must never contain raw third-party markup as-is.
 * Markdown sources are escaped before formatting; HTML sources are reduced to
 * an allowlist with event handlers and scripting URLs stripped.
 */
final readonly class CatalogDescription
{
    /**
     * @param array<int, string> $imageUrls URLs of images referenced by the
     *                                     description, so the client can
     *                                     lazy-load them.
     */
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

    /**
     * @return array{provider: string, project: string, html: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'project' => $this->project,
            'html' => $this->html,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            provider: (string) ($payload['provider'] ?? ''),
            project: (string) ($payload['project'] ?? ''),
            html: (string) ($payload['html'] ?? ''),
        );
    }
}
