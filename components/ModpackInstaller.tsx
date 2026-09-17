/*
 * ModpackInstaller.tsx — page entry component.
 *
 * This file owns the page-level STATE and DATA FETCHING only. All
 * reusable UI lives in modules:
 *
 *   components/toolbar/*    toolbar, filter panel, active-filter chips
 *   components/cards/*      catalog cards, results, pagination, images
 *   components/modals/*     modal chrome + modal bodies
 *   components/common/*     shared widgets (Dropdown)
 *   components/icons/*      SVG icons
 *   components/types/*      API response/filter types
 *   components/utils/*      constants + formatting helpers
 *   components/styles/*     CSS sources (root.css is generated from these)
 *
 * The panel's route registration and conf.yml reference this exact
 * filename (ModpackInstaller), so it must stay at components/.
 */

import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

import {
    API_BASE,
    DEFAULT_PROVIDER,
    DEFAULT_STACK,
    EXTENSION_VERSION,
    PAGE_LIMIT,
    STACK_OPTIONS,
    VIEW_STORAGE_KEY,
    activeInstallStorageKey,
    getServerIdentifier,
} from './utils/constants';

import {
    ActiveInstallRecord,
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

const snapshotKey = (state: InstallProgressData): string =>
    [
        state.phase,
        state.percent,
        state.downloaded_bytes ?? '',
        state.total_bytes ?? '',
        state.deployed_files ?? '',
        state.total_files ?? '',
    ].join('|');

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

        setDetailsItem(item);
        setModalVersions(null);
        setModalVersionsError(null);
        setModalVersionsLoading(false);
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
    }: {
        source: string;
        provider: string;
        name: string;
        version: string;
        iconUrl: string | null;
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
                message: `${record.name} installed successfully.`,
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
                    ? `${record.name} download was cancelled.`
                    : `${record.name} installation failed.`);

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
                    message: `${record.name} download was cancelled.`,
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
                        `${record.name} installation stopped responding.`,
                });

                setOutcomeBanner({
                    kind: 'error',
                    message:
                        `${record.name} installation stopped responding. ` +
                        'Please try again.',
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

        if (modalInstallLoading || activeRunning) {
            return;
        }

        if (!skipReplaceCheck.current && hasInstalledModpack) {
            requestReplaceConfirmation(installModalModpack);
            return;
        }

        skipReplaceCheck.current = false;

        const token = beginActiveInstall({
            source: modalVersionSource,
            provider: detailsItem?.provider ?? '',
            name: modalMetadata.name,
            version: modalMetadata.version,
            iconUrl: modalMetadata.icon_url,
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

        closeDetailsModal();
        setInstalledOpen(true);
        loadInstalled();

        try {
            await axios.post<InstallResponse>(
                `${API_BASE}/servers/${server}/install`,
                {
                    source: modalVersionSource,
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
                title="Installed modpacks"
                busy={installedLoading}
                headerActions={
                    <button
                        type="button"
                        className="modpackinstaller-icon-button modpackinstaller-icon-button--blue"
                        onClick={refreshInstalled}
                        disabled={installedLoading}
                        aria-label="Refresh installed modpacks"
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
                        modalVersions={modalVersions}
                        modalVersionsLoading={modalVersionsLoading}
                        modalVersionsError={modalVersionsError}
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
                        onRetryVersions={retryModalVersions}
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
                title="Replace installed modpack"
            >
                <ReplaceConfirmBody
                    onCancel={cancelReplaceInstall}
                    onConfirm={confirmReplaceInstall}
                />
            </Modal>

            <footer className="modpackinstaller-footer">
                <a
                    href="https://github.com/IndoGeek/modpack-installer"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Modpack Installer
                </a>
                {' '}by IndoGeek · v{EXTENSION_VERSION}
            </footer>
        </div>
    );
};
