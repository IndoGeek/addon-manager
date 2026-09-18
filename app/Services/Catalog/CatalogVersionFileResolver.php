<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Provider\ProviderHttpException;

// Resolves the downloadable primary file for a catalog version.
final class CatalogVersionFileResolver
{
    private const MODRINTH_API_BASE = 'https://api.modrinth.com/v2';

    private const CURSEFORGE_API_BASE = 'https://api.curseforge.com/v1';

    private const CURSEFORGE_API_KEY_HEADER = 'X-Api-Key';

    // Hosts CurseForge serves its own file downloads from.
    private const CURSEFORGE_CDN_HOSTS = [
        'edge.forgecdn.net',
        'mediafilez.forgecdn.net',
    ];

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly ?string $curseForgeApiKey = null,
    ) {}

    // @return array{url: string, filename: string, size: ?int, sha1: ?string}
    public function resolve(string $provider, string $projectId, string $versionId): array
    {
        return match ($provider) {
            'modrinth' => $this->resolveModrinth($projectId, $versionId),
            'curseforge' => $this->resolveCurseForge($projectId, $versionId),
            default => throw new CatalogProviderException(
                'This content type can only be installed from Modrinth or CurseForge.',
            ),
        };
    }

    // @return array{url: string, filename: string, size: ?int, sha1: ?string}
    private function resolveModrinth(string $projectId, string $versionId): array
    {
        if (
            $versionId === ''
            || preg_match('/^[A-Za-z0-9]{8,64}$/', $versionId) !== 1
        ) {
            throw new CatalogProviderException(
                'The selected version is invalid.'
            );
        }

        try {
            $response = $this->http->get(
                self::MODRINTH_API_BASE
                    . '/version/'
                    . rawurlencode($versionId),
            );
        } catch (ProviderHttpException $exception) {
            throw new CatalogProviderException(
                'Unable to resolve the version file from Modrinth.',
                0,
                $exception,
            );
        }

        $version = $response->body;

        if (!is_array($version)) {
            throw new CatalogProviderException(
                'The catalog provider returned an invalid response.'
            );
        }

        $file = $this->primaryFile($version['files'] ?? null);

        if ($file === null) {
            throw new CatalogProviderException(
                'The selected version has no downloadable file.'
            );
        }

        $url = is_string($file['url'] ?? null) ? $file['url'] : '';
        $filename = is_string($file['filename'] ?? null)
            ? $file['filename']
            : '';

        if ($url === '' || !preg_match('#^https://cdn\.modrinth\.com/#', $url)) {
            throw new CatalogProviderException(
                'The version file URL is not a trusted Modrinth CDN address.'
            );
        }

        return [
            'url' => $url,
            'filename' => $filename,
            'size' => is_int($file['size'] ?? null) ? $file['size'] : null,
            'sha1' => is_string($file['hashes']['sha1'] ?? null)
                ? $file['hashes']['sha1']
                : null,
        ];
    }

    // CurseForge exposes one file per version, and a project may forbid automated distribution entirely.
    private function resolveCurseForge(
        string $projectId,
        string $versionId,
    ): array {
        if ($this->curseForgeApiKey === null || $this->curseForgeApiKey === '') {
            throw new CatalogProviderException(
                'CurseForge is not available. Please add your API key to the extension settings.',
            );
        }

        if (
            preg_match('/^\d{1,12}$/', $projectId) !== 1
            || preg_match('/^\d{1,12}$/', $versionId) !== 1
        ) {
            throw new CatalogProviderException(
                'The selected version is invalid.'
            );
        }

        try {
            $response = $this->http->get(
                self::CURSEFORGE_API_BASE
                    . '/mods/'
                    . $projectId
                    . '/files/'
                    . $versionId,
                // Header LINE, not a map: see the note in the version catalog.
                headers: [
                    self::CURSEFORGE_API_KEY_HEADER
                        . ': '
                        . $this->curseForgeApiKey,
                ],
            );
        } catch (ProviderHttpException $exception) {
            throw new CatalogProviderException(
                'Unable to resolve the version file from CurseForge.',
                0,
                $exception,
            );
        }

        $file = is_array($response->body)
            ? ($response->body['data'] ?? null)
            : null;

        if (!is_array($file)) {
            throw new CatalogProviderException(
                'The catalog provider returned an invalid response.'
            );
        }

        if (($file['isAvailable'] ?? true) === false) {
            throw new CatalogProviderException(
                'This CurseForge file is no longer available for download.',
            );
        }

        $url = is_string($file['downloadUrl'] ?? null)
            ? trim($file['downloadUrl'])
            : '';

        if ($url === '') {
            throw new CatalogProviderException(
                'This CurseForge file does not allow automated downloads. '
                    . 'Please install it manually from CurseForge.',
            );
        }

        if (!$this->isTrustedCurseForgeUrl($url)) {
            throw new CatalogProviderException(
                'The version file URL is not a trusted CurseForge CDN address.'
            );
        }

        return [
            'url' => $url,
            'filename' => is_string($file['fileName'] ?? null)
                ? $file['fileName']
                : '',
            'size' => is_int($file['fileLength'] ?? null)
                ? $file['fileLength']
                : null,
            'sha1' => is_string($file['hashes']['sha1'] ?? null)
                ? $file['hashes']['sha1']
                : null,
        ];
    }

    private function isTrustedCurseForgeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme !== 'https') {
            return false;
        }

        return in_array(strtolower($host), self::CURSEFORGE_CDN_HOSTS, true);
    }

    // @param mixed $files @return array<string, mixed>|null
    private function primaryFile(mixed $files): ?array
    {
        if (!is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['primary'] ?? false) === true) {
                return $file;
            }
        }

        foreach ($files as $file) {
            if (is_array($file)) {
                return $file;
            }
        }

        return null;
    }
}
