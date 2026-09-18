// ModpackInstaller.tsx — page entry component.

import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

import {
    API_BASE,
    CONTENT_TYPE_OPTIONS,
    CONTENT_TYPE_STORAGE_KEY,
    DEFAULT_CONTENT_TYPE,
    DEFAULT_PROVIDER,
    DEFAULT_STACK,
    EXTENSION_VERSION,
    PAGE_LIMIT,
    SORT_OPTIONS,
    STACK_OPTIONS,
    VIEW_STORAGE_KEY,
    activeInstallStorageKey,
    backendContentType,
    getServerIdentifier,
    isCatalogContentType,
    isSingleFileContentType,
    providerSupportedContentTypes,
} from './utils/constants';

import {
    ActiveInstallFile,
    ActiveInstallRecord,
    CatalogContentType,
    CatalogDescriptionData,
    CatalogDescriptionResponse,
    CatalogFilters,
    CatalogItem,
    CatalogPagination,
    CatalogProviderOption,
    CatalogResponse,
    CatalogVersion,
    CatalogVersionsResponse,
    InstallProgressData,
    InstallProgressResponse,
    InstallRecordData,
    InstallResponse,
    InstallationResult,
    InstalledModpacksResponse,
    MetadataResponse,
    ModpackMetadata,
    ModVersion,
    ModVersionsResponse,
    MultiFilterKey,
    ProvidersResponse,
    RestoreResponse,
    StatusMessage,
    UninstallResponse,
    UpdateResponse,
} from './types';

import { Modal } from './modals/Modal';
import { ManualDownloadNotice } from './modals/ManualDownloadNotice';
import { InstalledModpacksBody } from './modals/InstalledModpacksBody';
import { DetailsModalBody } from './modals/DetailsModalBody';
import { UninstallConfirmBody } from './modals/UninstallConfirmBody';
import { ReplaceConfirmBody } from './modals/ReplaceConfirmBody';
import { isActiveRunning } from './modals/ActiveInstallCard';

import { CatalogToolbar } from './toolbar/CatalogToolbar';
import { FilterPanel } from './toolbar/FilterPanel';
import { ActiveFilterChips } from './toolbar/ActiveFilterChips';

import { CatalogResults } from './cards/CatalogResults';
import {
    CurseForgeIcon,
    DiscordIcon,
    GitBranchIcon,
    GitHubIcon,
    ModrinthIcon,
    RefreshIcon,
    SpinnerIcon,
    WarningIcon,
} from './icons';

// How long a freshly-started install may report no backend progress before
// the polling loop treats it as abandoned. The install POST can take several
// seconds to reach and be processed by the server (mobile connections,
// replace-confirmation flow), so an 'idle' poll in this window just means
// the first progress snapshot has not landed yet — the card must stay alive.
const IDLE_GRACE_MS = 30000;

// How long a requested cancellation may stay in the 'cancelling' phase before
// the poll loop gives up on hearing back from the backend. The backend makes
// real cancellations land in seconds, so this only fires for a wedged install
// request and stops the card from spinning "Cancelling ..." indefinitely.
const CANCEL_STUCK_TIMEOUT_MS = 90000;

// How long the backend's progress snapshot may go without its CONTENT
// changing before the poll loop declares the install dead (e.g. the PHP
// request was killed by a timeout or a crash) and surfaces a failure instead
// of freezing on the last snapshot forever. Generous by design: the backend
// legitimately pauses while retrying slow transfers (up to several seconds
// of backoff per attempt, multiple candidates per mod), so only a truly
// frozen snapshot across this window counts as dead.
const PROGRESS_STALE_TIMEOUT_MS = 90000;

const snapshotKey = (state: InstallProgressData): string => {
    // The active step's own counters count as liveness: a counted stage (the
    // mods a manifest lists) can sit on the same overall percentage for a
    // while, and those updates must not read as a dead request.
    const activeStage = (state.stages ?? []).find(
        (stage) => stage.state === 'active',
    );

    return [
        state.phase,
        state.percent,
        state.downloaded_bytes ?? '',
        state.total_bytes ?? '',
        state.deployed_files ?? '',
        state.total_files ?? '',
        activeStage?.key ?? '',
        activeStage?.current ?? '',
    ].join('|');
};

export default () => {
    const server = getServerIdentifier();

    const alive = useRef(true);

    const requestId = useRef(0);

    const lastSnapshotKey = useRef<string | null>(null);

    const lastSnapshotChangeAt = useRef<number | null>(null);

    const debounceTimer = useRef<number | null>(null);

    const initialSearchRan = useRef(false);

    const cancellingSince = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            alive.current = false;

            if (debounceTimer.current !== null) {
                window.clearTimeout(debounceTimer.current);
            }

            if (activePollTimer.current !== null) {
                window.clearInterval(activePollTimer.current);
            }

            if (outcomeTimer.current !== null) {
                window.clearTimeout(outcomeTimer.current);
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
        stack: DEFAULT_STACK,
        page: 1,
    });

    // Selected toolbar content type (Modpacks / Mods / Plugins / ...).
    // Persisted so the user's last tab survives reloads; switching tabs
    // resets the search state so each type starts from a clean query.
    const [contentType, setContentType] = useState<CatalogContentType>(() => {
        try {
            const stored = window.localStorage.getItem(
                CONTENT_TYPE_STORAGE_KEY,
            );

            return isCatalogContentType(stored)
                ? stored
                : DEFAULT_CONTENT_TYPE;
        } catch {
            return DEFAULT_CONTENT_TYPE;
        }
    });

    // Backend-driven defaults (admin settings page): applied when the
    // providers endpoint responds, before the first search runs.
    const applyBackendDefaults = (
        data: ProvidersResponse['data'],
    ): { sort: string; stack: string } => {
        const sort =
            typeof data.default_sort === 'string' &&
            SORT_OPTIONS.some((option) => option.value === data.default_sort)
                ? data.default_sort
                : 'relevance';

        const limit = data.pagination?.default_limit;
        const stack =
            typeof limit === 'number' &&
            STACK_OPTIONS.some(
                (option) => option.value === String(limit),
            )
                ? String(limit)
                : DEFAULT_STACK;

        return { sort, stack };
    };

    const filtersRef = useRef(filters);

    filtersRef.current = filters;

    // Mirrors the contentType state for callbacks that must read the active
    // tab without re-creating on every keystroke (runSearch's closure).
    const contentTypeRef = useRef(contentType);

    contentTypeRef.current = contentType;

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

    const [pendingUninstall, setPendingUninstall] =
        useState<InstallRecordData | null>(null);

    const [replaceConfirmOpen, setReplaceConfirmOpen] = useState(false);

    const pendingReplaceContinuation =
        useRef<(() => void) | null>(null);

    const skipReplaceCheck = useRef(false);

    const hasInstalledModpack =
        installed !== null && installed.length > 0;

    const [installedOpen, setInstalledOpen] = useState(false);

    const [manualOpen, setManualOpen] = useState(false);

    const [detailsItem, setDetailsItem] =
        useState<CatalogItem | null>(null);

    const [modalVersions, setModalVersions] =
        useState<CatalogVersion[] | null>(null);

    const [modalVersionsLoading, setModalVersionsLoading] =
        useState(false);

    const [modalVersionsError, setModalVersionsError] =
        useState<string | null>(null);

    // Mod-specific version data (loader / game-version options per version
    // plus dependency recommendations), loaded instead of the modpack list
    // when the details modal opens for a mod.
    const [modVersions, setModVersions] =
        useState<ModVersion[] | null>(null);

    const [modVersionsLoading, setModVersionsLoading] =
        useState(false);

    const [modVersionsError, setModVersionsError] =
        useState<string | null>(null);

    // The two dropdown selections of the mod version window; the install
    // button only appears once both are chosen.
    const [modLoaderSelection, setModLoaderSelection] = useState('');

    const [modMcSelection, setModMcSelection] = useState('');

    // Project ids of recommended dependencies the user opted into with the
    // select toggle. Non-blocking: the install works with or without them.
    const [selectedDependencyIds, setSelectedDependencyIds] = useState<
        string[]
    >([]);

    // Per-dependency-project version lists, fetched once the loader + MC
    // pair is chosen so each recommendation card can show (and install) the
    // exact build that matches the selection. Keyed by Modrinth project id.
    const [dependencyVersions, setDependencyVersions] = useState<
        Record<string, ModVersion[] | null>
    >({});

    const dependencyVersionsRequestId = useRef(0);

    const [modalVersionSource, setModalVersionSource] =
        useState<string | null>(null);

    const [versionPickerOpen, setVersionPickerOpen] =
        useState(false);

    const [modalMetadata, setModalMetadata] =
        useState<ModpackMetadata | null>(null);

    const [modalMetadataLoading, setModalMetadataLoading] =
        useState(false);

    const [modalMetadataError, setModalMetadataError] =
        useState<string | null>(null);

    const [modalDescription, setModalDescription] =
        useState<CatalogDescriptionData | null>(null);

    const [modalDescriptionLoading, setModalDescriptionLoading] =
        useState(false);

    const [modalDescriptionError, setModalDescriptionError] =
        useState<string | null>(null);

    const descriptionRequestId = useRef(0);

    const [modalStatus, setModalStatus] =
        useState<StatusMessage | null>(null);

    const [modalResult, setModalResult] =
        useState<InstallationResult | null>(null);

    const [modalInstallLoading, setModalInstallLoading] =
        useState(false);

    const [installProgress, setInstallProgress] =
        useState<InstallProgressData | null>(null);

    const [activeInstall, setActiveInstall] =
        useState<ActiveInstallRecord | null>(null);

    const [activeProgress, setActiveProgress] =
        useState<InstallProgressData | null>(null);

    const [outcomeBanner, setOutcomeBanner] =
        useState<StatusMessage | null>(null);

    const activeInstallRef =
        useRef<ActiveInstallRecord | null>(null);

    const activePollTimer = useRef<number | null>(null);

    const outcomeTimer = useRef<number | null>(null);

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

    const activeRunning =
        activeInstall !== null && isActiveRunning(activeProgress);

    const badgeCount = activeRunning
        ? 1
        : (installed !== null && installed.length > 0
            ? installed.length
            : null);

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

    // Which toolbar tabs the active provider can actually serve. CurseForge
    // only wires modpacks, so the mods tab is disabled while it is selected.
    const enabledContentTypes = providerSupportedContentTypes(
        activeProvider?.capabilities,
    );

    // ── Mod version window derived state ─────────────────────────────
    // Loaders and game versions offered in the two dropdowns, computed from
    // the versions the mod actually publishes, sorted newest-game-version
    // first. The install button only appears once both dropdowns resolve to
    // exactly one version.
    const modLoaders = modVersions === null
        ? []
        : Array.from(
            new Set(modVersions.flatMap((version) => version.loaders)),
        ).sort();

    const modGameVersions = modVersions === null
        ? []
        : Array.from(
            new Set(modVersions.flatMap((version) => version.game_versions)),
        )
            .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }));

    // CurseForge records a loader only for mods and modpacks. Plugins,
    // resource packs, data packs and shaders are tagged with Minecraft
    // versions alone, so those resolve from the Minecraft dropdown only
    // instead of demanding a loader that does not exist upstream.
    const modHasLoaders = modLoaders.length > 0;

    const modVersionSource = (() => {
        if (
            modVersions === null
            || modMcSelection === ''
            || (modHasLoaders && modLoaderSelection === '')
        ) {
            return null;
        }

        const matches = modVersions.filter(
            (version) =>
                version.game_versions.includes(modMcSelection)
                && (
                    !modHasLoaders
                    || version.loaders.includes(modLoaderSelection)
                ),
        );

        if (matches.length === 0) {
            return null;
        }

        // Modrinth returns newest-first; keep that ordering.
        return matches[0].source;
    })();

    const modSelectedVersion =
        modVersions !== null && modVersionSource !== null
            ? modVersions.find(
                (version) => version.source === modVersionSource,
            ) ?? null
            : null;

    // Loaders/versions that share at least one version with the current
    // other selection, so the dropdowns guide toward installable combos.
    const modLoadersForMc = modVersions === null || modMcSelection === ''
        ? modLoaders
        : modLoaders.filter((loader) =>
            modVersions.some(
                (version) =>
                    version.loaders.includes(loader)
                    && version.game_versions.includes(modMcSelection),
            ),
        );

    const modMcForLoader = modVersions === null || modLoaderSelection === ''
        ? modGameVersions
        : modGameVersions.filter((mc) =>
            modVersions.some(
                (version) =>
                    version.game_versions.includes(mc)
                    && version.loaders.includes(modLoaderSelection),
            ),
        );

    // Recommended dependencies of the currently resolved version, annotated
    // with the exact version of each dependency that matches the selected
    // loader + Minecraft version pair (or null when none does).
    const modDependencyCards =
        modSelectedVersion === null
            ? []
            : modSelectedVersion.dependencies.map((dependency) => {
                const versions =
                    dependencyVersions[dependency.project_id] ?? null;

                let resolved: ModVersion | null = null;

                if (
                    versions !== null
                    && modMcSelection !== ''
                    && (!modHasLoaders || modLoaderSelection !== '')
                ) {
                    resolved =
                        versions.find(
                            (version) =>
                                version.game_versions.includes(
                                    modMcSelection,
                                )
                                && (
                                    !modHasLoaders
                                    || version.loaders.includes(
                                        modLoaderSelection,
                                    )
                                ),
                        ) ?? null;
                }

                return {
                    ...dependency,
                    versions,
                    resolvedVersion: resolved,
                };
            });

    const providerOptions = visibleProviders.map((provider) => ({
        value: provider.name,
        label: provider.available
            ? provider.label
            : `${provider.label} (unavailable)`,
        disabled: !provider.available,
        icon:
            provider.name === 'modrinth'
                ? <ModrinthIcon />
                : provider.name === 'curseforge'
                    ? <CurseForgeIcon />
                    : undefined,
    }));

    const providerLabels: Record<string, string> =
        Object.fromEntries(
            (providers ?? []).map((provider) => [
                provider.name,
                provider.label,
            ]),
        );

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
            limit: Number.parseInt(next.stack, 10) || PAGE_LIMIT,
        };

        // Tab → backend content type. Tabs without backend support (null)
        // send 'modpack' so validation passes; a defined empty result is
        // shown instead of a 422.
        params.content_type =
            backendContentType(contentTypeRef.current) ?? 'modpack';

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

        // The facet lists the backend advertises (categories, loaders) are
        // content-type specific, so the providers endpoint is re-read
        // whenever the tab changes. Only the first run applies the backend
        // defaults and kicks off the initial search.
        const initial = !initialSearchRan.current;

        const fetchProviders = async () => {
            try {
                const response =
                    await axios.get<ProvidersResponse>(
                        `${API_BASE}/catalog/providers`,
                        {
                            params: {
                                content_type: backendContentType(
                                    contentType,
                                ),
                            },
                        },
                    );

                if (cancelled || !alive.current) {
                    return;
                }

                setProviders(response.data.data.providers);

                if (!initial) {
                    // Tab switch: only the facet lists changed.
                    return;
                }

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

                // A persisted tab the chosen provider cannot serve (e.g.
                // Mods with a modpack-only provider) would search a catalog
                // that never has rows; fall back to its first served tab.
                const supported = providerSupportedContentTypes(
                    response.data.data.providers.find(
                        (provider) => provider.name === chosenProvider,
                    )?.capabilities,
                );

                if (!supported.includes(backendContentType(contentType))) {
                    const fallback = CONTENT_TYPE_OPTIONS.find(
                        (option) =>
                            supported.includes(
                                backendContentType(option.value),
                            ),
                    );

                    if (fallback) {
                        contentTypeRef.current = fallback.value;
                        setContentType(fallback.value);

                        try {
                            window.localStorage.setItem(
                                CONTENT_TYPE_STORAGE_KEY,
                                fallback.value,
                            );
                        } catch {
                            // storage unavailable; selection won't persist
                        }
                    }
                }

                const defaults = applyBackendDefaults(response.data.data);

                setFilters((current) => ({
                    ...current,
                    provider: chosenProvider,
                    sort: defaults.sort,
                    stack: defaults.stack,
                }));
            } catch {
                if (cancelled || !alive.current) {
                    return;
                }

                setProvidersError(
                    'Unable to load catalog providers.',
                );
            }

            if (initial) {
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
    }, [contentType]);

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
                'Unable to load the installed addons.',
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
            await axios.post<UpdateResponse>(
                `${API_BASE}/servers/${server}/installed/${record.id}/update`,
            );

            if (!alive.current) {
                return;
            }

            setInstalledStatus(null);
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
            await axios.post<UninstallResponse>(
                `${API_BASE}/servers/${server}/installed/${record.id}/uninstall`,
            );

            if (!alive.current) {
                return;
            }

            setInstalled((current) =>
                current === null
                    ? null
                    : current.filter(
                        (item) => item.id !== record.id,
                    ),
            );
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

    const restoreInstalledModpack = async (
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
            await axios.post<RestoreResponse>(
                `${API_BASE}/servers/${server}/installed/${record.id}/restore`,
            );

            if (!alive.current) {
                return;
            }

            setInstalledStatus(null);
            refreshInstalled();
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to restore the missing modpack files.';

            setInstalledStatus({
                kind: 'error',
                message:
                    requestError.response?.data?.manual_download
                        ? 'This modpack requires a manual download to restore.'
                        : message,
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

        // The new provider may not serve the current tab (CurseForge has no
        // mods wiring); fall back to its first supported tab so the next
        // search never hits a provider that returns empty rows for it.
        const nextProvider = providers?.find(
            (entry) => entry.name === provider,
        );

        const supported = providerSupportedContentTypes(
            nextProvider?.capabilities,
        );

        if (!supported.includes(backendContentType(contentType) ?? '')) {
            const fallback = CONTENT_TYPE_OPTIONS.find(
                (option) =>
                    supported.includes(
                        backendContentType(option.value) ?? '',
                    ),
            );

            if (fallback) {
                changeContentType(fallback.value);
            }
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

    const changeContentType = (next: CatalogContentType) => {
        if (next === contentType) {
            return;
        }

        // Update the ref synchronously: the search fired below must carry
        // the NEW tab's content type, but a plain setState only lands in the
        // ref after a re-render — the stale value made tab switches search
        // the previous tab's catalog (Mods → modpacks, Modpacks → mods).
        contentTypeRef.current = next;

        setContentType(next);

        try {
            window.localStorage.setItem(CONTENT_TYPE_STORAGE_KEY, next);
        } catch {
            // Storage unavailable (private mode) — selection just won't persist.
        }

        // Each content type is its own search: drop the query, filters and
        // pagination so the new tab starts clean.
        applyFilters({
            query: '',
            gameVersions: [],
            loaders: [],
            categories: [],
            environment: '',
            page: 1,
        });
    };

    const changeStack = (stack: string) => {
        applyFilters({ stack, page: 1 });
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
        descriptionRequestId.current++;

        // Single-file content (mods, plugins, datapacks, ...) opens the
        // loader/Minecraft-version window; modpacks keep the version list.
        const isContent = isSingleFileContentType(contentTypeRef.current);

        setDetailsItem(item);
        setModalVersions(isContent ? null : modalVersions);
        setModalVersionsError(isContent ? null : modalVersionsError);
        setModalVersionsLoading(false);
        setModVersions(isContent ? null : modVersions);
        setModVersionsError(null);
        setModVersionsLoading(false);
        setModLoaderSelection('');
        setModMcSelection('');
        setSelectedDependencyIds([]);
        setModalVersionSource(null);
        setModalMetadata(null);
        setModalMetadataLoading(false);
        setModalMetadataError(null);
        setModalDescription(null);
        setModalDescriptionLoading(false);
        setModalDescriptionError(null);
        setModalStatus(null);
        setModalResult(null);
        setModalInstallLoading(false);
        setVersionPickerOpen(false);

        loadDescription(item);
    };

    const closeDetailsModal = () => {
        versionsRequestId.current++;
        metadataRequestId.current++;
        descriptionRequestId.current++;

        setDetailsItem(null);
        setModalVersions(null);
        setModalVersionsError(null);
        setModalVersionsLoading(false);
        setModVersions(null);
        setModVersionsError(null);
        setModVersionsLoading(false);
        setModLoaderSelection('');
        setModMcSelection('');
        setModalMetadata(null);
        setModalMetadataLoading(false);
        setModalMetadataError(null);
        setModalVersionSource(null);
        setModalDescription(null);
        setModalDescriptionLoading(false);
        setModalDescriptionError(null);
        setModalResult(null);
        setModalInstallLoading(false);
        setModalStatus(null);
        setVersionPickerOpen(false);
    };

    const loadDescription = async (
        item: CatalogItem,
    ) => {
        const id = ++descriptionRequestId.current;

        setModalDescriptionLoading(true);
        setModalDescriptionError(null);
        setModalDescription(null);

        try {
            const response =
                await axios.get<CatalogDescriptionResponse>(
                    `${API_BASE}/catalog/description`,
                    {
                        params: {
                            provider: item.provider,
                            project: item.provider_project_id,
                        },
                    },
                );

            if (
                !alive.current
                || id !== descriptionRequestId.current
            ) {
                return;
            }

            setModalDescription(response.data.data);
            setModalDescriptionLoading(false);
        } catch {
            if (
                !alive.current
                || id !== descriptionRequestId.current
            ) {
                return;
            }

            setModalDescriptionLoading(false);
            setModalDescriptionError(
                'Unable to load the modpack description.',
            );
        }
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

    // Mod flavor of loadVersions: hits the mod-versions endpoint which
    // returns per-version loader/game-version options and dependencies.
    const loadModVersions = async () => {
        const item = detailsItem;

        if (!item) {
            return;
        }

        const id = ++versionsRequestId.current;

        setModVersionsLoading(true);
        setModVersions(null);
        setModVersionsError(null);

        try {
            const response =
                await axios.get<ModVersionsResponse>(
                    `${API_BASE}/catalog/mod-versions`,
                    {
                        params: {
                            provider: item.provider,
                            project: item.provider_project_id,
                        },
                    },
                );

            if (
                !alive.current
                || id !== versionsRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            const payload = response.data.data;

            setModVersions(payload.versions);

            // Files exist but every download URL is withheld: the author
            // disabled automated downloads. Saying "no versions" there would
            // be wrong, so name the actual reason.
            if (
                payload.versions.length === 0
                && payload.unavailable_reason === 'distribution_disabled'
            ) {
                setModVersionsError(
                    'The author disabled automated downloads for this project '
                    + 'on CurseForge, so it has to be installed manually. '
                    + 'Open it on CurseForge from the button above.',
                );
            }
        } catch (requestError: any) {
            if (
                !alive.current
                || id !== versionsRequestId.current
                || detailsItem === null
            ) {
                return;
            }

            // `error` is this extension's shape and `message` is the panel's
            // generic handler: showing whichever arrived beats a generic
            // sentence that hides the real cause (a missing API key, a rate
            // limit, an upstream 403).
            setModVersionsError(
                requestError.response?.data?.error ||
                requestError.response?.data?.message ||
                'Unable to load the mod versions.',
            );
        } finally {
            if (
                alive.current
                && id === versionsRequestId.current
                && detailsItem !== null
            ) {
                setModVersionsLoading(false);
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

    const retryModVersions = () => {
        loadModVersions();
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
            setModalStatus(null);
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

    const stopActivePolling = () => {
        if (activePollTimer.current !== null) {
            window.clearInterval(activePollTimer.current);
            activePollTimer.current = null;
        }
    };

    const clearActiveInstallStorage = () => {
        if (!server) {
            return;
        }

        try {
            window.localStorage.removeItem(
                activeInstallStorageKey(server),
            );
        } catch {
            // storage unavailable; nothing to clear
        }
    };

    const scheduleOutcomeClear = () => {
        if (outcomeTimer.current !== null) {
            window.clearTimeout(outcomeTimer.current);
        }

        outcomeTimer.current = window.setTimeout(() => {
            outcomeTimer.current = null;
            setOutcomeBanner(null);
            setActiveInstall(null);
            setActiveProgress(null);
        }, 15000);
    };

    const dismissOutcome = () => {
        if (outcomeTimer.current !== null) {
            window.clearTimeout(outcomeTimer.current);
            outcomeTimer.current = null;
        }

        stopActivePolling();
        setOutcomeBanner(null);
        setActiveInstall(null);
        setActiveProgress(null);
    };

    const clearActiveInstall = () => {
        stopActivePolling();
        clearActiveInstallStorage();
        setActiveInstall(null);
        setActiveProgress(null);
    };

    const newProgressToken = (): string => {
        if (typeof window.crypto?.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        let token = '';
        const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

        while (token.length < 32) {
            token +=
                alphabet[Math.floor(Math.random() * alphabet.length)];
        }

        return token;
    };

    const beginActiveInstall = ({
        source,
        provider,
        name,
        version,
        iconUrl,
        mc_version = null,
        loader = null,
        kind = null,
        files = [],
    }: {
        source: string;
        provider: string;
        name: string;
        version: string;
        iconUrl: string | null;
        /** Optional selection pills for single-file installs (mods). */
        mc_version?: string | null;
        loader?: string | null;
        /** Catalog kind of the entry (modpack, mod, plugin, ...). */
        kind?: string | null;
        /** Every file this run installs (main content + dependencies). */
        files?: ActiveInstallFile[];
    }): string | null => {
        if (!server) {
            return null;
        }

        const token = newProgressToken();

        if (outcomeTimer.current !== null) {
            window.clearTimeout(outcomeTimer.current);
            outcomeTimer.current = null;
        }

        const record: ActiveInstallRecord = {
            server,
            token,
            provider,
            name,
            version,
            icon_url: iconUrl,
            source,
            started_at: new Date().toISOString(),
            mc_version,
            loader,
            kind,
            files,
        };

        setActiveInstall(record);
        setActiveProgress({
            phase: 'starting',
            percent: 0,
            indeterminate: false,
        });
        setInstallProgress({
            phase: 'starting',
            percent: 1,
            indeterminate: false,
        });
        setOutcomeBanner(null);

        try {
            window.localStorage.setItem(
                activeInstallStorageKey(server),
                JSON.stringify(record),
            );
        } catch {
            // storage unavailable; the in-memory state still applies
        }

        return token;
    };

    // Human label for an install run: a multi-file run names how many extra
    // files came along with the main content.
    const installLabel = (record: ActiveInstallRecord): string => {
        const extra = (record.files?.length ?? 1) - 1;

        return extra > 0
            ? `${record.name} + ${extra} more`
            : record.name;
    };

    const handleProgressState = (
        state: InstallProgressData,
        record: ActiveInstallRecord,
    ) => {
        if (state.phase === 'idle') {
            // 'idle' means the backend has no progress snapshot for the token
            // yet. Immediately after starting an install this is expected:
            // the install POST may still be in flight / being processed, so a
            // poll can win the race against the first store write. Only treat
            // an empty store as an abandoned install once the local record is
            // well past its start; otherwise keep the card alive and keep
            // polling so the running install resurfaces when it lands.
            const startedAt = new Date(record.started_at).getTime();
            const ageMs = Number.isNaN(startedAt)
                ? Number.POSITIVE_INFINITY
                : Date.now() - startedAt;

            if (ageMs >= IDLE_GRACE_MS) {
                clearActiveInstall();
            }
            return;
        }

        if (state.phase === 'complete') {
            stopActivePolling();
            clearActiveInstallStorage();
            setActiveInstall(null);
            setActiveProgress(null);
            setOutcomeBanner({
                kind: 'success',
                message: `${installLabel(record)} installed successfully.`,
            });
            loadInstalled();
            return;
        }

        if (state.phase === 'cancelled' || state.phase === 'failed') {
            stopActivePolling();
            clearActiveInstallStorage();
            cancellingSince.current = null;
            setActiveProgress(state);

            const message =
                state.message
                || (state.phase === 'cancelled'
                    ? `${installLabel(record)} download was cancelled.`
                    : `${installLabel(record)} installation failed.`);

            setOutcomeBanner({
                kind: state.phase === 'cancelled' ? 'info' : 'error',
                message,
            });

            scheduleOutcomeClear();
            return;
        }

        if (state.phase === 'cancelling') {
            // A requested cancellation normally resolves to 'cancelled' within
            // a few seconds. If the backend has not reported back for a long
            // time the install request is wedged (e.g. an upstream that never
            // closes a stalled connection), so stop the card from spinning
            // "Cancelling ..." forever and surface the outcome locally.
            if (cancellingSince.current === null) {
                cancellingSince.current = Date.now();
            } else if (
                Date.now() - cancellingSince.current
                >= CANCEL_STUCK_TIMEOUT_MS
            ) {
                stopActivePolling();
                clearActiveInstallStorage();
                cancellingSince.current = null;
                setActiveProgress(state);

                setOutcomeBanner({
                    kind: 'info',
                    message: `${installLabel(record)} download was cancelled.`,
                });

                scheduleOutcomeClear();
                return;
            }
        } else {
            cancellingSince.current = null;
        }

        // Stale-snapshot watchdog: a running phase whose snapshot content has
        // not changed for a long time means the install request died (killed
        // by a PHP timeout, OOM, or a crash) — without this the card would
        // sit on the last snapshot forever. Measured entirely with the LOCAL
        // clock against the last time the snapshot CONTENT changed, so server
        // vs browser clock skew can never trigger a false failure. A long
        // window is required because the backend legitimately goes quiet
        // while retrying slow transfers and connecting to mirrors.
        const runningPhase
            = state.phase === 'download'
            || state.phase === 'deploy'
            || state.phase === 'preparing';

        if (runningPhase) {
            const key = snapshotKey(state);

            if (key !== lastSnapshotKey.current) {
                lastSnapshotKey.current = key;
                lastSnapshotChangeAt.current = Date.now();
            } else if (
                lastSnapshotChangeAt.current !== null
                && Date.now() - lastSnapshotChangeAt.current
                >= PROGRESS_STALE_TIMEOUT_MS
            ) {
                stopActivePolling();
                clearActiveInstallStorage();
                setActiveProgress({
                    ...state,
                    phase: 'failed',
                    indeterminate: false,
                    message:
                        `${installLabel(record)} installation stopped responding.`,
                });

                setOutcomeBanner({
                    kind: 'error',
                    message:
                        `${installLabel(record)} installation stopped ` +
                        'responding. Please try again.',
                });

                scheduleOutcomeClear();
                return;
            }
        } else {
            lastSnapshotKey.current = null;
            lastSnapshotChangeAt.current = null;
        }

        setActiveProgress(state);
    };

    const cancelActiveInstall = async () => {
        const activeRef = activeInstallRef.current;

        if (!activeRef || !server) {
            return;
        }

        try {
            await axios.post(
                `${API_BASE}/servers/${server}/install/cancel`,
                undefined,
                {
                    params: {
                        progress_token: activeRef.token,
                    },
                },
            );
        } catch {
            // The install request clears the flag when it finishes; the
            // polling loop still surfaces the cancelled state on its own.
        }

        setActiveProgress((current) =>
            current === null
                ? {
                    phase: 'cancelling',
                    percent: 0,
                    indeterminate: false,
                }
                : {
                    ...current,
                    phase: 'cancelling',
                    indeterminate: false,
                },
        );
    };

    useEffect(() => {
        activeInstallRef.current = activeInstall;
    });

    useEffect(() => {
        const record = activeInstall;

        if (record === null) {
            return;
        }

        let stopped = false;

        const tick = async () => {
            if (stopped || !alive.current) {
                return;
            }

            try {
                const response =
                    await axios.get<InstallProgressResponse>(
                        `${API_BASE}/install/progress`,
                        {
                            params: {
                                progress_token: record.token,
                            },
                        },
                    );

                if (stopped || !alive.current) {
                    return;
                }

                handleProgressState(response.data.data, record);
            } catch {
                // Transient poll failures are ignored; the loop keeps going
                // and store expiry surfaces as an 'idle' state.
            }
        };

        tick();

        activePollTimer.current = window.setInterval(tick, 750);

        return () => {
            stopped = true;

            if (activePollTimer.current !== null) {
                window.clearInterval(activePollTimer.current);
                activePollTimer.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeInstall?.token]);

    useEffect(() => {
        if (!server) {
            return;
        }

        let cancelled = false;

        try {
            const raw = window.localStorage.getItem(
                activeInstallStorageKey(server),
            );

            if (raw) {
                const record = JSON.parse(raw) as ActiveInstallRecord;

                if (
                    !cancelled
                    && record
                    && typeof record.token === 'string'
                    && record.server === server
                ) {
                    setActiveInstall(record);
                    setActiveProgress({
                        phase: 'starting',
                        percent: 0,
                        indeterminate: false,
                    });
                }
            }
        } catch {
            // Storage unavailable or corrupt; a fresh install can start.
        }

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [server]);

    useEffect(() => {
        if (!server) {
            return;
        }

        const onStorage = (event: StorageEvent) => {
            if (event.key !== activeInstallStorageKey(server)) {
                return;
            }

            if (event.newValue === null) {
                if (activeInstallRef.current !== null) {
                    clearActiveInstall();
                }
                return;
            }

            try {
                const record =
                    JSON.parse(event.newValue) as ActiveInstallRecord;

                if (
                    record
                    && typeof record.token === 'string'
                    && record.server === server
                ) {
                    setActiveInstall(record);
                    setActiveProgress((current) =>
                        current !== null && isActiveRunning(current)
                            ? current
                            : {
                                phase: 'starting',
                                percent: 0,
                                indeterminate: false,
                            },
                    );
                }
            } catch {
                // ignore malformed cross-tab writes
            }
        };

        window.addEventListener('storage', onStorage);

        return () => {
            window.removeEventListener('storage', onStorage);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [server]);

    const requestReplaceConfirmation = (continuation: () => void) => {
        pendingReplaceContinuation.current = continuation;
        setReplaceConfirmOpen(true);
    };

    const confirmReplaceInstall = () => {
        const continuation = pendingReplaceContinuation.current;

        pendingReplaceContinuation.current = null;
        setReplaceConfirmOpen(false);

        if (continuation === null) {
            return;
        }

        skipReplaceCheck.current = true;

        continuation();
    };

    const cancelReplaceInstall = () => {
        pendingReplaceContinuation.current = null;
        skipReplaceCheck.current = false;
        setReplaceConfirmOpen(false);
    };

    const installModalModpack = async () => {
        if (!server) {
            setModalStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        const isContentInstall =
            detailsItem !== null &&
            isSingleFileContentType(contentTypeRef.current) &&
            detailsItem.provider === 'modrinth';

        if (isContentInstall) {
            // Both dropdowns must be chosen; the resolved version follows
            // from them.
            if (!modVersionSource) {
                setModalStatus({
                    kind: 'error',
                    message:
                        'Select a Minecraft version and loader before installing.',
                });
                return;
            }
        } else if (!modalVersionSource) {
            setModalStatus({
                kind: 'error',
                message: 'Select a modpack version before installing.',
            });
            return;
        } else if (!modalMetadata) {
            setModalStatus({
                kind: 'error',
                message: 'Resolve the modpack version before installing.',
            });
            return;
        }

        if (modalInstallLoading || activeRunning) {
            return;
        }

        // Single-file content is not a full modpack: installing a mod,
        // plugin, datapack, resource pack, or shader never replaces an
        // existing modpack, so the replace warning that guards the modpack
        // pipeline does not apply here.
        if (
            !isContentInstall
            && !skipReplaceCheck.current
            && hasInstalledModpack
        ) {
            requestReplaceConfirmation(installModalModpack);
            return;
        }

        skipReplaceCheck.current = false;

        // Narrowed above: content installs have a resolved source; modpack
        // installs have both a source and metadata.
        const installSource = isContentInstall
            ? (modVersionSource as string)
            : (modalVersionSource as string);

        // Selected recommended dependencies install in the same run, each
        // becoming its own tracked record on the server.
        const chosenDependencies = isContentInstall
            ? modDependencyCards.filter(
                (card) =>
                    selectedDependencyIds.includes(card.project_id)
                    && card.resolvedVersion !== null,
            )
            : [];

        // The downloading window lists every file this run fetches, so a
        // multi-file install shows one card per file instead of a single
        // card for the whole run. Each carries the kind of content it is;
        // the backend corrects a dependency whose kind differs from the
        // entry the user picked (a shader's Iris dependency is a mod).
        const tabKind = isContentInstall
            ? backendContentType(contentTypeRef.current)
            : null;

        const installFiles: ActiveInstallFile[] = isContentInstall
            ? [
                {
                    name: detailsItem?.name ?? '',
                    icon_url: detailsItem?.icon_url ?? null,
                    dependency: false,
                    kind: tabKind,
                },
                ...chosenDependencies.map((card) => ({
                    name: card.title,
                    icon_url: card.icon_url ?? null,
                    dependency: true,
                    kind: tabKind,
                })),
            ]
            : [];

        const token = beginActiveInstall({
            source: installSource,
            provider: detailsItem?.provider ?? '',
            name: isContentInstall
                ? (detailsItem?.name ?? '')
                : (modalMetadata?.name ?? ''),
            version: isContentInstall
                ? (modSelectedVersion?.version_number ?? '')
                : (modalMetadata?.version ?? ''),
            iconUrl: isContentInstall
                ? (detailsItem?.icon_url ?? null)
                : (modalMetadata?.icon_url ?? null),
            mc_version: isContentInstall ? modMcSelection : null,
            loader:
                isContentInstall && modHasLoaders
                    ? modLoaderSelection
                    : null,
            kind: isContentInstall ? tabKind : 'modpack',
            files: installFiles,
        });

        if (token === null) {
            setModalStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        setModalInstallLoading(true);
        setModalStatus(null);
        setModalResult(null);

        // Single-file content uses the simple install endpoint; modpacks
        // keep the full archive pipeline. Both speak the same
        // progress/cancel protocol, so the polling loop below is shared.
        const endpoint = isContentInstall
            ? `${API_BASE}/servers/${server}/install/mod`
            : `${API_BASE}/servers/${server}/install`;

        const body: Record<string, unknown> = {
            source: installSource,
        };

        if (isContentInstall) {
            body.name = detailsItem?.name ?? '';
            // Tells the backend which directory the file belongs in
            // (mods, plugins, world/datapacks, ...).
            body.content_type = backendContentType(contentTypeRef.current);
            body.mc_version = modMcSelection;

            // Loader-less content (CurseForge plugins, packs, shaders, data
            // packs) sends no loader: there is none to send.
            if (modHasLoaders) {
                body.loader = modLoaderSelection;
            }

            if (detailsItem?.icon_url) {
                body.icon_url = detailsItem.icon_url;
            }

            if (chosenDependencies.length > 0) {
                body.dependencies = chosenDependencies.map((card) => ({
                    source: card.resolvedVersion?.source ?? '',
                    name: card.title,
                    icon_url: card.icon_url ?? '',
                }));
            }
        }

        closeDetailsModal();
        setInstalledOpen(true);
        loadInstalled();

        try {
            await axios.post<InstallResponse>(
                endpoint,
                body,
                {
                    params: {
                        progress_token: token,
                    },
                },
            );
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            if (requestError.response?.status === 503) {
                // Another install holds the lock; the Installed window is
                // already open and shows the running install.
                clearActiveInstall();
                return;
            }

            if (requestError.response?.status === 409) {
                // Our own cancel request; the polling loop surfaces the
                // cancelled state on its next tick.
                return;
            }

            // Network errors (timeout, closed tab/connection dropped) or 5xx
            // from the reverse proxy / php-fpm. The request may still be
            // running server-side, so do not tear down the active install;
            // the polling loop resurfaces progress, completion, or an 'idle'
            // cleanup on its next tick.
            if (
                !requestError.response ||
                (requestError.response?.status ?? 0) >= 500
            ) {
                return;
            }

            // Definitive pre-start rejection (4xx, e.g. 422 validation).
            const message =
                requestError.response?.data?.error ||
                (isContentInstall
                    ? 'Unable to install the content.'
                    : 'Unable to install the modpack.');

            stopActivePolling();
            clearActiveInstallStorage();
            setActiveProgress({
                phase: 'failed',
                percent: 0,
                indeterminate: false,
                message,
            });
            setOutcomeBanner({
                kind: 'error',
                message,
            });
            scheduleOutcomeClear();
        } finally {
            if (alive.current) {
                setModalInstallLoading(false);
            }
        }
    };

    useEffect(() => {
        if (detailsItem === null) {
            return;
        }

        // Single-file content uses the dedicated version catalog
        // (loader/game-version options + dependencies); modpacks keep the
        // classic version list picker.
        if (isSingleFileContentType(contentTypeRef.current)) {
            loadModVersions();
        } else {
            loadVersions();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [detailsItem]);

    // Once the loader + MC pair resolves a version, fetch each recommended
    // dependency project's own versions so the cards can offer the exact
    // compatible build for the same pair. Cleared when the pair changes.
    useEffect(() => {
        if (modSelectedVersion === null) {
            dependencyVersionsRequestId.current++;
            setDependencyVersions({});

            return;
        }

        const deps = modSelectedVersion.dependencies;

        if (deps.length === 0) {
            setDependencyVersions({});

            return;
        }

        const id = ++dependencyVersionsRequestId.current;

        // Mark all as loading; resolved lists replace the nulls as they land.
        setDependencyVersions((current) => {
            const next: Record<string, ModVersion[] | null> = {};

            for (const dependency of deps) {
                next[dependency.project_id] = current[
                    dependency.project_id
                ] ?? null;
            }

            return next;
        });

        // Dependencies belong to the same provider as the entry that
        // references them: a CurseForge mod recommends CurseForge mods,
        // addressed by their numeric id instead of a slug.
        const dependencyProvider = detailsItem?.provider ?? 'modrinth';

        const fetchOne = async (
            projectId: string,
            slug: string | null,
        ) => {
            try {
                const response = await axios.get<ModVersionsResponse>(
                    `${API_BASE}/catalog/mod-versions`,
                    {
                        params: {
                            provider: dependencyProvider,
                            project: slug ?? projectId,
                        },
                    },
                );

                if (!alive.current || id !== dependencyVersionsRequestId.current) {
                    return;
                }

                setDependencyVersions((current) => ({
                    ...current,
                    [projectId]: response.data.data.versions,
                }));
            } catch {
                if (!alive.current || id !== dependencyVersionsRequestId.current) {
                    return;
                }

                // A failed lookup just disables that card's install toggle.
                setDependencyVersions((current) => ({
                    ...current,
                    [projectId]: [],
                }));
            }
        };

        for (const dependency of deps) {
            fetchOne(dependency.project_id, dependency.slug);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [modSelectedVersion?.version_id]);

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

        if (installLoading || activeRunning) {
            return;
        }

        if (!skipReplaceCheck.current && hasInstalledModpack) {
            requestReplaceConfirmation(installManualSource);
            return;
        }

        skipReplaceCheck.current = false;

        const token = beginActiveInstall({
            source: selectedSource,
            provider: metadata?.source.split('://')[0] ?? '',
            name: metadata?.name ?? selectedSource,
            version: metadata?.version ?? '',
            iconUrl: metadata?.icon_url ?? null,
        });

        if (token === null) {
            setStatus({
                kind: 'error',
                message: 'Unable to determine the current server.',
            });
            return;
        }

        setInstallLoading(true);
        setStatus(null);
        setResult(null);

        setInstalledOpen(true);
        loadInstalled();

        try {
            await axios.post<InstallResponse>(
                `${API_BASE}/servers/${server}/install`,
                {
                    source: selectedSource,
                },
                {
                    params: {
                        progress_token: token,
                    },
                },
            );
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            if (requestError.response?.status === 503) {
                clearActiveInstall();
                return;
            }

            if (requestError.response?.status === 409) {
                return;
            }

            // Network errors (timeout, closed tab/connection dropped) or 5xx
            // from the reverse proxy / php-fpm. The request may still be
            // running server-side, so do not tear down the active install;
            // the polling loop resurfaces progress, completion, or an 'idle'
            // cleanup on its next tick.
            if (
                !requestError.response ||
                (requestError.response?.status ?? 0) >= 500
            ) {
                return;
            }

            // Definitive pre-start rejection (4xx, e.g. 422 validation).
            const message =
                requestError.response?.data?.error ||
                'Unable to install the modpack.';

            stopActivePolling();
            clearActiveInstallStorage();
            setActiveProgress({
                phase: 'failed',
                percent: 0,
                indeterminate: false,
                message,
            });
            setOutcomeBanner({
                kind: 'error',
                message,
            });
            scheduleOutcomeClear();
        } finally {
            if (alive.current) {
                setInstallLoading(false);
            }
        }
    };

    return (
        <div
            className="modpackinstaller-root"
            aria-busy={processing || searching}
        >
            <div className="modpackinstaller-card">
                <CatalogToolbar
                    contentType={contentType}
                    onContentTypeChange={changeContentType}
                    enabledContentTypes={enabledContentTypes}
                    query={filters.query}
                    onQueryChange={onQueryChange}
                    onQuerySubmit={submitQuery}
                    onQueryClear={clearQuery}
                    catalogBusy={catalogBusy}
                    providerValue={filters.provider}
                    onProviderChange={changeProvider}
                    providerOptions={providerOptions}
                    sortValue={filters.sort}
                    onSortChange={changeSort}
                    stackValue={filters.stack}
                    onStackChange={changeStack}
                    filtersOpen={filtersOpen}
                    onToggleFilters={() =>
                        setFiltersOpen((current) => !current)
                    }
                    activeFilterCount={activeFilterCount}
                    view={view}
                    onViewChange={changeView}
                    installedCount={
                        installed !== null ? installed.length : null
                    }
                    badgeCount={badgeCount}
                    onOpenInstalled={openInstalledModal}
                />

                {filtersOpen && (
                    <FilterPanel
                        capabilities={capabilities}
                        facets={facets}
                        filters={filters}
                        onToggleListValue={toggleListValue}
                        onReset={resetFilters}
                        onClose={() => setFiltersOpen(false)}
                        activeFilterCount={activeFilterCount}
                        searching={searching}
                    />
                )}

                {activeFilterCount > 0 && (
                    <ActiveFilterChips
                        filters={filters}
                        onToggleListValue={toggleListValue}
                        searching={searching}
                    />
                )}

                {providersError && (
                    <div
                        className="modpackinstaller-status modpackinstaller-status--error"
                        role="alert"
                    >
                        {providersError}
                    </div>
                )}

                <CatalogResults
                    items={items}
                    pagination={pagination}
                    searching={searching}
                    catalogError={catalogError}
                    view={view}
                    processing={processing}
                    providerLabels={providerLabels}
                    contentType={contentType}
                    onRetry={() =>
                        runSearch({
                            ...filtersRef.current,
                            page: 1,
                        })
                    }
                    onOpenDetails={openDetailsModal}
                    onPage={goToPage}
                />

                {manualOpen && (
                    <div className="modpackinstaller-manual-panel">
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
                    </div>
                )}
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
                                <div className="modpackinstaller-metadata-title">
                                    <h4>{metadata.name}</h4>

                                    <span className="modpackinstaller-catalog-card-provider">
                                        {providerLabels[
                                            metadata.source.split('://')[0]
                                        ] ?? metadata.source.split('://')[0]}
                                    </span>
                                </div>

                                {metadata.description && (
                                    <p>{metadata.description}</p>
                                )}

                                <div className="modpackinstaller-pill-row">
                                    <span className="modpackinstaller-pill">
                                        {metadata.version}
                                    </span>

                                    {metadata.minecraft_version && (
                                        <span className="modpackinstaller-pill">
                                            {metadata.minecraft_version}
                                        </span>
                                    )}

                                    {metadata.loader && (
                                        <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                            {metadata.loader}
                                        </span>
                                    )}
                                </div>
                            </div>
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
                                    disabled={
                                        installLoading
                                        || result !== null
                                        || activeRunning
                                    }
                                    className={`modpackinstaller-modal-actions-button modpackinstaller-install-button${
                                        result !== null
                                            ? ' modpackinstaller-modal-actions-button--success'
                                            : activeRunning
                                                ? ' modpackinstaller-modal-actions-button--locked'
                                                : hasInstalledModpack
                                                    ? ' modpackinstaller-install-button--warning'
                                                    : ''
                                    }`}
                                >
                                    {installLoading && (
                                        <span
                                            className={`modpackinstaller-install-progress-fill${
                                                installProgress
                                                    ?.indeterminate
                                                    ? ' modpackinstaller-install-progress-fill--indeterminate'
                                                    : ''
                                            }`}
                                            style={{
                                                width: `${installProgress?.percent ?? 0}%`,
                                            }}
                                        />
                                    )}
                                    <span className="modpackinstaller-install-progress-label">
                                        {activeRunning
                                            ? '1 modpack installation in progress'
                                            : installLoading
                                                ? `Installing ...${
                                                      installProgress
                                                      && !installProgress.indeterminate
                                                          ? ` ${installProgress.percent}%`
                                                          : ''
                                                  }`
                                                : result !== null
                                                    ? 'Installed'
                                                    : hasInstalledModpack
                                                        ? (
                                                            <>
                                                                <WarningIcon />
                                                                <span className="modpackinstaller-install-button-warning-label">
                                                                    Warning: installing this modpack will replace your existing modpack
                                                                </span>
                                                            </>
                                                        )
                                                        : 'Install this modpack'}
                                    </span>
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {result && (
                <div className="modpackinstaller-card">
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
                title="Installed addons"
                busy={installedLoading}
                headerActions={
                    <button
                        type="button"
                        className="modpackinstaller-icon-button modpackinstaller-icon-button--neutral"
                        onClick={refreshInstalled}
                        disabled={installedLoading}
                        aria-label="Refresh installed addons"
                    >
                        {installedLoading
                            ? <SpinnerIcon />
                            : <RefreshIcon />}
                    </button>
                }
            >
                <InstalledModpacksBody
                    installed={installed}
                    installedLoading={installedLoading}
                    installedError={installedError}
                    installedStatus={installedStatus}
                    lifecycleRecordId={lifecycleRecordId}
                    activeInstall={activeInstall}
                    activeProgress={activeProgress}
                    outcomeBanner={outcomeBanner}
                    providerLabels={providerLabels}
                    onCancelActive={cancelActiveInstall}
                    onDismissOutcome={dismissOutcome}
                    onRefresh={refreshInstalled}
                    onUpdate={updateInstalledModpack}
                    onRestore={restoreInstalledModpack}
                    onUninstall={(record) =>
                        setPendingUninstall(record)
                    }
                />
            </Modal>

            <Modal
                open={detailsItem !== null}
                onClose={closeDetailsModal}
                labelledBy="modpackinstaller-details-title"
                title={detailsItem?.name ?? 'Modpack details'}
                busy={modalBusy}
            >
                {detailsItem && (
                    <DetailsModalBody
                        detailsItem={detailsItem}
                        providerLabel={activeProvider?.label ?? null}
                        isContentEntry={isSingleFileContentType(
                            contentTypeRef.current,
                        )}
                        contentKind={
                            isSingleFileContentType(contentTypeRef.current)
                                ? backendContentType(contentTypeRef.current)
                                : null
                        }
                        modalVersions={modalVersions}
                        modalVersionsLoading={modalVersionsLoading}
                        modalVersionsError={modalVersionsError}
                        modVersions={modVersions}
                        modVersionsLoading={modVersionsLoading}
                        modVersionsError={modVersionsError}
                        modLoaderSelection={modLoaderSelection}
                        modMcSelection={modMcSelection}
                        onModLoaderChange={setModLoaderSelection}
                        onModMcChange={setModMcSelection}
                        modDependencyCards={modDependencyCards}
                        selectedDependencyIds={selectedDependencyIds}
                        onToggleDependency={(projectId) =>
                            setSelectedDependencyIds((current) =>
                                current.includes(projectId)
                                    ? current.filter(
                                        (id) => id !== projectId,
                                    )
                                    : [...current, projectId],
                            )
                        }
                        modalVersionSource={modalVersionSource}
                        modalMetadata={modalMetadata}
                        modalMetadataLoading={modalMetadataLoading}
                        modalMetadataError={modalMetadataError}
                        modalDescription={modalDescription}
                        modalDescriptionLoading={modalDescriptionLoading}
                        modalDescriptionError={modalDescriptionError}
                        modalStatus={modalStatus}
                        modalResult={modalResult}
                        modalInstallLoading={modalInstallLoading}
                        installProgress={installProgress}
                        installBlocked={activeRunning}
                        willReplace={hasInstalledModpack}
                        onSelectVersion={selectModalVersion}
                        onRetryVersions={
                            isSingleFileContentType(contentTypeRef.current)
                                ? retryModVersions
                                : retryModalVersions
                        }
                        onRetryDescription={() =>
                            detailsItem !== null
                                ? loadDescription(detailsItem)
                                : undefined
                        }
                        onInstall={installModalModpack}
                        onOpenVersionPicker={() =>
                            setVersionPickerOpen(true)
                        }
                        versionPickerOpen={versionPickerOpen}
                        onCloseVersionPicker={() =>
                            setVersionPickerOpen(false)
                        }
                    />
                )}
            </Modal>

            <Modal
                open={pendingUninstall !== null}
                onClose={() => setPendingUninstall(null)}
                labelledBy="modpackinstaller-uninstall-title"
                title="Uninstall modpack"
                busy={lifecycleRecordId !== null}
            >
                <UninstallConfirmBody
                    pendingUninstall={pendingUninstall}
                    uninstalling={lifecycleRecordId !== null}
                    onCancel={() => setPendingUninstall(null)}
                    onConfirm={(record) => {
                        setPendingUninstall(null);
                        uninstallInstalledModpack(record);
                    }}
                />
            </Modal>

            <Modal
                open={replaceConfirmOpen}
                onClose={cancelReplaceInstall}
                labelledBy="modpackinstaller-replace-title"
                title="Replace installed addon"
            >
                <ReplaceConfirmBody
                    onCancel={cancelReplaceInstall}
                    onConfirm={confirmReplaceInstall}
                />
            </Modal>

            <footer className="modpackinstaller-footer">
                <a
                    href="https://github.com/IndoGeek/addon-manager"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Addon Manager
                </a>
                <span className="modpackinstaller-footer-sep">•</span>
                <span className="modpackinstaller-footer-item">by <GitHubIcon /> IndoGeek</span>
                <span className="modpackinstaller-footer-sep">•</span>
                <span className="modpackinstaller-footer-item"><GitBranchIcon /> v{EXTENSION_VERSION}</span>
                <span className="modpackinstaller-footer-sep">•</span>
                <a
                    href="https://discord.gg/TuRR5tgvVT"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <DiscordIcon /> Contact
                </a>
            </footer>
        </div>
    );
};
