import type React from 'react';

export interface ManualDownloadInfo {
    provider: string;
    project_name: string;
    project_url: string | null;
    file_name: string;
    version: string;
    download_url: string | null;
    reason: string;
}

export interface ModpackMetadata {
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

export interface MetadataResponse {
    data: ModpackMetadata;
}

export interface InstallationResult {
    total_files: number;
    created: number;
    overwritten: number;
    backed_up: number;
}

export interface InstallResponse {
    data: InstallationResult & {
        /** How many files the install run placed (main mod + dependencies). */
        installed_count?: number;
    };
}

/** Live state of one file inside a multi-file download run. */
export interface InstallProgressFile {
    /** Catalog kind of this file (mod, plugin, datapack, ...). */
    kind?: string | null;
    downloaded_bytes: number | null;
    total_bytes: number | null;
    state: 'downloading' | 'done' | 'failed';
}

export interface InstallProgressData {
    phase: string;
    percent: number;
    indeterminate: boolean;
    downloaded_bytes?: number | null;
    total_bytes?: number | null;
    deployed_files?: number | null;
    total_files?: number | null;
    /** 1-based index of the file being downloaded in a multi-file run. */
    file_index?: number | null;
    /** How many files the current install run downloads in total. */
    file_count?: number | null;
    /**
     * Per-file state of a multi-file run, in download order. Every file
     * downloads at once, so each entry carries its own byte counters.
     */
    files?: InstallProgressFile[] | null;
    /**
     * Ordered steps of a multi-phase install, each with its own state and
     * progress. Modpack runs walk several (fetch archive, extract it, read
     * the manifest, fetch the mods it lists, deploy).
     */
    stages?: InstallProgressStage[] | null;
    message?: string | null;
    /** Server-side unix timestamp of the last progress write. */
    updated_at?: number | null;
}

export interface InstallProgressResponse {
    data: InstallProgressData;
}

/** One labeled step of a multi-phase install. */
export interface InstallProgressStage {
    key: string;
    label: string;
    state: 'pending' | 'active' | 'done';
    /** This step's own progress; null while it has not started measuring. */
    percent: number | null;
    downloaded_bytes?: number | null;
    total_bytes?: number | null;
    /** Item counts when the step walks a list (the mods a manifest lists). */
    current?: number | null;
    total?: number | null;
}

/** One file a single install run downloads (the main content + dependencies). */
export interface ActiveInstallFile {
    name: string;
    icon_url: string | null;
    /** True for a recommended dependency installed alongside the main file. */
    dependency: boolean;
    /** Catalog kind of this file (mod, plugin, datapack, ...). */
    kind?: string | null;
}

export interface ActiveInstallRecord {
    server: string;
    token: string;
    provider: string;
    name: string;
    version: string;
    icon_url: string | null;
    source: string;
    started_at: string;
    /** Optional selection pills for single-file installs (mods). */
    mc_version?: string | null;
    loader?: string | null;
    /** Catalog kind of the entry: modpack, mod, plugin, datapack, ... */
    kind?: string | null;
    /**
     * Whether this run installs new content or replaces an installed one, so
     * its messages say "updated" instead of "installed".
     */
    mode?: 'install' | 'update';
    /**
     * Every file this run installs, in download order. Single-file runs and
     * modpack installs leave this empty and render one card.
     */
    files?: ActiveInstallFile[];
}

export interface RecordIntegrity {
    status: 'ok' | 'degraded';
    missing: string[];
    missing_count: number;
}

export interface InstallRecordData {
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
    icon_url: string | null;
    installed_at: string;
    updated_at: string;
    status: string;
    /** 'content' = single-file install (mods); 'modpack' = full archive. */
    content_type?: string;
    /**
     * Which catalog kind this record is: modpack, mod, plugin, datapack,
     * resourcepack, or shader.
     */
    content_kind?: string;
    ownership: {
        created: string[];
        overwritten: string[];
    };
    integrity?: RecordIntegrity;
}

export interface InstalledModpacksResponse {
    data: InstallRecordData[];
}

export interface UninstallResponse {
    data: {
        id: string;
        display_name: string;
        version: string;
        removed: number;
        missing: number;
    };
}

export interface UpdateResponse {
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

export interface RestoreResponse {
    data: {
        id: string;
        display_name: string;
        version: string;
        restored: number;
        requested: number;
    };
}

export interface StatusMessage {
    kind: 'error' | 'info' | 'success';
    message: string;
}

export interface CatalogDescriptionData {
    provider: string;
    project: string;
    html: string;
}

export interface CatalogDescriptionResponse {
    data: CatalogDescriptionData;
}

export interface CatalogItem {
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
    author: string | null;
    updated_at: string | null;
    banner_url: string | null;
    environment: string | null;
}

export interface CatalogPagination {
    page: number;
    limit: number;
    total: number;
    total_pages: number;
    has_next: boolean;
    has_previous: boolean;
}

export interface CatalogVersion {
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
    file_size: number | null;
}

export interface CatalogVersionsResponse {
    data: {
        provider: string;
        filters: {
            game_versions: string[];
            loaders: string[];
        };
        versions: CatalogVersion[];
    };
}

/** A mod version entry with loader/game-version options and dependencies. */
export interface ModVersion {
    version_id: string;
    version_number: string;
    loaders: string[];
    game_versions: string[];
    date_published: string | null;
    downloads: number | null;
    file_size: number | null;
    source: string;
    dependencies: ModDependency[];
}

/** A recommended dependency of a mod version. */
export interface ModDependency {
    project_id: string;
    title: string;
    /** Provider slug, used to fetch the dependency's own versions. */
    slug: string | null;
    icon_url: string | null;
    type: string;
}

export interface ModVersionsResponse {
    data: {
        provider: string;
        project: string;
        versions: ModVersion[];
        /**
         * Why a project has no installable versions when it does have files:
         * `distribution_disabled` means the author turned off automated
         * downloads on CurseForge, so nothing can be fetched for them.
         */
        unavailable_reason?: 'distribution_disabled' | null;
    };
}

export interface CatalogResponseData {
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

export interface CatalogResponse {
    data: CatalogResponseData;
}

export interface ProviderCapabilities {
    query: boolean;
    game_versions: boolean;
    loaders: boolean;
    categories: boolean;
    environment: boolean;
    sort: boolean;
    /** Backend content types this provider can actually serve. */
    content_types?: string[];
}

export interface ProviderFacets {
    game_versions: string[];
    loaders: string[];
    categories: string[];
    environments: string[];
}

export interface CatalogProviderOption {
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

export interface ProvidersResponse {
    data: {
        providers: CatalogProviderOption[];
        default_provider: string;
        default_sort?: string;
        pagination: {
            default_page: number;
            default_limit: number;
        };
    };
}

export interface CatalogFilters {
    provider: string;
    query: string;
    gameVersions: string[];
    loaders: string[];
    categories: string[];
    environment: string;
    sort: string;
    stack: string;
    page: number;
}

export type MultiFilterKey = 'gameVersions' | 'loaders' | 'categories';

export interface CardTag {
    label: string;
    loader: boolean;
}

/** Catalog content types shown as the segment bar in the toolbar. */
export type CatalogContentType =
    | 'modpacks'
    | 'mods'
    | 'plugins'
    | 'resourcepacks'
    | 'datapacks'
    | 'shaders';

export interface DropdownOption {
    value: string;
    label: string;
    detail?: string;
    disabled?: boolean;
    /** Optional icon rendered before the label. */
    icon?: React.ReactNode;
}
