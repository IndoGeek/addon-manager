<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerRelativePath;

/**
 * Thin, authenticated client for the Wings server file API.
 *
 * The connection address and daemon key are resolved by the backend from the
 * authenticated Pterodactyl Server/Node models and cannot be supplied by a
 * client request. URLs are constructed only from that resolved connection
 * address plus this server's UUID, so a caller can never redirect requests
 * to an arbitrary host.
 */
final class WingsFileClient
{
    private const SUCCESS = [200, 204];

    public function __construct(
        private readonly WingsTransport $transport,
        private readonly string $connectionAddress,
        private readonly string $daemonKey,
        private readonly string $serverUuid,
    ) {
        if ($connectionAddress === '') {
            throw new InvalidArgumentException(
                'A Wings connection address is required.'
            );
        }

        if ($daemonKey === '') {
            throw new InvalidArgumentException(
                'A Wings daemon key is required.'
            );
        }

        if ($serverUuid === '') {
            throw new InvalidArgumentException(
                'A server UUID is required.'
            );
        }
    }

    public function getContents(string $relativePath): string
    {
        $path = ServerRelativePath::normalize($relativePath);

        $response = $this->request('GET', '/files/contents', [
            'query' => ['file' => $path],
        ]);

        if ($response->status === 200) {
            return $response->body;
        }

        $this->throwFor($response->status);
    }

    public function putContents(string $relativePath, string $contents): void
    {
        $path = ServerRelativePath::normalize($relativePath);

        $response = $this->request('POST', '/files/write', [
            'query' => ['file' => $path],
            'body' => $contents,
        ]);

        if (in_array($response->status, self::SUCCESS, true)) {
            return;
        }

        $this->throwFor($response->status);
    }

    /**
     * @return array<int, array<string, mixed>> Wings file entry list
     */
    public function listDirectory(string $directory): array
    {
        $path = ServerRelativePath::normalizeDirectory($directory);

        $response = $this->request('GET', '/files/list-directory', [
            'query' => ['directory' => $path],
        ]);

        if ($response->status === 200) {
            $entries = json_decode($response->body, true);

            if (!is_array($entries)) {
                throw new WingsHttpException(502);
            }

            return $entries;
        }

        $this->throwFor($response->status);
    }

    public function createDirectory(string $name, string $parent): void
    {
        $normalizedName = ServerRelativePath::normalize($name);

        if (str_contains($normalizedName, '/')) {
            throw new InvalidArgumentException(
                'Directory names cannot contain path separators.'
            );
        }

        $response = $this->request('POST', '/files/create-directory', [
            'json' => [
                'name' => $normalizedName,
                'path' => ServerRelativePath::normalizeDirectory($parent),
            ],
        ]);

        if (in_array($response->status, self::SUCCESS, true)) {
            return;
        }

        $this->throwFor($response->status);
    }

    /**
     * @param array<int, string> $files
     */
    public function deleteFiles(string $root, array $files): void
    {
        $normalizedFiles = [];

        foreach ($files as $file) {
            $name = ServerRelativePath::normalize($file);

            if (str_contains($name, '/')) {
                throw new InvalidArgumentException(
                    'File names cannot contain path separators.'
                );
            }

            $normalizedFiles[] = $name;
        }

        if ($normalizedFiles === []) {
            throw new InvalidArgumentException(
                'At least one file name is required for deletion.'
            );
        }

        $response = $this->request('POST', '/files/delete', [
            'json' => [
                'root' => ServerRelativePath::normalizeDirectory($root),
                'files' => $normalizedFiles,
            ],
        ]);

        if (in_array($response->status, self::SUCCESS, true)) {
            return;
        }

        // Deleting a file that no longer exists is a no-op, mirroring the
        // local filesystem target.
        if ($response->status === 404) {
            return;
        }

        $this->throwFor($response->status);
    }

    private function request(string $method, string $endpoint, array $options): WingsTransportResponse
    {
        $url = rtrim($this->connectionAddress, '/')
            . '/api/servers/'
            . $this->serverUuid
            . $endpoint;

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->daemonKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        return $this->transport->request($method, $url, $options);
    }

    private function throwFor(int $status): never
    {
        if ($status === 404) {
            throw new WingsFileNotFoundException();
        }

        throw new WingsHttpException($status);
    }
}