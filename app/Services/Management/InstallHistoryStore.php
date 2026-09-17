<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use RuntimeException;
use Throwable;

// Append-only JSON audit log of install/update/restore/uninstall activity,
// shown on the extension's admin settings page. Entries are capped so the
// file cannot grow indefinitely.
final class InstallHistoryStore
{
    private const FILE = 'history.json';

    private const MAX_ENTRIES = 200;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    // Record one completed action. Best-effort by design: callers wrap this
    // so a logging failure never masks the operation's outcome.
    public function append(array $entry): void
    {
        $this->mutate(static function (array $entries) use ($entry): array {
            $entries[] = $entry;

            if (count($entries) > self::MAX_ENTRIES) {
                $entries = array_slice($entries, -self::MAX_ENTRIES);
            }

            return $entries;
        });
    }

    // @return array<int, array<string, mixed>> newest first
    public function all(int $limit = 100): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return [];
        }

        $entries = array_values(array_filter(
            $decoded,
            static fn ($entry): bool => is_array($entry),
        ));

        $entries = array_reverse($entries);

        if ($limit > 0 && count($entries) > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    // @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $mutator
    private function mutate(callable $mutator): void
    {
        $this->ensureDirectory();

        $lock = @fopen($this->path() . '.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException(
                'Unable to open the install history lock.',
            );
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire the install history lock.',
                );
            }

            $entries = $mutator($this->rawEntries());

            $this->persist($entries);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    // @return array<int, array<string, mixed>>
    private function rawEntries(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    // @param array<int, array<string, mixed>> $entries
    private function persist(array $entries): void
    {
        $payload = json_encode(
            array_values($entries),
            JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Unable to encode the install history.',
            );
        }

        $path = $this->path();

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $payload . "\n") === false) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to write the install history.',
            );
        }

        @chmod($temporary, 0640);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to commit the install history.',
            );
        }
    }

    private function path(): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . '/' . self::FILE;
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
                'Unable to create the install history directory.',
            );
        }
    }
}
