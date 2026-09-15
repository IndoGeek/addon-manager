<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use RuntimeException;

/**
 * File-backed store that surfaces installation progress across requests.
 *
 * The installing request streams progress into a per-token JSON file while the
 * panel frontend polls it through a read-only endpoint. Files are written
 * atomically so a reader can never observe a partial update, and stale files
 * expire by timestamp so abandoned tokens cannot accumulate.
 */
final class InstallProgressStore
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function set(
        string $token,
        array $state,
        int $ttlSeconds = 600,
    ): void {
        $this->ensureDirectory();

        $path = $this->path($token);

        $payload = json_encode([
            'expires_at' => time() + max(1, $ttlSeconds),
            'state' => $state,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException(
                'Unable to encode installation progress.'
            );
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $payload . "\n") === false) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to write installation progress.'
            );
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to commit installation progress.'
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        $path = $this->path($token);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || !isset($decoded['state'])) {
            @unlink($path);

            return null;
        }

        if (time() > (int) ($decoded['expires_at'] ?? 0)) {
            @unlink($path);

            return null;
        }

        return $decoded['state'];
    }

    private function path(string $token): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . '/' . $token . '.json';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (
            !@mkdir($this->directory, 0750, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                'Unable to create the installation progress directory.'
            );
        }
    }
}