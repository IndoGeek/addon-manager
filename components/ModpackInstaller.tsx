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
    PAGE_LIMIT,
    VIEW_STORAGE_KEY,
    getServerIdentifier,
} from './utils/constants';

import {
    CatalogFilters,
    CatalogItem,
    CatalogPagination,
    CatalogProviderOption,
    CatalogResponse,
    CatalogVersion,
    CatalogVersionsResponse,
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

import { CatalogToolbar } from './toolbar/CatalogToolbar';
import { FilterPanel } from './toolbar/FilterPanel';
import { ActiveFilterChips } from './toolbar/ActiveFilterChips';

import { CatalogResults } from './cards/CatalogResults';
import { RefreshIcon, SpinnerIcon } from './icons';

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

    const [pendingUninstall, setPendingUninstall] =
        useState<InstallRecordData | null>(null);

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
            : `${provider.label} (unavailable)`,
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
                    onOpenInstalled={openInstalledModal}
                />

                {filtersOpen && (
                    <FilterPanel
                        capabilities={capabilities}
                        facets={facets}
                        filters={filters}
                        onToggleListValue={toggleListValue}
                        onToggleEnvironment={toggleEnvironment}
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
                        onToggleEnvironment={toggleEnvironment}
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
                                        {metadata.source.split('://')[0]}
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
                                    disabled={installLoading || result !== null}
                                    className={`modpackinstaller-modal-actions-button${
                                        result !== null
                                            ? ' modpackinstaller-modal-actions-button--success'
                                            : ''
                                    }`}
                                >
                                    {installLoading
                                        ? 'Installing ...'
                                        : result !== null
                                            ? 'Installation Complete'
                                            : 'Install this modpack'}
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
                        modalStatus={modalStatus}
                        modalResult={modalResult}
                        modalInstallLoading={modalInstallLoading}
                        onSelectVersion={selectModalVersion}
                        onRetryVersions={retryModalVersions}
                        onRetryMetadata={retryModalMetadata}
                        onInstall={installModalModpack}
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
        </div>
    );
};
