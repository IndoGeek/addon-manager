import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

interface ModpackMetadata {
    id: string;
    name: string;
    version: string;
    minecraft_version: string;
    loader: string;
    description: string | null;
    icon_url: string | null;
    source: string;
}

interface MetadataResponse {
    data: ModpackMetadata;
}

interface PreviewOperation {
    path: string;
    action: string;
}

interface InstallationPreview {
    total_files: number;
    create_count: number;
    overwrite_count: number;
    operations: PreviewOperation[];
}

interface PreviewResponse {
    data: InstallationPreview;
}

interface InstallationResult {
    total_files: number;
    created: number;
    overwritten: number;
    backed_up: number;
}

interface InstallResponse {
    data: InstallationResult;
}

interface StatusMessage {
    kind: 'error' | 'info' | 'success';
    message: string;
}

interface CatalogItem {
    provider: string;
    provider_project_id: string;
    slug: string | null;
    name: string;
    summary: string | null;
    icon_url: string | null;
    project_url: string | null;
    downloads: number | null;
    follows: number | null;
    categories: string[];
    game_versions: string[];
    loaders: string[];
    latest_version: string | null;
    source: string;
}

interface CatalogPagination {
    page: number;
    limit: number;
    total: number;
    total_pages: number;
    has_next: boolean;
    has_previous: boolean;
}

interface CatalogResponseData {
    items: CatalogItem[];
    pagination: CatalogPagination;
    provider: string;
    filters: {
        query: string | null;
        game_version: string | null;
        loader: string | null;
        category: string | null;
    };
    sort: string;
}

interface CatalogResponse {
    data: CatalogResponseData;
}

interface CatalogProviderOption {
    name: string;
    label: string;
    available: boolean;
}

interface ProvidersResponse {
    data: CatalogProviderOption[];
}

interface CatalogFilters {
    provider: string;
    query: string;
    gameVersion: string;
    loader: string;
    category: string;
    sort: string;
    page: number;
}

const API_BASE =
    '/api/client/extensions/modpackinstaller';

const DEFAULT_PROVIDER = 'modrinth';

const PAGE_LIMIT = 20;

const GAME_VERSIONS = [
    '1.21.4',
    '1.21.1',
    '1.20.1',
    '1.20',
    '1.19.4',
    '1.19.2',
    '1.18.2',
];

const LOADERS = [
    'fabric',
    'forge',
    'quilt',
    'neoforge',
    'liteloader',
];

const CATEGORIES = [
    'adventure',
    'building',
    'combat',
    'decoration',
    'magic',
    'optimization',
    'storage',
    'technology',
    'utility',
];

const SORT_OPTIONS: Array<{ value: string; label: string }> = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Most downloads' },
    { value: 'follows', label: 'Most follows' },
    { value: 'newest', label: 'Newest' },
    { value: 'updated', label: 'Recently updated' },
];

const getServerIdentifier = (): string | null => {
    const match = window.location.pathname.match(
        /^\/server\/([^/]+)/,
    );

    return match?.[1] ?? null;
};

const formatCount = (value: number): string => {
    if (value >= 1_000_000) {
        return (
            (value / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M'
        );
    }

    if (value >= 1_000) {
        return (
            (value / 1_000).toFixed(1).replace(/\.0$/, '') + 'K'
        );
    }

    return String(value);
};

const ModpackIcon = ({ item }: { item: CatalogItem }) => {
    const mounted = useRef(true);

    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [item.icon_url, item.provider_project_id]);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    if (!item.icon_url || failed) {
        const initial =
            item.name.trim().charAt(0).toUpperCase() || '?';

        return (
            <div
                className="modpackinstaller-card-image modpackinstaller-card-image--fallback"
                aria-hidden="true"
            >
                {initial}
            </div>
        );
    }

    return (
        <img
            src={item.icon_url}
            alt=""
            className="modpackinstaller-card-image"
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => {
                if (mounted.current) {
                    setFailed(true);
                }
            }}
        />
    );
};

export default () => {
    const server = getServerIdentifier();

    const alive = useRef(true);

    const requestId = useRef(0);

    const debounceTimer = useRef<number | null>(null);

    const initialSearchRan = useRef(false);

    useEffect(() => {
        return () => {
            alive.current = false;

            if (debounceTimer.current !== null) {
                window.clearTimeout(debounceTimer.current);
            }
        };
    }, []);

    const [source, setSource] =
        useState('mock://example-pack');

    const [metadata, setMetadata] =
        useState<ModpackMetadata | null>(null);

    const [selectedSource, setSelectedSource] =
        useState<string | null>(null);

    const [policy, setPolicy] =
        useState('overwrite');

    const [layout, setLayout] =
        useState('direct');

    const [preview, setPreview] =
        useState<InstallationPreview | null>(null);

    const [result, setResult] =
        useState<InstallationResult | null>(null);

    const [loading, setLoading] =
        useState(false);

    const [previewLoading, setPreviewLoading] =
        useState(false);

    const [installLoading, setInstallLoading] =
        useState(false);

    const [status, setStatus] =
        useState<StatusMessage | null>(null);

    const busy = loading || previewLoading || installLoading;

    const [providers, setProviders] =
        useState<CatalogProviderOption[] | null>(null);

    const [providersError, setProvidersError] =
        useState<string | null>(null);

    const [filters, setFilters] = useState<CatalogFilters>({
        provider: DEFAULT_PROVIDER,
        query: '',
        gameVersion: '',
        loader: '',
        category: '',
        sort: 'relevance',
        page: 1,
    });

    const [items, setItems] =
        useState<CatalogItem[] | null>(null);

    const [pagination, setPagination] =
        useState<CatalogPagination | null>(null);

    const [searching, setSearching] =
        useState(false);

    const [catalogError, setCatalogError] =
        useState<string | null>(null);

    const filtersRef = useRef(filters);

    filtersRef.current = filters;

    const runSearch = async (next: CatalogFilters) => {
        const id = ++requestId.current;

        setSearching(true);
        setCatalogError(null);

        const params: Record<string, string | number> = {
            provider: next.provider,
            sort: next.sort,
            page: next.page,
            limit: PAGE_LIMIT,
        };

        if (next.query.trim() !== '') {
            params.query = next.query.trim();
        }

        if (next.gameVersion !== '') {
            params.game_version = next.gameVersion;
        }

        if (next.loader !== '') {
            params.loader = next.loader;
        }

        if (next.category !== '') {
            params.category = next.category;
        }

        try {
            const response =
                await axios.get<CatalogResponse>(
                    `${API_BASE}/catalog`,
                    { params },
                );

            if (!alive.current || id !== requestId.current) {
                return;
            }

            const data = response.data.data;

            setItems(data.items);
            setPagination(data.pagination);
        } catch (requestError: any) {
            if (!alive.current || id !== requestId.current) {
                return;
            }

            setItems(null);
            setPagination(null);
            setCatalogError(
                requestError.response?.data?.error ||
                'Unable to load the modpack catalog.',
            );
        } finally {
            if (alive.current && id === requestId.current) {
                setSearching(false);
            }
        }
    };

    useEffect(() => {
        let cancelled = false;
        let chosenProvider = DEFAULT_PROVIDER;

        const fetchProviders = async () => {
            try {
                const response =
                    await axios.get<ProvidersResponse>(
                        `${API_BASE}/catalog/providers`,
                    );

                if (cancelled || !alive.current) {
                    return;
                }

                setProviders(response.data.data);

                const available = response.data.data.filter(
                    (provider) => provider.available,
                );

                if (
                    available.length > 0
                    && !available.some(
                        (provider) =>
                            provider.name === DEFAULT_PROVIDER,
                    )
                ) {
                    chosenProvider = available[0].name;

                    setFilters((current) => ({
                        ...current,
                        provider: chosenProvider,
                    }));
                }
            } catch {
                if (cancelled || !alive.current) {
                    return;
                }

                setProvidersError(
                    'Unable to load catalog providers.',
                );
            }

            if (!initialSearchRan.current) {
                initialSearchRan.current = true;

                if (alive.current) {
                    runSearch({
                        ...filtersRef.current,
                        provider: chosenProvider,
                        page: 1,
                    });
                }
            }
        };

        fetchProviders();

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onQueryChange = (value: string) => {
        setFilters((current) => ({
            ...current,
            query: value,
        }));

        if (debounceTimer.current !== null) {
            window.clearTimeout(debounceTimer.current);
        }

        debounceTimer.current = window.setTimeout(() => {
            if (!alive.current) {
                return;
            }

            debounceTimer.current = null;
            runSearch({ ...filtersRef.current, page: 1 });
        }, 450);
    };

    const submitQuery = () => {
        if (debounceTimer.current !== null) {
            window.clearTimeout(debounceTimer.current);
            debounceTimer.current = null;
        }

        runSearch({ ...filtersRef.current, page: 1 });
    };

    const applyFilter = (patch: Partial<CatalogFilters>) => {
        setFilters((current) => ({
            ...current,
            ...patch,
            page: 1,
        }));

        runSearch({
            ...filtersRef.current,
            ...patch,
            page: 1,
        });
    };

    const goToPage = (page: number) => {
        if (page < 1) {
            return;
        }

        applyFilter({ page });
    };

    const updateSource = (value: string) => {
        setSource(value);
        setPreview(null);
        setResult(null);
        setStatus(null);

        if (value.trim() !== selectedSource) {
            setMetadata(null);
            setSelectedSource(null);
        }
    };

    const selectCatalogItem = (item: CatalogItem) => {
        if (busy) {
            return;
        }

        setSource(item.source);
        setMetadata(null);
        setSelectedSource(null);
        setPreview(null);
        setResult(null);
        setStatus(null);

        fetchMetadata(item.source);
    };

    const fetchMetadata = async (targetSource: string) => {
        const trimmedSource = targetSource.trim();

        if (!trimmedSource) {
            setStatus({
                kind: 'error',
                message: 'Please enter a modpack source.',
            });
            setMetadata(null);
            setSelectedSource(null);
            setPreview(null);
            setResult(null);
            return;
        }

        if (loading) {
            return;
        }

        setLoading(true);
        setStatus(null);
        setMetadata(null);
        setSelectedSource(null);
        setPreview(null);
        setResult(null);

        try {
            const response =
                await axios.get<MetadataResponse>(
                    `${API_BASE}/metadata`,
                    {
                        params: {
                            source: trimmedSource,
                        },
                    },
                );

            if (!alive.current) {
                return;
            }

            setMetadata(response.data.data);
            setSelectedSource(trimmedSource);
            setStatus({
                kind: 'info',
                message: `Metadata loaded for ${trimmedSource}.`,
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to load modpack metadata.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setLoading(false);
            }
        }
    };

    const loadManualSource = () => {
        fetchMetadata(source);
    };

    const previewInstallation = async () => {
        if (!server) {
            setStatus({
                kind: 'error',
                message:
                    'Unable to determine the current server.',
            });
            return;
        }

        if (!selectedSource) {
            setStatus({
                kind: 'error',
                message:
                    'Load a modpack before previewing installation.',
            });
            return;
        }

        if (previewLoading || installLoading) {
            return;
        }

        setPreviewLoading(true);
        setStatus(null);
        setPreview(null);
        setResult(null);

        try {
            const response =
                await axios.post<PreviewResponse>(
                    `${API_BASE}/servers/${server}/preview`,
                    {
                        source: selectedSource,
                        policy,
                        layout,
                    },
                );

            if (!alive.current) {
                return;
            }

            setPreview(response.data.data);
            setStatus({
                kind: 'info',
                message:
                    'Installation preview is ready. Review the operations below.',
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to preview the modpack installation.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setPreviewLoading(false);
            }
        }
    };

    const installModpack = async () => {
        if (!server) {
            setStatus({
                kind: 'error',
                message:
                    'Unable to determine the current server.',
            });
            return;
        }

        if (!selectedSource) {
            setStatus({
                kind: 'error',
                message:
                    'Load a modpack before installing.',
            });
            return;
        }

        if (!preview) {
            setStatus({
                kind: 'error',
                message:
                    'Preview the installation before installing.',
            });
            return;
        }

        if (installLoading) {
            return;
        }

        setInstallLoading(true);
        setStatus(null);
        setResult(null);

        try {
            const response =
                await axios.post<InstallResponse>(
                    `${API_BASE}/servers/${server}/install`,
                    {
                        source: selectedSource,
                        policy,
                        layout,
                    },
                );

            if (!alive.current) {
                return;
            }

            setResult(response.data.data);
            setPreview(null);
            setStatus({
                kind: 'success',
                message: 'Installation complete.',
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to install the modpack.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setInstallLoading(false);
            }
        }
    };

    const selectLayout = (value: string) => {
        setLayout(value);
        setPreview(null);
        setResult(null);
        setStatus(null);
    };

    const selectPolicy = (value: string) => {
        setPolicy(value);
        setPreview(null);
        setResult(null);
        setStatus(null);
    };

    const providerList = providers ?? [];

    const providerOptions = providerList.filter(
        (provider) =>
            provider.available || provider.name === filters.provider,
    );

    const catalogBusy = searching || providerList.length === 0;

    return (
        <div
            className="modpackinstaller-root"
            aria-busy={busy || searching}
        >
            <div className="modpackinstaller-header">
                <h2>Modpack Installer</h2>

                <p>
                    Browse, search, and install a modpack directly
                    onto this server.
                </p>
            </div>

            {status && (
                <div
                    className={`modpackinstaller-status modpackinstaller-status--${status.kind}`}
                    role={
                        status.kind === 'error'
                            ? 'alert'
                            : 'status'
                    }
                >
                    {status.message}
                </div>
            )}

            <div className="modpackinstaller-card">
                <h3>Browse modpacks</h3>

                <p>
                    Search and filter modpacks from the available
                    providers, then use one to install.
                </p>

                <div className="modpackinstaller-catalog-toolbar">
                    <div className="modpackinstaller-catalog-search">
                        <label htmlFor="modpackinstaller-search">
                            Search
                        </label>

                        <div className="modpackinstaller-catalog-search-row">
                            <input
                                id="modpackinstaller-search"
                                type="search"
                                value={filters.query}
                                onChange={(event) =>
                                    onQueryChange(
                                        event.target.value,
                                    )
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        submitQuery();
                                    }
                                }}
                                placeholder="Search modpacks"
                                disabled={catalogBusy}
                            />

                            <button
                                type="button"
                                onClick={submitQuery}
                                disabled={catalogBusy}
                            >
                                Search
                            </button>
                        </div>
                    </div>

                    <div className="modpackinstaller-catalog-filters">
                        <div>
                            <label htmlFor="modpackinstaller-provider">
                                Provider
                            </label>

                            <select
                                id="modpackinstaller-provider"
                                value={filters.provider}
                                onChange={(event) =>
                                    applyFilter({
                                        provider: event.target.value,
                                    })
                                }
                                disabled={catalogBusy}
                            >
                                {providerOptions.map((provider) => (
                                    <option
                                        key={provider.name}
                                        value={provider.name}
                                        disabled={!provider.available}
                                    >
                                        {provider.available
                                            ? provider.label
                                            : `${provider.label} (not available)`}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="modpackinstaller-game-version">
                                Game version
                            </label>

                            <select
                                id="modpackinstaller-game-version"
                                value={filters.gameVersion}
                                onChange={(event) =>
                                    applyFilter({
                                        gameVersion:
                                            event.target.value,
                                    })
                                }
                                disabled={catalogBusy}
                            >
                                <option value="">Any</option>
                                {GAME_VERSIONS.map((mcVersion) => (
                                    <option
                                        key={mcVersion}
                                        value={mcVersion}
                                    >
                                        {mcVersion}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="modpackinstaller-loader">
                                Loader
                            </label>

                            <select
                                id="modpackinstaller-loader"
                                value={filters.loader}
                                onChange={(event) =>
                                    applyFilter({
                                        loader: event.target.value,
                                    })
                                }
                                disabled={catalogBusy}
                            >
                                <option value="">Any</option>
                                {LOADERS.map((loader) => (
                                    <option
                                        key={loader}
                                        value={loader}
                                    >
                                        {loader}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="modpackinstaller-category">
                                Category
                            </label>

                            <select
                                id="modpackinstaller-category"
                                value={filters.category}
                                onChange={(event) =>
                                    applyFilter({
                                        category: event.target.value,
                                    })
                                }
                                disabled={catalogBusy}
                            >
                                <option value="">Any</option>
                                {CATEGORIES.map((category) => (
                                    <option
                                        key={category}
                                        value={category}
                                    >
                                        {category}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="modpackinstaller-sort">
                                Sort
                            </label>

                            <select
                                id="modpackinstaller-sort"
                                value={filters.sort}
                                onChange={(event) =>
                                    applyFilter({
                                        sort: event.target.value,
                                    })
                                }
                                disabled={catalogBusy}
                            >
                                {SORT_OPTIONS.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                {providersError && (
                    <div
                        className="modpackinstaller-status modpackinstaller-status--error"
                        role="alert"
                    >
                        {providersError}
                    </div>
                )}

                {catalogError && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                        <p role="alert">{catalogError}</p>

                        <button
                            type="button"
                            onClick={() =>
                                runSearch({
                                    ...filtersRef.current,
                                    page: 1,
                                })
                            }
                        >
                            Retry
                        </button>
                    </div>
                )}

                {!catalogError && searching && items === null && (
                    <div
                        className="modpackinstaller-catalog-state"
                        role="status"
                    >
                        Searching for modpacks ...
                    </div>
                )}

                {!catalogError
                    && items !== null
                    && items.length === 0
                    && !searching && (
                        <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                            <p>
                                No modpacks matched your search.
                            </p>
                        </div>
                    )}

                {!catalogError && items !== null && items.length > 0 && (
                    <>
                        <div
                            className="modpackinstaller-catalog-grid"
                            aria-busy={searching}
                        >
                            {items.map((item) => (
                                <article
                                    className="modpackinstaller-catalog-card"
                                    key={`${item.provider}:${item.provider_project_id}`}
                                >
                                    <ModpackIcon item={item} />

                                    <div className="modpackinstaller-catalog-card-content">
                                        <div className="modpackinstaller-catalog-card-header">
                                            <span className="modpackinstaller-catalog-card-badge">
                                                {item.provider}
                                            </span>

                                            <h4>{item.name}</h4>
                                        </div>

                                        <p className="modpackinstaller-catalog-card-summary">
                                            {item.summary ||
                                                'No description available.'}
                                        </p>

                                        <dl className="modpackinstaller-catalog-card-meta">
                                            {item.loaders.length > 0 && (
                                                <div>
                                                    <dt>Loader</dt>
                                                    <dd>
                                                        {item.loaders.join(' · ')}
                                                    </dd>
                                                </div>
                                            )}

                                            {item.game_versions.length > 0 && (
                                                <div>
                                                    <dt>Minecraft</dt>
                                                    <dd>
                                                        {item.game_versions.length > 2
                                                            ? `${item.game_versions
                                                                .slice(0, 2)
                                                                .join(', ')} +`
                                                            : item.game_versions.join(', ')}
                                                    </dd>
                                                </div>
                                            )}

                                            {item.downloads !== null && (
                                                <div>
                                                    <dt>Downloads</dt>
                                                    <dd>
                                                        {formatCount(item.downloads)}
                                                    </dd>
                                                </div>
                                            )}

                                            {item.follows !== null && (
                                                <div>
                                                    <dt>Follows</dt>
                                                    <dd>
                                                        {formatCount(item.follows)}
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>

                                        <div className="modpackinstaller-catalog-card-actions">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    selectCatalogItem(item)
                                                }
                                                disabled={busy || searching}
                                            >
                                                Use this modpack
                                            </button>

                                            {item.project_url && (
                                                <a
                                                    href={item.project_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    Details
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>

                        <nav
                            className="modpackinstaller-catalog-pagination"
                            aria-label="Catalog pages"
                            aria-busy={searching}
                        >
                            <button
                                type="button"
                                onClick={() =>
                                    goToPage(
                                        (pagination?.page ?? 1) - 1,
                                    )
                                }
                                disabled={
                                    !pagination?.has_previous ||
                                    searching
                                }
                            >
                                Previous
                            </button>

                            <span className="modpackinstaller-catalog-pagination-label">
                                Page {pagination?.page ?? 1} of{' '}
                                {pagination?.total_pages ?? 0}
                            </span>

                            <button
                                type="button"
                                onClick={() =>
                                    goToPage(
                                        (pagination?.page ?? 1) + 1,
                                    )
                                }
                                disabled={
                                    !pagination?.has_next ||
                                    searching
                                }
                            >
                                Next
                            </button>
                        </nav>
                    </>
                )}

                <details className="modpackinstaller-catalog-source">
                    <summary>
                        Or enter a modpack source manually
                    </summary>

                    <div className="modpackinstaller-form modpackinstaller-form--inline">
                        <label htmlFor="modpackinstaller-source">
                            Modpack source
                        </label>

                        <input
                            id="modpackinstaller-source"
                            type="text"
                            value={source}
                            onChange={(event) =>
                                updateSource(event.target.value)
                            }
                            placeholder="mock://example-pack"
                            disabled={loading}
                        />

                        <button
                            type="button"
                            onClick={loadManualSource}
                            disabled={busy}
                        >
                            Load
                        </button>

                        <p className="modpackinstaller-source-hint">
                            Sources: modrinth://project-slug ·
                            curseforge://project-id · mock://example-pack
                        </p>
                    </div>
                </details>
            </div>

            {metadata && (
                <div className="modpackinstaller-card">
                    <h3>Selected modpack</h3>

                    <div className="modpackinstaller-metadata">
                        <div className="modpackinstaller-metadata-header">
                            {metadata.icon_url && (
                                <img
                                    src={metadata.icon_url}
                                    alt=""
                                    className="modpackinstaller-icon"
                                />
                            )}

                            <div>
                                <h4>{metadata.name}</h4>

                                {metadata.description && (
                                    <p>
                                        {metadata.description}
                                    </p>
                                )}

                                <div className="modpackinstaller-selected">
                                    Selected modpack
                                </div>
                            </div>
                        </div>

                        <div className="modpackinstaller-details">
                            <div>
                                <span>Version</span>
                                <strong>
                                    {metadata.version}
                                </strong>
                            </div>

                            <div>
                                <span>Minecraft</span>
                                <strong>
                                    {metadata.minecraft_version}
                                </strong>
                            </div>

                            <div>
                                <span>Loader</span>
                                <strong>
                                    {metadata.loader}
                                </strong>
                            </div>
                        </div>

                        <div className="modpackinstaller-source">
                            <span>Source</span>
                            <code>
                                {metadata.source}
                            </code>
                        </div>
                    </div>
                </div>
            )}

            {metadata && (
                <div className="modpackinstaller-card">
                    <h3>Installation options</h3>

                    <div className="modpackinstaller-options">
                        <fieldset>
                            <legend>Package layout</legend>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-layout"
                                    value="direct"
                                    checked={
                                        layout === 'direct'
                                    }
                                    onChange={() =>
                                        selectLayout('direct')
                                    }
                                />

                                Direct
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-layout"
                                    value="overrides"
                                    checked={
                                        layout === 'overrides'
                                    }
                                    onChange={() =>
                                        selectLayout(
                                            'overrides',
                                        )
                                    }
                                />

                                Overrides
                            </label>
                        </fieldset>

                        <fieldset>
                            <legend>Existing files</legend>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="overwrite"
                                    checked={
                                        policy === 'overwrite'
                                    }
                                    onChange={() =>
                                        selectPolicy('overwrite')
                                    }
                                />

                                Overwrite
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="skip_existing"
                                    checked={
                                        policy === 'skip_existing'
                                    }
                                    onChange={() =>
                                        selectPolicy(
                                            'skip_existing',
                                        )
                                    }
                                />

                                Skip existing
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="create_only"
                                    checked={
                                        policy === 'create_only'
                                    }
                                    onChange={() =>
                                        selectPolicy(
                                            'create_only',
                                        )
                                    }
                                />

                                Create only
                            </label>
                        </fieldset>
                    </div>

                    <button
                        type="button"
                        onClick={previewInstallation}
                        disabled={
                            previewLoading ||
                            installLoading
                        }
                    >
                        {previewLoading
                            ? 'Preparing Preview ...'
                            : 'Preview Installation'}
                    </button>
                </div>
            )}

            {preview && (
                <div className="modpackinstaller-card">
                    <h3>Installation Preview</h3>

                    <div className="modpackinstaller-details">
                        <div>
                            <span>Total files</span>
                            <strong>
                                {preview.total_files}
                            </strong>
                        </div>

                        <div>
                            <span>New files</span>
                            <strong>
                                {preview.create_count}
                            </strong>
                        </div>

                        <div>
                            <span>Overwrite</span>
                            <strong>
                                {preview.overwrite_count}
                            </strong>
                        </div>
                    </div>

                    <div className="modpackinstaller-operation-list">
                        {preview.operations.map(
                            (operation) => (
                                <div
                                    key={`${operation.action}:${operation.path}`}
                                >
                                    <code>
                                        {operation.path}
                                    </code>

                                    <span>
                                        {operation.action}
                                    </span>
                                </div>
                            ),
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={installModpack}
                        disabled={
                            installLoading ||
                            previewLoading
                        }
                    >
                        {installLoading
                            ? 'Installing ...'
                            : 'Install Modpack'}
                    </button>
                </div>
            )}

            {result && (
                <div className="modpackinstaller-card">
                    <h3>Installation complete</h3>

                    <div className="modpackinstaller-result-grid">
                        <div>
                            <span>Total files</span>
                            <strong>
                                {result.total_files}
                            </strong>
                        </div>

                        <div>
                            <span>Created</span>
                            <strong>
                                {result.created}
                            </strong>
                        </div>

                        <div>
                            <span>Overwritten</span>
                            <strong>
                                {result.overwritten}
                            </strong>
                        </div>

                        <div>
                            <span>Backups</span>
                            <strong>
                                {result.backed_up}
                            </strong>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};