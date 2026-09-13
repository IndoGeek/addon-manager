import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

interface ManualDownloadInfo {
    provider: string;
    project_name: string;
    project_url: string | null;
    file_name: string;
    version: string;
    download_url: string | null;
    reason: string;
}

interface ModpackMetadata {
    id: string;
    name: string;
    version: string;
    minecraft_version: string;
    loader: string;
    description: string | null;
    icon_url: string | null;
    source: string;
    manual_download: ManualDownloadInfo | null;
}

interface MetadataResponse {
    data: ModpackMetadata;
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

interface InstallRecordData {
    id: string;
    server_uuid: string;
    provider: string;
    project_id: string;
    version_id: string | null;
    source: string;
    display_name: string;
    version: string;
    minecraft_version: string | null;
    loader: string | null;
    installed_at: string;
    updated_at: string;
    status: string;
    ownership: {
        created: string[];
        overwritten: string[];
    };
}

interface InstalledModpacksResponse {
    data: InstallRecordData[];
}

interface UninstallResponse {
    data: {
        id: string;
        display_name: string;
        version: string;
        removed: number;
        missing: number;
    };
}

interface UpdateResponse {
    data: {
        id: string;
        display_name: string;
        previous_version: string;
        version: string;
        total_files: number;
        created: number;
        overwritten: number;
        backed_up: number;
    };
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

interface CatalogVersion {
    provider: string;
    project_id: string;
    project_slug: string | null;
    project_name: string | null;
    version_id: string;
    version_number: string;
    version_name: string | null;
    game_versions: string[];
    loaders: string[];
    date_published: string | null;
    date_modified: string | null;
    downloads: number | null;
    source: string;
}

interface CatalogVersionsResponse {
    data: {
        provider: string;
        filters: {
            game_versions: string[];
            loaders: string[];
        };
        versions: CatalogVersion[];
    };
}

interface CatalogResponseData {
    items: CatalogItem[];
    pagination: CatalogPagination;
    provider: string;
    filters: {
        query: string | null;
        game_versions: string[];
        loaders: string[];
        categories: string[];
        environments: string[];
    };
    sort: string;
}

interface CatalogResponse {
    data: CatalogResponseData;
}

interface ProviderCapabilities {
    query: boolean;
    game_versions: boolean;
    loaders: boolean;
    categories: boolean;
    environment: boolean;
    sort: boolean;
}

interface ProviderFacets {
    game_versions: string[];
    loaders: string[];
    categories: string[];
    environments: string[];
}

interface CatalogProviderOption {
    name: string;
    label: string;
    available: boolean;
    state: string;
    development_only: boolean;
    development: boolean;
    unavailable_reason: string | null;
    capabilities: ProviderCapabilities;
    facets: ProviderFacets;
}

interface ProvidersResponse {
    data: {
        providers: CatalogProviderOption[];
        default_provider: string;
        pagination: {
            default_page: number;
            default_limit: number;
        };
    };
}

interface CatalogFilters {
    provider: string;
    query: string;
    gameVersions: string[];
    loaders: string[];
    categories: string[];
    environment: string;
    sort: string;
    page: number;
}

type MultiFilterKey = 'gameVersions' | 'loaders' | 'categories';

const API_BASE =
    '/api/client/extensions/modpackinstaller';

const DEFAULT_PROVIDER = 'modrinth';

const PAGE_LIMIT = 20;

const VIEW_STORAGE_KEY = 'modpackinstaller-view';

const SORT_OPTIONS: Array<{ value: string; label: string }> = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Most downloads' },
    { value: 'follows', label: 'Most follows' },
    { value: 'newest', label: 'Newest' },
    { value: 'updated', label: 'Recently updated' },
];

const ENVIRONMENT_OPTIONS: Array<{
    value: string;
    label: string;
}> = [
    { value: 'client', label: 'Client' },
    { value: 'server', label: 'Server' },
    { value: 'client-and-server', label: 'Client + Server' },
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

const formatDate = (value: string): string => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
};

const versionLabel = (version: CatalogVersion): string => {
    const suffix: string[] = [];

    if (version.game_versions.length > 0) {
        suffix.push(
            version.game_versions.length > 2
                ? `${version.game_versions.slice(0, 2).join(', ')} +`
                : version.game_versions.join(', '),
        );
    }

    if (version.loaders.length > 0) {
        suffix.push(version.loaders.join('/'));
    }

    return suffix.length > 0
        ? `${version.version_number} (${suffix.join(' · ')})`
        : version.version_number;
};

const uniqueSorted = (
    values: string[],
    fallback: string[],
): string[] => {
    const seen = new Set<string>();

    const combined = [...values, ...fallback].filter(
        (value) => value.trim() !== '',
    );

    combined.forEach((value) => seen.add(value));

    return Array.from(seen);
};

interface DropdownOption {
    value: string;
    label: string;
    detail?: string;
    disabled?: boolean;
}

const Dropdown = ({
    id,
    label,
    value,
    onChange,
    options,
    disabled,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: DropdownOption[];
    disabled?: boolean;
}) => {
    const [open, setOpen] = useState(false);

    const wrapperRef = useRef<HTMLDivElement | null>(null);

    const menuRef = useRef<HTMLDivElement | null>(null);

    const selected = options.find((option) => option.value === value);

    const focusOption = (index: number) => {
        const menu = menuRef.current;

        if (!menu) {
            return;
        }

        const buttons = Array.from(
            menu.querySelectorAll(
                'button[role="option"]:not(:disabled)',
            ),
        ) as HTMLButtonElement[];

        if (buttons.length === 0) {
            return;
        }

        const clamped =
            ((index % buttons.length) + buttons.length) %
            buttons.length;

        buttons[clamped].focus();
    };

    const onTriggerKeyDown = (
        event: React.KeyboardEvent<HTMLButtonElement>,
    ) => {
        if (event.key === 'Enter' || event.key === ' ') {
            setOpen((current) => !current);
            event.preventDefault();

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();

        if (!open) {
            setOpen(true);
            window.setTimeout(() => {
                focusOption(
                    event.key === 'ArrowUp'
                        ? options.length - 1
                        : 0,
                );
            }, 0);

            return;
        }

        const menu = menuRef.current;

        if (!menu) {
            return;
        }

        const buttons = Array.from(
            menu.querySelectorAll(
                'button[role="option"]:not(:disabled)',
            ),
        ) as HTMLButtonElement[];

        if (buttons.length === 0) {
            return;
        }

        const currentIndex = buttons.findIndex(
            (button) => button === document.activeElement,
        );

        focusOption(
            event.key === 'ArrowDown'
                ? currentIndex + 1
                : currentIndex - 1,
        );
    };

    const onMenuKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'Home') {
            event.preventDefault();
            focusOption(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            focusOption(options.length - 1);
        }
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            if (
                wrapperRef.current
                && !wrapperRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div ref={wrapperRef} className="modpackinstaller-dropdown">
            <label id={`${id}-label`} htmlFor={id}>
                {label}
            </label>

            <button
                id={id}
                type="button"
                className="modpackinstaller-dropdown-trigger"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-labelledby={`${id}-label ${id}`}
                disabled={disabled || options.length === 0}
                onClick={() => setOpen((current) => !current)}
                onKeyDown={onTriggerKeyDown}
            >
                <span className="modpackinstaller-dropdown-value">
                    {selected ? selected.label : 'Any'}
                </span>

                <span
                    className="modpackinstaller-dropdown-caret"
                    aria-hidden="true"
                >
                    ▾
                </span>
            </button>

            {open && (
                <div
                    ref={menuRef}
                    className="modpackinstaller-dropdown-menu"
                    role="listbox"
                    aria-labelledby={`${id}-label`}
                    onKeyDown={onMenuKeyDown}
                >
                    {options.map((option) => (
                        <button
                            type="button"
                            key={option.value}
                            role="option"
                            aria-selected={option.value === value}
                            className={`modpackinstaller-dropdown-option${
                                option.value === value
                                    ? ' modpackinstaller-dropdown-option--active'
                                    : ''
                            }`}
                            disabled={option.disabled}
                            onClick={() => {
                                onChange(option.value);
                                setOpen(false);
                            }}
                        >
                            <span>{option.label}</span>

                            {option.detail && (
                                <small>{option.detail}</small>
                            )}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

const Modal = ({
    open,
    labelledBy,
    title,
    onClose,
    headerActions,
    children,
    busy,
}: {
    open: boolean;
    labelledBy: string;
    title: string;
    onClose: () => void;
    headerActions?: React.ReactNode;
    children: React.ReactNode;
    busy?: boolean;
}) => {
    const [shown, setShown] = useState(open);

    const [phase, setPhase] = useState<'in' | 'out'>('in');

    const closeRef = useRef<HTMLButtonElement | null>(null);

    const previousFocus = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (open) {
            setShown(true);
            setPhase('in');
        } else if (shown) {
            setPhase('out');

            const timer = window.setTimeout(() => {
                setShown(false);
            }, 200);

            return () => window.clearTimeout(timer);
        }
    }, [open, shown]);

    useEffect(() => {
        if (!shown || phase !== 'in') {
            return;
        }

        previousFocus.current =
            document.activeElement as HTMLElement | null;

        const timer = window.setTimeout(() => {
            closeRef.current?.focus();
        }, 30);

        return () => window.clearTimeout(timer);
    }, [open, shown, phase]);

    useEffect(() => {
        if (!open) {
            previousFocus.current?.focus();
        }
    }, [open]);

    useEffect(() => {
        if (!open || !shown) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = prevOverflow;
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open, shown, onClose]);

    if (!shown) {
        return null;
    }

    return (
        <div
            className={`modpackinstaller-modal-overlay${
                phase === 'out'
                    ? ' modpackinstaller-modal-overlay--out'
                    : ''
            }`}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div
                className={`modpackinstaller-modal${
                    phase === 'out'
                        ? ' modpackinstaller-modal--out'
                        : ''
                }`}
                role="dialog"
                aria-modal="true"
                aria-labelledby={labelledBy}
                aria-busy={busy}
            >
                <div className="modpackinstaller-modal-header">
                    <h3 id={labelledBy}>{title}</h3>

                    <div className="modpackinstaller-modal-header-actions">
                        {headerActions}

                        <button
                            type="button"
                            ref={closeRef}
                            className="modpackinstaller-modal-close"
                            aria-label="Close"
                            onClick={onClose}
                        >
                            &times;
                        </button>
                    </div>
                </div>

                {children}
            </div>
        </div>
    );
};

const ManualDownloadNotice = ({
    manual,
}: {
    manual: ManualDownloadInfo;
}) => {
    return (
        <div
            className="modpackinstaller-manual-download"
            role="alert"
        >
            <h4>Manual download required</h4>

            <p>{manual.reason}</p>

            <div className="modpackinstaller-details">
                <div>
                    <span>Provider</span>
                    <strong>{manual.provider}</strong>
                </div>

                <div>
                    <span>Project</span>
                    <strong>{manual.project_name}</strong>
                </div>

                <div>
                    <span>File</span>
                    <strong>{manual.file_name}</strong>
                </div>

                <div>
                    <span>Version</span>
                    <strong>{manual.version}</strong>
                </div>
            </div>

            {manual.download_url && (
                <p className="modpackinstaller-manual-download-link">
                    <a
                        href={manual.download_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open official download
                    </a>
                </p>
            )}

            {manual.project_url && (
                <p className="modpackinstaller-manual-download-link">
                    <a
                        href={manual.project_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        View project on CurseForge
                    </a>
                </p>
            )}
        </div>
    );
};

const ModpackIcon = ({
    item,
    compact,
}: {
    item: CatalogItem;
    compact?: boolean;
}) => {
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
                className={`modpackinstaller-card-image modpackinstaller-card-image--fallback${
                    compact
                        ? ' modpackinstaller-card-image--compact'
                        : ''
                }`}
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
            className={`modpackinstaller-card-image${
                compact
                    ? ' modpackinstaller-card-image--compact'
                    : ''
            }`}
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

const PaginationBar = ({
    pagination,
    searching,
    onPage,
    variant,
}: {
    pagination: CatalogPagination | null;
    searching: boolean;
    onPage: (page: number) => void;
    variant: 'top' | 'bottom';
}) => {
    if (!pagination) {
        return null;
    }

    const page = pagination.page;

    return (
        <nav
            className={`modpackinstaller-pagination modpackinstaller-pagination--${variant}`}
            aria-label="Catalog pages"
            aria-busy={searching}
        >
            <span className="modpackinstaller-pagination-info">
                {pagination.total}{' '}
                {pagination.total === 1 ? 'result' : 'results'} · Page{' '}
                {page} of {pagination.total_pages}
            </span>

            <div className="modpackinstaller-pagination-actions">
                <button
                    type="button"
                    onClick={() => onPage(page - 1)}
                    disabled={!pagination.has_previous || searching}
                >
                    Previous
                </button>

                <button
                    type="button"
                    onClick={() => onPage(page + 1)}
                    disabled={!pagination.has_next || searching}
                >
                    Next
                </button>
            </div>
        </nav>
    );
};

const CatalogCard = ({
    item,
    view,
    onOpen,
    disabled,
}: {
    item: CatalogItem;
    view: 'grid' | 'list';
    onOpen: (item: CatalogItem) => void;
    disabled: boolean;
}) => {
    return (
        <article
            className={`modpackinstaller-catalog-card modpackinstaller-catalog-card--${view}`}
        >
            <ModpackIcon item={item} compact={view === 'list'} />

            <div className="modpackinstaller-catalog-card-content">
                <div className="modpackinstaller-catalog-card-header">
                    <span className="modpackinstaller-catalog-card-badge">
                        {item.provider}
                    </span>

                    <h4>{item.name}</h4>
                </div>

                <p className="modpackinstaller-catalog-card-summary">
                    {item.summary || 'No description available.'}
                </p>

                <dl className="modpackinstaller-catalog-card-meta">
                    {item.categories.length > 0 && (
                        <div>
                            <dt>Category</dt>
                            <dd>
                                {item.categories
                                    .slice(0, 3)
                                    .join(' · ')}
                            </dd>
                        </div>
                    )}

                    {item.loaders.length > 0 && (
                        <div>
                            <dt>Loader</dt>
                            <dd>{item.loaders.join(' · ')}</dd>
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
                            <dd>{formatCount(item.downloads)}</dd>
                        </div>
                    )}

                    {item.follows !== null && (
                        <div>
                            <dt>Follows</dt>
                            <dd>{formatCount(item.follows)}</dd>
                        </div>
                    )}

                    {item.latest_version && (
                        <div>
                            <dt>Latest</dt>
                            <dd>{item.latest_version}</dd>
                        </div>
                    )}
                </dl>

                <div className="modpackinstaller-catalog-card-actions">
                    <button
                        type="button"
                        onClick={() => onOpen(item)}
                        disabled={disabled}
                    >
                        View details
                    </button>

                    {item.project_url && (
                        <a
                            href={item.project_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerPolicy="no-referrer"
                        >
                            Open page
                        </a>
                    )}
                </div>
            </div>
        </article>
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

    const [providers, setProviders] =
        useState<CatalogProviderOption[] | null>(null);

    const [providersError, setProvidersError] =
        useState<string | null>(null);

    const [filters, setFilters] = useState<CatalogFilters>({
        provider: DEFAULT_PROVIDER,
        query: '',
        gameVersions: [],
        loaders: [],
        categories: [],
        environment: '',
        sort: 'relevance',
        page: 1,
    });

    const filtersRef = useRef(filters);

    filtersRef.current = filters;

    const [items, setItems] = useState<CatalogItem[] | null>(null);

    const [pagination, setPagination] =
        useState<CatalogPagination | null>(null);

    const [searching, setSearching] = useState(false);

    const [catalogError, setCatalogError] =
        useState<string | null>(null);

    const [view, setView] = useState<'grid' | 'list'>(() => {
        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'list'
            ? 'list'
            : 'grid';
    });

    const [filtersOpen, setFiltersOpen] = useState(false);

    const [installed, setInstalled] =
        useState<InstallRecordData[] | null>(null);

    const [installedLoading, setInstalledLoading] = useState(false);

    const [installedError, setInstalledError] =
        useState<string | null>(null);

    const [installedStatus, setInstalledStatus] =
        useState<StatusMessage | null>(null);

    const [lifecycleRecordId, setLifecycleRecordId] =
        useState<string | null>(null);

    const [armedUninstall, setArmedUninstall] =
        useState<string | null>(null);

    const [installedOpen, setInstalledOpen] = useState(false);

    const [detailsItem, setDetailsItem] =
        useState<CatalogItem | null>(null);

    const [modalVersions, setModalVersions] =
        useState<CatalogVersion[] | null>(null);

    const [modalVersionsLoading, setModalVersionsLoading] =
        useState(false);

    const [modalVersionsError, setModalVersionsError] =
        useState<string | null>(null);

    const [modalVersionSource, setModalVersionSource] =
        useState<string | null>(null);

    const [modalMetadata, setModalMetadata] =
        useState<ModpackMetadata | null>(null);

    const [modalMetadataLoading, setModalMetadataLoading] =
        useState(false);

    const [modalMetadataError, setModalMetadataError] =
        useState<string | null>(null);

    const [modalStatus, setModalStatus] =
        useState<StatusMessage | null>(null);

    const [modalResult, setModalResult] =
        useState<InstallationResult | null>(null);

    const [modalInstallLoading, setModalInstallLoading] =
        useState(false);

    const versionsRequestId = useRef(0);

    const metadataRequestId = useRef(0);

    const [source, setSource] = useState('mock://example-pack');

    const [metadata, setMetadata] =
        useState<ModpackMetadata | null>(null);

    const [selectedSource, setSelectedSource] =
        useState<string | null>(null);

    const [loading, setLoading] = useState(false);

    const [installLoading, setInstallLoading] = useState(false);

    const [result, setResult] =
        useState<InstallationResult | null>(null);

    const [status, setStatus] =
        useState<StatusMessage | null>(null);

    const processing =
        loading || installLoading || modalInstallLoading;

    const modalBusy =
        modalVersionsLoading ||
        modalMetadataLoading ||
        modalInstallLoading;

    const visibleProviders = (providers ?? []).filter(
        (provider) => !provider.development_only,
    );

    const activeProvider =
        providers?.find(
            (provider) => provider.name === filters.provider,
        ) ?? null;

    const facets = activeProvider?.facets ?? {
        game_versions: [] as string[],
        loaders: [] as string[],
        categories: [] as string[],
        environments: [] as string[],
    };

    const capabilities = activeProvider?.capabilities ?? null;

    const providerOptions = visibleProviders.map((provider) => ({
        value: provider.name,
        label: provider.available
            ? provider.label
            : `${provider.label} (not available)`,
        detail: provider.available
            ? undefined
            : provider.unavailable_reason ?? undefined,
        disabled: !provider.available,
    }));

    const catalogBusy =
        searching || visibleProviders.length === 0;

    const activeFilterCount =
        filters.gameVersions.length +
        filters.loaders.length +
        filters.categories.length +
        (filters.environment !== '' ? 1 : 0);

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

        if (next.gameVersions.length > 0) {
            params.game_versions = next.gameVersions.join(',');
        }

        if (next.loaders.length > 0) {
            params.loaders = next.loaders.join(',');
        }

        if (next.categories.length > 0) {
            params.categories = next.categories.join(',');
        }

        if (next.environment !== '') {
            params.environments = next.environment;
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

            setItems(response.data.data.items);
            setPagination(response.data.data.pagination);
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

                setProviders(response.data.data.providers);

                const available = response.data.data.providers.filter(
                    (provider) =>
                        provider.available
                        && !provider.development_only,
                );

                const defaultAvailable = available.some(
                    (provider) =>
                        provider.name
                        === response.data.data.default_provider,
                );

                if (defaultAvailable) {
                    chosenProvider =
                        response.data.data.default_provider;
                } else if (available.length > 0) {
                    chosenProvider = available[0].name;
                }

                setFilters((current) => ({
                    ...current,
                    provider: chosenProvider,
                }));
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

    const loadInstalled = async () => {
        if (!server) {
            setInstalled([]);
            setInstalledError(null);
            return;
        }

        setInstalledLoading(true);
        setInstalledError(null);

        try {
            const response =
                await axios.get<InstalledModpacksResponse>(
                    `${API_BASE}/servers/${server}/installed`,
                );

            if (!alive.current) {
                return;
            }

            setInstalled(response.data.data);
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            setInstalled([]);
            setInstalledError(
                requestError.response?.data?.error ||
                'Unable to load the installed modpacks.',
            );
        } finally {
            if (alive.current) {
                setInstalledLoading(false);
            }
        }
    };

    useEffect(() => {
        loadInstalled();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [server]);

    const refreshInstalled = () => {
        loadInstalled();
        setArmedUninstall(null);
    };

    const openInstalledModal = () => {
        setInstalledOpen(true);
        loadInstalled();
    };

    const updateInstalledModpack = async (
        record: InstallRecordData,
    ) => {
        if (!server) {
            setInstalledStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        if (lifecycleRecordId !== null) {
            return;
        }

        setLifecycleRecordId(record.id);
        setInstalledStatus(null);

        try {
            const response =
                await axios.post<UpdateResponse>(
                    `${API_BASE}/servers/${server}/installed/${record.id}/update`,
                );

            if (!alive.current) {
                return;
            }

            setInstalledStatus({
                kind: 'success',
                message: `Updated ${response.data.data.display_name} from ${response.data.data.previous_version} to ${response.data.data.version}.`,
            });
            refreshInstalled();
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to update the modpack.';

            setInstalledStatus({
                kind: 'error',
                message:
                    requestError.response?.data?.manual_download
                        ? 'This modpack requires a manual download to update.'
                        : message,
            });
        } finally {
            if (alive.current) {
                setLifecycleRecordId(null);
            }
        }
    };

    const uninstallInstalledModpack = async (
        record: InstallRecordData,
    ) => {
        if (!server) {
            setInstalledStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        if (lifecycleRecordId !== null) {
            return;
        }

        setLifecycleRecordId(record.id);
        setInstalledStatus(null);

        try {
            const response =
                await axios.post<UninstallResponse>(
                    `${API_BASE}/servers/${server}/installed/${record.id}/uninstall`,
                );

            if (!alive.current) {
                return;
            }

            setInstalledStatus({
                kind: 'success',
                message: `Uninstalled ${response.data.data.display_name} (${response.data.data.removed} files removed).`,
            });
            refreshInstalled();
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            setInstalledStatus({
                kind: 'error',
                message:
                    requestError.response?.data?.error ||
                    'Unable to uninstall the modpack.',
            });
        } finally {
            if (alive.current) {
                setLifecycleRecordId(null);
            }
        }
    };

    const applyFilters = (patch: Partial<CatalogFilters>) => {
        if (debounceTimer.current !== null) {
            window.clearTimeout(debounceTimer.current);
            debounceTimer.current = null;
        }

        const next = {
            ...filtersRef.current,
            ...patch,
            page: 1,
        };

        setFilters(next);
        runSearch(next);
    };

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

    const clearQuery = () => {
        if (debounceTimer.current !== null) {
            window.clearTimeout(debounceTimer.current);
            debounceTimer.current = null;
        }

        setFilters((current) => ({ ...current, query: '' }));
        runSearch({ ...filtersRef.current, query: '', page: 1 });
    };

    const changeProvider = (provider: string) => {
        if (provider === filtersRef.current.provider) {
            return;
        }

        applyFilters({
            provider,
            gameVersions: [],
            loaders: [],
            categories: [],
            environment: '',
        });
    };

    const changeSort = (sort: string) => {
        applyFilters({ sort });
    };

    const toggleListValue = (
        key: MultiFilterKey,
        value: string,
    ) => {
        const current = filtersRef.current[key];

        const next = current.includes(value)
            ? current.filter((item) => item !== value)
            : [...current, value];

        applyFilters({ [key]: next } as Partial<CatalogFilters>);
    };

    const toggleEnvironment = (value: string) => {
        const next =
            filtersRef.current.environment === value ? '' : value;

        applyFilters({ environment: next });
    };

    const resetFilters = () => {
        applyFilters({
            gameVersions: [],
            loaders: [],
            categories: [],
            environment: '',
        });
    };

    const goToPage = (page: number) => {
        if (page < 1) {
            return;
        }

        const next = {
            ...filtersRef.current,
            page,
        };

        setFilters(next);
        runSearch(next);
    };

    const changeView = (next: 'grid' | 'list') => {
        setView(next);

        try {
            window.localStorage.setItem(VIEW_STORAGE_KEY, next);
        } catch {
            // storage unavailable; the in-memory view still applies
        }
    };

    const openDetailsModal = (item: CatalogItem) => {
        if (processing) {
            return;
        }

        versionsRequestId.current++;
        metadataRequestId.current++;

        setDetailsItem(item);
        setModalVersions(null);
        setModalVersionsError(null);
        setModalVersionsLoading(false);
        setModalVersionSource(null);
        setModalMetadata(null);
        setModalMetadataLoading(false);
        setModalMetadataError(null);
        setModalStatus(null);
        setModalResult(null);
        setModalInstallLoading(false);
    };

    const closeDetailsModal = () => {
        versionsRequestId.current++;
        metadataRequestId.current++;

        setDetailsItem(null);
        setModalVersions(null);
        setModalVersionsError(null);
        setModalVersionsLoading(false);
        setModalMetadata(null);
        setModalMetadataLoading(false);
        setModalMetadataError(null);
        setModalVersionSource(null);
        setModalResult(null);
        setModalInstallLoading(false);
        setModalStatus(null);
    };

    const loadVersions = async () => {
        const item = detailsItem;

        if (!item) {
            return;
        }

        const id = ++versionsRequestId.current;

        setModalVersionsLoading(true);
        setModalVersions(null);
        setModalVersionsError(null);

        const params: Record<string, string> = {
            provider: item.provider,
            project: item.provider_project_id,
        };

        try {
            const response =
                await axios.get<CatalogVersionsResponse>(
                    `${API_BASE}/catalog/versions`,
                    { params },
                );

            if (
                !alive.current
                || id !== versionsRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            const versions = response.data.data.versions;

            setModalVersions(versions);

            if (versions.length === 1) {
                selectModalVersion(versions[0].source);
            } else if (
                modalVersionSource
                && versions.some(
                    (version) =>
                        version.source === modalVersionSource,
                )
            ) {
                // keep the current selection
            } else {
                clearModalSelection();
            }
        } catch (requestError: any) {
            if (
                !alive.current
                || id !== versionsRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            setModalVersionsError(
                requestError.response?.data?.error ||
                'Unable to load the modpack versions.',
            );

            clearModalSelection();
        } finally {
            if (
                alive.current
                && id === versionsRequestId.current
                && detailsItem !== null
            ) {
                setModalVersionsLoading(false);
            }
        }
    };

    const clearModalSelection = () => {
        setModalVersionSource(null);
        setModalMetadata(null);
        setModalMetadataError(null);
        setModalStatus(null);
        setModalResult(null);
    };

    const retryModalVersions = () => {
        loadVersions();
    };

    const selectModalVersion = (sourceValue: string) => {
        setModalVersionSource(sourceValue);
        setModalMetadata(null);
        setModalMetadataError(null);
        setModalStatus(null);
        setModalResult(null);

        fetchModalMetadata(sourceValue);
    };

    const fetchModalMetadata = async (
        targetSource: string,
    ) => {
        const trimmedSource = targetSource.trim();

        if (!trimmedSource) {
            setModalStatus({
                kind: 'error',
                message: 'The selected version has no installable source.',
            });
            return;
        }

        const id = ++metadataRequestId.current;

        setModalMetadataLoading(true);
        setModalMetadataError(null);
        setModalStatus(null);

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

            if (
                !alive.current
                || id !== metadataRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            setModalMetadata(response.data.data);
            setModalStatus({
                kind: 'info',
                message: `Resolved ${response.data.data.name} ${response.data.data.version}.`,
            });
        } catch (requestError: any) {
            if (
                !alive.current
                || id !== metadataRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to resolve the selected modpack version.';

            setModalMetadataError(message);
            setModalStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (
                alive.current
                && id === metadataRequestId.current
                && detailsItem !== null
            ) {
                setModalMetadataLoading(false);
            }
        }
    };

    const retryModalMetadata = () => {
        if (modalVersionSource) {
            fetchModalMetadata(modalVersionSource);
        }
    };

    const installModalModpack = async () => {
        if (!server) {
            setModalStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        if (!modalVersionSource) {
            setModalStatus({
                kind: 'error',
                message: 'Select a modpack version before installing.',
            });
            return;
        }

        if (!modalMetadata) {
            setModalStatus({
                kind: 'error',
                message: 'Resolve the modpack version before installing.',
            });
            return;
        }

        if (modalInstallLoading) {
            return;
        }

        setModalInstallLoading(true);
        setModalStatus(null);
        setModalResult(null);

        try {
            const response =
                await axios.post<InstallResponse>(
                    `${API_BASE}/servers/${server}/install`,
                    {
                        source: modalVersionSource,
                    },
                );

            if (!alive.current || detailsItem === null) {
                return;
            }

            setModalResult(response.data.data);
            setModalStatus({
                kind: 'success',
                message: 'Installation complete.',
            });
            loadInstalled();
        } catch (requestError: any) {
            if (!alive.current || detailsItem === null) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to install the modpack.';

            setModalStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current && detailsItem !== null) {
                setModalInstallLoading(false);
            }
        }
    };

    useEffect(() => {
        if (detailsItem === null) {
            return;
        }

        loadVersions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [detailsItem]);

    const updateSource = (value: string) => {
        setSource(value);
        setResult(null);
        setStatus(null);

        if (value.trim() !== selectedSource) {
            setMetadata(null);
            setSelectedSource(null);
        }
    };

    const loadManualSource = async () => {
        const trimmedSource = source.trim();

        if (!trimmedSource) {
            setStatus({
                kind: 'error',
                message: 'Please enter a modpack source.',
            });
            setMetadata(null);
            setSelectedSource(null);
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

    const installManualSource = async () => {
        if (!server) {
            setStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        if (!selectedSource) {
            setStatus({
                kind: 'error',
                message: 'Load a modpack before installing.',
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
                    },
                );

            if (!alive.current) {
                return;
            }

            setResult(response.data.data);
            setStatus({
                kind: 'success',
                message: 'Installation complete.',
            });
            loadInstalled();
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

    const activeChips: Array<{
        key: string;
        label: string;
        onRemove: () => void;
    }> = [];

    filters.categories.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        activeChips.push({
            key: `category:${value}`,
            label: `Category: ${value}`,
            onRemove: () => toggleListValue('categories', value),
        });
    });

    filters.gameVersions.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        activeChips.push({
            key: `game_version:${value}`,
            label: `MC ${value}`,
            onRemove: () => toggleListValue('gameVersions', value),
        });
    });

    filters.loaders.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        activeChips.push({
            key: `loader:${value}`,
            label: `Loader: ${value}`,
            onRemove: () => toggleListValue('loaders', value),
        });
    });

    if (filters.environment !== '') {
        const environmentLabel =
            ENVIRONMENT_OPTIONS.find(
                (option) => option.value === filters.environment,
            )?.label ?? filters.environment;

        activeChips.push({
            key: `environment:${filters.environment}`,
            label: `Environment: ${environmentLabel}`,
            onRemove: () => toggleEnvironment(filters.environment),
        });
    }

    return (
        <div
            className="modpackinstaller-root"
            aria-busy={processing || searching}
        >
            <header className="modpackinstaller-page-header">
                <div>
                    <h2>Modpack Installer</h2>

                    <p>
                        Browse, search, and install a modpack directly
                        onto this server.
                    </p>
                </div>

                <button
                    type="button"
                    className="modpackinstaller-installed-button"
                    onClick={openInstalledModal}
                >
                    Installed modpacks
                    {installed !== null && installed.length > 0
                        ? ` (${installed.length})`
                        : ''}
                </button>
            </header>

            <div className="modpackinstaller-card">
                <div className="modpackinstaller-browser-toolbar">
                    <div className="modpackinstaller-search">
                        <label htmlFor="modpackinstaller-search">
                            Search
                        </label>

                        <div className="modpackinstaller-search-row">
                            <input
                                id="modpackinstaller-search"
                                type="search"
                                value={filters.query}
                                onChange={(event) =>
                                    onQueryChange(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        submitQuery();
                                    }
                                }}
                                placeholder="Search modpacks"
                                disabled={catalogBusy}
                                aria-label="Search modpacks"
                            />

                            {filters.query.trim() !== '' && (
                                <button
                                    type="button"
                                    className="modpackinstaller-search-clear"
                                    onClick={clearQuery}
                                    aria-label="Clear search"
                                    disabled={catalogBusy}
                                >
                                    &times;
                                </button>
                            )}

                            <button
                                type="button"
                                onClick={submitQuery}
                                disabled={catalogBusy}
                            >
                                {searching ? 'Searching ...' : 'Search'}
                            </button>
                        </div>
                    </div>

                    <div className="modpackinstaller-toolbar-row">
                        <Dropdown
                            id="modpackinstaller-provider"
                            label="Provider"
                            value={filters.provider}
                            onChange={changeProvider}
                            options={providerOptions}
                            disabled={catalogBusy}
                        />

                        <Dropdown
                            id="modpackinstaller-sort"
                            label="Sort"
                            value={filters.sort}
                            onChange={changeSort}
                            options={SORT_OPTIONS}
                            disabled={catalogBusy}
                        />

                        <button
                            type="button"
                            className="modpackinstaller-filters-toggle"
                            aria-expanded={filtersOpen}
                            onClick={() =>
                                setFiltersOpen((current) => !current)
                            }
                            disabled={catalogBusy}
                        >
                            Filters
                            {activeFilterCount > 0
                                ? ` (${activeFilterCount})`
                                : ''}
                        </button>

                        <div
                            className="modpackinstaller-view-toggle"
                            role="group"
                            aria-label="Result view"
                        >
                            <button
                                type="button"
                                aria-pressed={view === 'grid'}
                                className={
                                    view === 'grid'
                                        ? 'modpackinstaller-view-toggle--active'
                                        : undefined
                                }
                                onClick={() => changeView('grid')}
                            >
                                Grid
                            </button>

                            <button
                                type="button"
                                aria-pressed={view === 'list'}
                                className={
                                    view === 'list'
                                        ? 'modpackinstaller-view-toggle--active'
                                        : undefined
                                }
                                onClick={() => changeView('list')}
                            >
                                List
                            </button>
                        </div>
                    </div>
                </div>

                {filtersOpen && (
                    <div className="modpackinstaller-filter-panel">
                        <div className="modpackinstaller-filter-groups">
                            {capabilities?.categories !== false
                                && facets.categories.length > 0 && (
                                    <fieldset className="modpackinstaller-filter-group">
                                        <legend>
                                            Categories
                                        </legend>

                                        <div className="modpackinstaller-filter-options">
                                            {facets.categories.map(
                                                (value) => (
                                                    <label
                                                        className="modpackinstaller-filter-option"
                                                        key={value}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={filters.categories.includes(
                                                                value,
                                                            )}
                                                            onChange={() =>
                                                                toggleListValue(
                                                                    'categories',
                                                                    value,
                                                                )
                                                            }
                                                        />

                                                        <span>
                                                            {value}
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    </fieldset>
                                )}

                            {capabilities?.game_versions !== false
                                && facets.game_versions.length > 0 && (
                                    <fieldset className="modpackinstaller-filter-group">
                                        <legend>
                                            Game versions
                                        </legend>

                                        <div className="modpackinstaller-filter-options">
                                            {facets.game_versions.map(
                                                (value) => (
                                                    <label
                                                        className="modpackinstaller-filter-option"
                                                        key={value}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={filters.gameVersions.includes(
                                                                value,
                                                            )}
                                                            onChange={() =>
                                                                toggleListValue(
                                                                    'gameVersions',
                                                                    value,
                                                                )
                                                            }
                                                        />

                                                        <span>
                                                            {value}
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    </fieldset>
                                )}

                            {capabilities?.loaders !== false
                                && facets.loaders.length > 0 && (
                                    <fieldset className="modpackinstaller-filter-group">
                                        <legend>
                                            Loaders
                                        </legend>

                                        <div className="modpackinstaller-filter-options">
                                            {facets.loaders.map(
                                                (value) => (
                                                    <label
                                                        className="modpackinstaller-filter-option"
                                                        key={value}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={filters.loaders.includes(
                                                                value,
                                                            )}
                                                            onChange={() =>
                                                                toggleListValue(
                                                                    'loaders',
                                                                    value,
                                                                )
                                                            }
                                                        />

                                                        <span>
                                                            {value}
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    </fieldset>
                                )}

                            {capabilities?.environment !== false
                                && facets.environments.length > 0 && (
                                    <fieldset className="modpackinstaller-filter-group">
                                        <legend>
                                            Environment
                                        </legend>

                                        <div className="modpackinstaller-filter-options">
                                            {ENVIRONMENT_OPTIONS.map(
                                                (option) => (
                                                    <label
                                                        className="modpackinstaller-filter-option"
                                                        key={option.value}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                filters.environment
                                                                === option.value
                                                            }
                                                            onChange={() =>
                                                                toggleEnvironment(
                                                                    option.value,
                                                                )
                                                            }
                                                        />

                                                        <span>
                                                            {option.label}
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    </fieldset>
                                )}
                        </div>

                        <div className="modpackinstaller-filter-actions">
                            <button
                                type="button"
                                onClick={() => setFiltersOpen(false)}
                            >
                                Done
                            </button>

                            <button
                                type="button"
                                onClick={resetFilters}
                                disabled={
                                    activeFilterCount === 0
                                    || searching
                                }
                            >
                                Clear filters
                            </button>
                        </div>
                    </div>
                )}

                {activeFilterCount > 0 && (
                    <div className="modpackinstaller-active-filters">
                        {activeChips.map((chip) => (
                            <span
                                className="modpackinstaller-chip"
                                key={chip.key}
                            >
                                {chip.label}

                                <button
                                    type="button"
                                    aria-label={`Remove ${chip.label}`}
                                    onClick={chip.onRemove}
                                    disabled={searching}
                                >
                                    &times;
                                </button>
                            </span>
                        ))}
                    </div>
                )}

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
                                Try broadening the filters.
                            </p>
                        </div>
                    )}

                {!catalogError
                    && items !== null
                    && items.length > 0 && (
                        <>
                            <PaginationBar
                                pagination={pagination}
                                searching={searching}
                                onPage={goToPage}
                                variant="top"
                            />

                            <div
                                className={`modpackinstaller-catalog-${view}`}
                                aria-busy={searching}
                            >
                                {items.map((item) => (
                                    <CatalogCard
                                        key={`${item.provider}:${item.provider_project_id}`}
                                        item={item}
                                        view={view}
                                        onOpen={openDetailsModal}
                                        disabled={processing || searching}
                                    />
                                ))}
                            </div>

                            <PaginationBar
                                pagination={pagination}
                                searching={searching}
                                onPage={goToPage}
                                variant="bottom"
                            />
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
                            disabled={loading}
                        >
                            {loading ? 'Loading ...' : 'Load'}
                        </button>

                        <p className="modpackinstaller-source-hint">
                            Sources: modrinth://project-slug ·
                            curseforge://project-id · mock://example-pack.
                            Append @version-id to pin an exact modpack
                            version.
                        </p>
                    </div>
                </details>
            </div>

            {status && (
                <div
                    className={`modpackinstaller-status modpackinstaller-status--${status.kind}`}
                    role={
                        status.kind === 'error' ? 'alert' : 'status'
                    }
                >
                    {status.message}
                </div>
            )}

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
                                    <p>{metadata.description}</p>
                                )}

                                <div className="modpackinstaller-selected">
                                    Selected modpack
                                </div>
                            </div>
                        </div>

                        <div className="modpackinstaller-details">
                            <div>
                                <span>Version</span>
                                <strong>{metadata.version}</strong>
                            </div>

                            <div>
                                <span>Minecraft</span>
                                <strong>
                                    {metadata.minecraft_version}
                                </strong>
                            </div>

                            <div>
                                <span>Loader</span>
                                <strong>{metadata.loader}</strong>
                            </div>
                        </div>

                        <div className="modpackinstaller-source">
                            <span>Source</span>
                            <code>{metadata.source}</code>
                        </div>

                        {metadata.manual_download ? (
                            <ManualDownloadNotice
                                manual={metadata.manual_download}
                            />
                        ) : (
                            <div className="modpackinstaller-modal-actions modpackinstaller-install-actions">
                                <button
                                    type="button"
                                    onClick={installManualSource}
                                    disabled={installLoading || result !== null}
                                >
                                    {installLoading
                                        ? 'Installing ...'
                                        : 'Install this modpack'}
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {result && (
                <div className="modpackinstaller-card">
                    <h3>Installation complete</h3>

                    <div className="modpackinstaller-result-grid">
                        <div>
                            <span>Total files</span>
                            <strong>{result.total_files}</strong>
                        </div>

                        <div>
                            <span>Created</span>
                            <strong>{result.created}</strong>
                        </div>

                        <div>
                            <span>Overwritten</span>
                            <strong>{result.overwritten}</strong>
                        </div>

                        <div>
                            <span>Backups</span>
                            <strong>{result.backed_up}</strong>
                        </div>
                    </div>
                </div>
            )}

            <Modal
                open={installedOpen}
                onClose={() => setInstalledOpen(false)}
                labelledBy="modpackinstaller-installed-title"
                title="Installed modpacks"
                busy={installedLoading}
                headerActions={
                    <button
                        type="button"
                        onClick={refreshInstalled}
                        disabled={installedLoading}
                    >
                        {installedLoading
                            ? 'Refreshing ...'
                            : 'Refresh'}
                    </button>
                }
            >
                {installedStatus && (
                    <div
                        className={`modpackinstaller-status modpackinstaller-status--${installedStatus.kind}`}
                        role={
                            installedStatus.kind === 'error'
                                ? 'alert'
                                : 'status'
                        }
                    >
                        {installedStatus.message}
                    </div>
                )}

                {installed === null && installedLoading && (
                    <div
                        className="modpackinstaller-catalog-state"
                        role="status"
                    >
                        Loading installed modpacks ...
                    </div>
                )}

                {installed !== null && installedError && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                        <p role="alert">{installedError}</p>

                        <button
                            type="button"
                            onClick={refreshInstalled}
                        >
                            Retry
                        </button>
                    </div>
                )}

                {installed !== null
                    && !installedError
                    && installed.length === 0
                    && !installedLoading && (
                        <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                            <p>
                                No modpacks are installed on this server
                                yet.
                            </p>
                        </div>
                    )}

                {installed !== null
                    && !installedError
                    && installed.length > 0 && (
                        <div className="modpackinstaller-installed-list">
                            {installed.map((record) => (
                                <article
                                    className="modpackinstaller-installed-item"
                                    key={record.id}
                                >
                                    <div className="modpackinstaller-installed-header">
                                        <span className="modpackinstaller-catalog-card-badge">
                                            {record.provider}
                                        </span>

                                        <h4>{record.display_name}</h4>
                                    </div>

                                    <div className="modpackinstaller-details">
                                        <div>
                                            <span>Version</span>
                                            <strong>
                                                {record.version}
                                            </strong>
                                        </div>

                                        {record.minecraft_version && (
                                            <div>
                                                <span>Minecraft</span>
                                                <strong>
                                                    {record.minecraft_version}
                                                </strong>
                                            </div>
                                        )}

                                        {record.loader && (
                                            <div>
                                                <span>Loader</span>
                                                <strong>
                                                    {record.loader}
                                                </strong>
                                            </div>
                                        )}
                                    </div>

                                    <div className="modpackinstaller-installed-source">
                                        <span>Source</span>
                                        <code>{record.source}</code>
                                    </div>

                                    <p className="modpackinstaller-installed-meta">
                                        Installed{' '}
                                        {formatDate(record.installed_at)}
                                        {' '}· Updated{' '}
                                        {formatDate(record.updated_at)}
                                    </p>

                                    <div className="modpackinstaller-installed-actions">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                updateInstalledModpack(
                                                    record,
                                                )
                                            }
                                            disabled={
                                                lifecycleRecordId !== null
                                            }
                                        >
                                            {lifecycleRecordId
                                                === record.id
                                                ? 'Working ...'
                                                : 'Update to latest'}
                                        </button>

                                        {armedUninstall === record.id ? (
                                            <>
                                                <button
                                                    type="button"
                                                    className="modpackinstaller-danger-armed"
                                                    onClick={() =>
                                                        uninstallInstalledModpack(
                                                            record,
                                                        )
                                                    }
                                                    disabled={
                                                        lifecycleRecordId
                                                        !== null
                                                    }
                                                >
                                                    {lifecycleRecordId
                                                        === record.id
                                                        ? 'Removing ...'
                                                        : 'Confirm uninstall'}
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setArmedUninstall(
                                                            null,
                                                        )
                                                    }
                                                    disabled={
                                                        lifecycleRecordId
                                                        !== null
                                                    }
                                                >
                                                    Cancel
                                                </button>
                                            </>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setArmedUninstall(
                                                        record.id,
                                                    )
                                                }
                                                disabled={
                                                    lifecycleRecordId
                                                    !== null
                                                }
                                            >
                                                Uninstall
                                            </button>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
            </Modal>

            <Modal
                open={detailsItem !== null}
                onClose={closeDetailsModal}
                labelledBy="modpackinstaller-details-title"
                title={detailsItem?.name ?? 'Modpack details'}
                busy={modalBusy}
            >
                {modalStatus && (
                    <div
                        className={`modpackinstaller-status modpackinstaller-status--${modalStatus.kind}`}
                        role={
                            modalStatus.kind === 'error'
                                ? 'alert'
                                : 'status'
                        }
                    >
                        {modalStatus.message}
                    </div>
                )}

                {detailsItem && (
                    <div className="modpackinstaller-modal-body">
                        <div className="modpackinstaller-modal-item">
                            <ModpackIcon
                                item={detailsItem}
                                compact
                            />

                            <div className="modpackinstaller-modal-item-meta">
                                <p className="modpackinstaller-catalog-card-summary">
                                    {detailsItem.summary ||
                                        'No description available.'}
                                </p>

                                {detailsItem.categories.length > 0 && (
                                    <div className="modpackinstaller-modal-categories">
                                        {detailsItem.categories.map(
                                            (category) => (
                                                <span
                                                    className="modpackinstaller-chip"
                                                    key={category}
                                                >
                                                    {category}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                )}

                                <dl className="modpackinstaller-catalog-card-meta">
                                    {detailsItem.loaders.length > 0 && (
                                        <div>
                                            <dt>Loader</dt>
                                            <dd>
                                                {detailsItem.loaders.join(' · ')}
                                            </dd>
                                        </div>
                                    )}

                                    {detailsItem.game_versions.length > 0 && (
                                        <div>
                                            <dt>Minecraft</dt>
                                            <dd>
                                                {detailsItem.game_versions.length > 3
                                                    ? `${detailsItem.game_versions
                                                          .slice(0, 3)
                                                          .join(', ')} +`
                                                    : detailsItem.game_versions.join(', ')}
                                            </dd>
                                        </div>
                                    )}

                                    {detailsItem.downloads !== null && (
                                        <div>
                                            <dt>Downloads</dt>
                                            <dd>
                                                {formatCount(detailsItem.downloads)}
                                            </dd>
                                        </div>
                                    )}

                                    {detailsItem.follows !== null && (
                                        <div>
                                            <dt>Follows</dt>
                                            <dd>
                                                {formatCount(detailsItem.follows)}
                                            </dd>
                                        </div>
                                    )}

                                    {detailsItem.latest_version && (
                                        <div>
                                            <dt>Latest</dt>
                                            <dd>
                                                {detailsItem.latest_version}
                                            </dd>
                                        </div>
                                    )}
                                </dl>

                                {detailsItem.project_url && (
                                    <a
                                        href={detailsItem.project_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        referrerPolicy="no-referrer"
                                        className="modpackinstaller-modal-link"
                                    >
                                        View on{' '}
                                        {activeProvider?.label ??
                                            detailsItem.provider}
                                    </a>
                                )}
                            </div>
                        </div>

                        <div className="modpackinstaller-modal-versions">
                            <div className="modpackinstaller-modal-versions-heading">
                                <span className="modpackinstaller-modal-versions-title">
                                    Version
                                </span>

                                <span className="modpackinstaller-modal-versions-count">
                                    {modalVersionsLoading
                                        ? 'Loading versions ...'
                                        : modalVersions === null
                                            ? ''
                                            : modalVersions.length === 0
                                                ? 'No versions match the selected filters.'
                                                : `${modalVersions.length} version${modalVersions.length === 1 ? '' : 's'}`}
                                </span>
                            </div>

                            {modalVersionsLoading && (
                                <div
                                    className="modpackinstaller-catalog-state"
                                    role="status"
                                >
                                    Loading versions ...
                                </div>
                            )}

                            {!modalVersionsLoading
                                && modalVersionsError && (
                                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                                        <p role="alert">
                                            {modalVersionsError}
                                        </p>

                                        <button
                                            type="button"
                                            onClick={retryModalVersions}
                                        >
                                            Retry
                                        </button>
                                    </div>
                                )}

                            {!modalVersionsLoading
                                && !modalVersionsError
                                && modalVersions !== null
                                && modalVersions.length === 0 && (
                                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                                        <p>No versions are available for this
                                            modpack.</p>
                                    </div>
                                )}

                            {!modalVersionsLoading
                                && !modalVersionsError
                                && modalVersions !== null
                                && modalVersions.length > 0 && (
                                    <div
                                        className="modpackinstaller-version-options"
                                        role="radiogroup"
                                        aria-label="Version"
                                    >
                                        {modalVersions.map((version) => {
                                            const checked =
                                                modalVersionSource
                                                === version.source;

                                            return (
                                                <button
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={checked}
                                                    key={version.source}
                                                    className={`modpackinstaller-version-option${
                                                        checked
                                                            ? ' modpackinstaller-version-option--selected'
                                                            : ''
                                                    }`}
                                                    onClick={() =>
                                                        selectModalVersion(
                                                            version.source,
                                                        )
                                                    }
                                                >
                                                    <span className="modpackinstaller-version-option-name">
                                                        {versionLabel(
                                                            version,
                                                        )}
                                                    </span>

                                                    <span className="modpackinstaller-version-option-meta">
                                                        {version.date_published
                                                            ? `Published ${formatDate(version.date_published)}`
                                                            : 'Release date unavailable'}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                        </div>

                        {modalMetadataLoading && (
                            <div
                                className="modpackinstaller-catalog-state"
                                role="status"
                            >
                                Resolving the selected version ...
                            </div>
                        )}

                        {!modalMetadataLoading
                            && modalMetadataError && (
                                <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                                    <p role="alert">
                                        {modalMetadataError}
                                    </p>

                                    <button
                                        type="button"
                                        onClick={retryModalMetadata}
                                    >
                                        Retry
                                    </button>
                                </div>
                            )}

                        {modalMetadata && (
                            <div className="modpackinstaller-modal-selected">
                                <div className="modpackinstaller-details">
                                    <div>
                                        <span>Version</span>
                                        <strong>
                                            {modalMetadata.version}
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Minecraft</span>
                                        <strong>
                                            {modalMetadata.minecraft_version}
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Loader</span>
                                        <strong>
                                            {modalMetadata.loader}
                                        </strong>
                                    </div>
                                </div>

                                {modalMetadata.manual_download ? (
                                    <ManualDownloadNotice
                                        manual={
                                            modalMetadata.manual_download
                                        }
                                    />
                                ) : (
                                    <div className="modpackinstaller-modal-actions">
<button
                                    type="button"
                                    onClick={installModalModpack}
                                    disabled={
                                        !modalVersionSource
                                        || modalInstallLoading
                                        || modalResult !== null
                                    }
                                >
                                            {modalInstallLoading
                                                ? 'Installing ...'
                                                : 'Install Modpack'}
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}

                        {modalResult && (
                            <div className="modpackinstaller-modal-result">
                                <div className="modpackinstaller-result-grid">
                                    <div>
                                        <span>Total files</span>
                                        <strong>
                                            {modalResult.total_files}
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Created</span>
                                        <strong>
                                            {modalResult.created}
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Overwritten</span>
                                        <strong>
                                            {modalResult.overwritten}
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Backups</span>
                                        <strong>
                                            {modalResult.backed_up}
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </div>
    );
};