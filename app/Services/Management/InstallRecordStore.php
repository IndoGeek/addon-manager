<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

// Extension-owned JSON store of installed-modpack records.
final class InstallRecordStore
{
    private const FILE = 'installs.json';

    private const FORMAT_VERSION = 1;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    // @return list<InstallRecord> newest first
    public function all(string $serverUuid): array
    {
        $serverUuid = $this->serverUuid($serverUuid);

        $records = [];

        foreach ($this->load() as $entry) {
            if (($entry['server_uuid'] ?? null) !== $serverUuid) {
                continue;
            }

            $record = $this->hydrate($entry);

            if ($record === null || $record->status !== InstallRecord::STATUS_INSTALLED) {
                continue;
            }

            $records[] = $record;
        }

        usort($records, static fn (
            InstallRecord $a,
            InstallRecord $b,
        ): int => strcmp($b->installedAt, $a->installedAt));

        return $records;
    }

    public function find(
        string $serverUuid,
        string $id,
    ): ?InstallRecord {
        if ($id === '') {
            return null;
        }

        $serverUuid = $this->serverUuid($serverUuid);

        foreach ($this->load() as $entry) {
            if (
                ($entry['server_uuid'] ?? null) === $serverUuid
                && ($entry['id'] ?? null) === $id
            ) {
                return $this->hydrate($entry);
            }
        }

        return null;
    }

    public function findOneBySource(
        string $serverUuid,
        string $source,
    ): ?InstallRecord {
        $serverUuid = $this->serverUuid($serverUuid);

        foreach ($this->load() as $entry) {
            if (
                ($entry['server_uuid'] ?? null) === $serverUuid
                && ($entry['source'] ?? null) === $source
            ) {
                return $this->hydrate($entry);
            }
        }

        return null;
    }

    public function save(InstallRecord $record): void
    {
        $this->mutate(static function (array $records) use ($record): array {
            $key = $record->serverUuid . ':' . $record->id;

            $replaced = false;

            $records = array_map(static function (array $entry) use ($record, $key, &$replaced): array {
                if (
                    ($entry['server_uuid'] ?? null) === $record->serverUuid
                    && ($entry['id'] ?? null) === $record->id
                ) {
                    $replaced = true;

                    return $record->toArray();
                }

                return $entry;
            }, $records);

            if (!$replaced) {
                $records[] = $record->toArray();
            }

            return $records;
        });
    }

    public function delete(string $serverUuid, string $id): void
    {
        $serverUuid = $this->serverUuid($serverUuid);

        if ($id === '') {
            return;
        }

        $this->mutate(static function (array $records) use ($serverUuid, $id): array {
            return array_values(
                array_filter(
                    $records,
                    static fn (array $entry): bool => !(
                        ($entry['server_uuid'] ?? null) === $serverUuid
                        && ($entry['id'] ?? null) === $id
                    ),
                ),
            );
        });
    }

    // @return array<int, array<string, mixed>>
    private function load(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'Unable to read the installed-modpack store.'
            );
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'The installed-modpack store is corrupted.'
            );
        }

        $records = $decoded['records'] ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'The installed-modpack store is corrupted.'
            );
        }

        return $records;
    }

    // @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $mutator
    private function mutate(callable $mutator): void
    {
        $lockPath = $this->lockPath();

        $this->ensureDirectory();

        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException(
                'Unable to open the installed-modpack store lock.'
            );
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire the installed-modpack store lock.'
                );
            }

            $records = $mutator($this->load());

            $this->persist($records);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    // @param array<int, array<string, mixed>> $records
    private function persist(array $records): void
    {
        $payload = json_encode([
            'version' => self::FORMAT_VERSION,
            'records' => array_values($records),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($payload === false) {
            throw new RuntimeException(
                'Unable to encode the installed-modpack store.'
            );
        }

        $path = $this->path();

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $payload . "\n") === false) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to write the installed-modpack store.'
            );
        }

        @chmod($temporary, 0640);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                'Unable to commit the installed-modpack store.'
            );
        }
    }

    private function hydrate(array $entry): ?InstallRecord
    {
        try {
            return InstallRecord::fromArray($entry);
        } catch (Throwable) {
            return null;
        }
    }

    private function path(): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . '/' . self::FILE;
    }

    private function lockPath(): string
    {
        return $this->path() . '.lock';
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
                'Unable to create the installed-modpack store directory.'
            );
        }
    }

    private function serverUuid(string $serverUuid): string
    {
        $trimmed = trim($serverUuid);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'A server uuid is required.'
            );
        }

        return $trimmed;
    }
}