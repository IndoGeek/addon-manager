<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use Throwable;

/**
 * Short-lived cache for upstream catalog responses, backed by the panel's
 * Laravel cache repository (Redis on every stock Pterodactyl install).
 *
 * Catalog payloads are small JSON trees and only safe to hold for minutes:
 * new modpack versions are published continuously, so the TTL stays short
 * and every entry carries the exact query in its key. Every operation fails
 * soft — a cache outage must degrade catalog latency, never break it.
 *
 * The cache store is injected lazily: production resolves the Laravel
 * facade on first use, tests can supply an in-memory store.
 */
final class CatalogCache
{
    /** Prefix shared with build.sh's flush step — do not rename casually. */
    public const KEY_PREFIX = 'modpackinstaller:catalog:';

    private ?object $store = null;

    private ?bool $available = null;

    public function __construct(
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Builds a deterministic cache key from the request coordinates.
     *
     * @param array<int|string, mixed> $parts
     */
    public static function key(string $scope, array $parts): string
    {
        $normalized = [];

        foreach ($parts as $name => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $normalized[$name] = $value;
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);

        return self::KEY_PREFIX
            . $scope
            . ':'
            . hash('sha256', $encoded === false ? '' : $encoded);
    }

    /**
     * Returns the cached payload for the key, or null on miss/unavailability.
     *
     * @return mixed
     */
    public function get(string $key)
    {
        $store = $this->usableStore();

        if ($store === null) {
            return null;
        }

        try {
            return $store->get($key);
        } catch (Throwable) {
            $this->available = false;

            return null;
        }
    }

    /**
     * Stores the payload under the key. Failures are swallowed: a broken
     * cache store must never fail a request that already has fresh data.
     *
     * @param mixed $value
     */
    public function put(string $key, $value): void
    {
        $store = $this->usableStore();

        if ($store === null) {
            return;
        }

        try {
            $store->put($key, $value, $this->ttlSeconds);
        } catch (Throwable) {
            $this->available = false;
        }
    }

    /**
     * Removes every entry this extension owns (the well-known scope keys).
     * Never touches the panel's own cache entries.
     */
    public function flush(): void
    {
        $store = $this->usableStore();

        if ($store === null) {
            return;
        }

        foreach (['providers'] as $scope) {
            try {
                $store->forget(self::KEY_PREFIX . $scope);
            } catch (Throwable) {
                // A flush failure must never break the caller.
            }
        }
    }

    /**
     * Injects a custom cache store (used by tests). Must expose
     * get(string): mixed, put(string, mixed, int): void, forget(string): void.
     */
    public function useStore(object $store): void
    {
        $this->store = $store;
        $this->available = null;
    }

    /**
     * Resolves the cache store lazily: first use probes the Laravel cache
     * facade and remembers the verdict, so a panel with broken Redis never
     * retries on every request. Returns null when unavailable.
     */
    private function usableStore(): ?object
    {
        if ($this->available !== null) {
            return $this->available ? $this->store : null;
        }

        if ($this->store === null) {
            $this->store = new class {
                public function get(string $key)
                {
                    return \Illuminate\Support\Facades\Cache::get($key);
                }

                public function put(string $key, $value, int $ttl): void
                {
                    \Illuminate\Support\Facades\Cache::put($key, $value, $ttl);
                }

                public function forget(string $key): void
                {
                    \Illuminate\Support\Facades\Cache::forget($key);
                }
            };
        }

        try {
            $this->store->get(self::KEY_PREFIX . 'probe');

            $this->available = true;
        } catch (Throwable) {
            $this->available = false;

            return null;
        }

        return $this->store;
    }
}
