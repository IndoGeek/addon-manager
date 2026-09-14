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
    data: InstallationResult;
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
    ownership: {
        created: string[];
        overwritten: string[];
    };
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

export interface StatusMessage {
    kind: 'error' | 'info' | 'success';
    message: string;
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
    page: number;
}

export type MultiFilterKey = 'gameVersions' | 'loaders' | 'categories';

export interface CardTag {
    label: string;
    loader: boolean;
}

export interface DropdownOption {
    value: string;
    label: string;
    detail?: string;
    disabled?: boolean;
}
