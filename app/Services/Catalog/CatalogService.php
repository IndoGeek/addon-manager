<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

/**
 * Application-level catalog facade used by the HTTP layer. It resolves the
 * requested provider, refuses unavailable providers, exposes the list of
 * providers (with their availability, capabilities and facet options) for the
 * UI, and resolves normalized project details for the details view.
 *
 * Search/version/project responses are cached for a short TTL (panel Redis
 * through the shared cache repository) keyed by the exact query, so repeat
 * views and provider switches render instantly instead of re-hitting the
 * upstream APIs on every open.
 */
final class CatalogService
{
    public function __construct(
        private readonly CatalogProviderRegistry $registry,
        private readonly ?CatalogCache $cache = null,
    ) {
    }

    /**
     * The provider the UI should preselect: the first provider that is
     * available and not development-only, or the plain first provider name.
     */
    public function defaultProvider(): string
    {
        $first = null;

        foreach ($this->registry->all() as $provider) {
            $first ??= $provider->name();

            if (
                $provider->available()
                && !$provider->developmentOnly()
            ) {
                return $provider->name();
            }
        }

        return $first ?? CatalogSearchQuery::DEFAULT_PROVIDER;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->registry->all() as $provider) {
            $providers[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'available' => $provider->available(),
                'state' => $provider->state(),
                'development_only' => $provider->developmentOnly(),
                'development' => $provider->developmentOnly(),
                'unavailable_reason' => $provider->unavailableReason(),
                'capabilities' => $provider->capabilities(),
                'facets' => $provider->facets(),
            ];
        }

        return $providers;
    }

    public function search(CatalogSearchQuery $query): CatalogResult
    {
        $provider = $this->registry->get($query->provider);

        if (!$provider->available()) {
            throw new CatalogUnavailableException(
                $this->unavailableMessage($provider),
            );
        }

        $key = CatalogCache::key('search', [
            'provider' => $query->provider,
            'query' => $query->query,
            'game_versions' => $query->gameVersions,
            'loaders' => $query->loaders,
            'categories' => $query->categories,
            'environments' => $query->environments,
            'sort' => $query->sort->value,
            'page' => $query->page,
            'limit' => $query->limit,
        ]);

        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            return CatalogResult::fromArray($cached);
        }

        $result = $provider->search($query);

        $this->cache?->put($key, $result->toArray());

        return $result;
    }

    public function versions(CatalogVersionQuery $query): CatalogVersionList
    {
        $provider = $this->registry->get($query->provider);

        if (!$provider->available()) {
            throw new CatalogUnavailableException(
                $this->unavailableMessage($provider),
            );
        }

        $key = CatalogCache::key('versions', [
            'provider' => $query->provider,
            'project' => $query->project,
            'game_versions' => $query->gameVersions,
            'loaders' => $query->loaders,
        ]);

        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            return CatalogVersionList::fromArray($cached);
        }

        $list = new CatalogVersionList(
            provider: $provider->name(),
            appliedGameVersions: $query->gameVersions,
            appliedLoaders: $query->loaders,
            versions: $provider->versions($query),
        );

        $this->cache?->put($key, $list->toArray());

        return $list;
    }

    public function project(CatalogProjectQuery $query): CatalogItem
    {
        $provider = $this->registry->get($query->provider);

        if (!$provider->available()) {
            throw new CatalogUnavailableException(
                $this->unavailableMessage($provider),
            );
        }

        $key = CatalogCache::key('project', [
            'provider' => $query->provider,
            'project' => $query->project,
        ]);

        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            return CatalogItem::fromArray($cached);
        }

        $item = $provider->project($query);

        $this->cache?->put($key, $item->toArray());

        return $item;
    }

    private function unavailableMessage(CatalogProvider $provider): string
    {
        $reason = $provider->unavailableReason();

        if ($reason !== null && $reason !== '') {
            return $reason;
        }

        return 'The requested modpack catalog provider is not available.';
    }
}