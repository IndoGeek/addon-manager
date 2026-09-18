import {
    CatalogContentType,
    CatalogItem,
    CatalogVersion,
    CardTag,
} from '../types';

export const API_BASE = '/api/client/extensions/modpackinstaller';
export const DEFAULT_PROVIDER = 'modrinth';
export const DEFAULT_CONTENT_TYPE: CatalogContentType = 'modpacks';
export const PAGE_LIMIT = 20;
export const VIEW_STORAGE_KEY = 'modpackinstaller-view';
export const CONTENT_TYPE_STORAGE_KEY = 'modpackinstaller-content-type';

/** Toolbar segment-bar options, in display order. */
export const CONTENT_TYPE_OPTIONS: Array<{
    value: CatalogContentType;
    label: string;
}> = [
    { value: 'modpacks', label: 'Modpacks' },
    { value: 'mods', label: 'Mods' },
    { value: 'plugins', label: 'Plugins' },
    { value: 'resourcepacks', label: 'Resource Packs' },
    { value: 'datapacks', label: 'Datapacks' },
    { value: 'shaders', label: 'Shaders' },
];

export const isCatalogContentType = (
    value: unknown,
): value is CatalogContentType =>
    CONTENT_TYPE_OPTIONS.some((option) => option.value === value);

/** Toolbar tab → backend catalog content type. */
const BACKEND_CONTENT_TYPES: Record<CatalogContentType, string> = {
    modpacks: 'modpack',
    mods: 'mod',
    plugins: 'plugin',
    resourcepacks: 'resourcepack',
    datapacks: 'datapack',
    shaders: 'shader',
};

export const backendContentType = (type: CatalogContentType): string =>
    BACKEND_CONTENT_TYPES[type];

/**
 * Whether a tab installs a single downloadable file (mods, plugins,
 * datapacks, resource packs, shaders) instead of a whole modpack. These
 * share the version window (loader + Minecraft version) and the
 * simple-content install endpoint.
 */
export const isSingleFileContentType = (
    type: CatalogContentType,
): boolean => backendContentType(type) !== 'modpack';

/**
 * Tabs each provider's segment bar shows. Both providers now serve every
 * content type (CurseForge via its own per-type classes: Bukkit Plugins,
 * Mods, Resource Packs, Data Packs, Shaders, Modpacks), so the bar is the
 * full list and `providerSupportedContentTypes` decides what is enabled.
 * A provider that serves only some types can override this map.
 */
const PROVIDER_VISIBLE_TABS: Record<string, CatalogContentType[]> = {};

export const visibleContentTypes = (
    provider: string,
): CatalogContentType[] =>
    PROVIDER_VISIBLE_TABS[provider]
    ?? CONTENT_TYPE_OPTIONS.map((option) => option.value);

/**
 * Which content types the given provider can actually serve, from the
 * capabilities advertised by the backend. Falls back to modpacks-only
 * when the provider does not advertise anything (older payload).
 */
export const providerSupportedContentTypes = (
    capabilities: { content_types?: string[] } | undefined,
): string[] =>
    capabilities?.content_types
        && Array.isArray(capabilities.content_types)
        && capabilities.content_types.length > 0
        ? capabilities.content_types
        : ['modpack'];

/**
 * Lower-case noun used inside user-facing messages, e.g. "Unable to update
 * the modpack." An unknown kind reads as a generic addon so a message never
 * names the wrong content type.
 */
export const contentNoun = (kind: string | null | undefined): string => {
    switch (kind) {
        case 'modpack':
            return 'modpack';
        case 'mod':
            return 'mod';
        case 'plugin':
            return 'plugin';
        case 'datapack':
            return 'datapack';
        case 'resourcepack':
            return 'resource pack';
        case 'shader':
            return 'shader';
        default:
            return 'addon';
    }
};

/** Accessible label for a per-addon action, e.g. "Update resource pack". */
export const contentActionLabel = (
    action: string,
    kind: string | null | undefined,
): string => `${action} ${contentNoun(kind)}`;

/**
 * Human label for the kind of content an install record or downloaded file
 * is: a datapack can be installed next to a mod, and the card should say
 * which one it is. Unknown kinds render no pill rather than a wrong one.
 */
export const contentKindLabel = (
    kind: string | null | undefined,
): string | null => {
    switch (kind) {
        case 'modpack':
            return 'Modpack';
        case 'mod':
            return 'Mod';
        case 'plugin':
            return 'Plugin';
        case 'datapack':
            return 'Datapack';
        case 'resourcepack':
            return 'Resource Pack';
        case 'shader':
            return 'Shader';
        default:
            return null;
    }
};

export const activeInstallStorageKey = (server: string): string =>
    `modpackinstaller-active-install-${server}`;

export const SORT_OPTIONS: Array<{ value: string; label: string }> = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Most downloads' },
    { value: 'follows', label: 'Most follows' },
    { value: 'newest', label: 'Newest' },
    { value: 'updated', label: 'Recently updated' },
];

export const EXTENSION_VERSION = "0.47.1";

/** Page-size options for the catalog "Stack" dropdown. */
export const STACK_OPTIONS: Array<{ value: string; label: string }> = [
    { value: '10', label: '10 stack' },
    { value: '20', label: '20 stack' },
    { value: '30', label: '30 stack' },
];

export const STACK_GRID_SIZE = 44;

export const DEFAULT_STACK = '10';

export const ENVIRONMENT_OPTIONS: Array<{
    value: string;
    label: string;
}> = [
    { value: 'client', label: 'Client' },
    { value: 'server', label: 'Server' },
    { value: 'client-and-server', label: 'Client + Server' },
];

export const getServerIdentifier = (): string | null => {
    const match = window.location.pathname.match(
        /^\/server\/([^/]+)/,
    );
    return match?.[1] ?? null;
};

export const formatCount = (value: number): string => {
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

export const formatDate = (value: string): string => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
};

export const formatBytes = (value: number | null): string => {
    if (value === null || !Number.isFinite(value) || value < 0) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let index = 0;
    let size = value;

    while (size >= 1024 && index < units.length - 1) {
        size /= 1024;
        index++;
    }

    const digits =
        index === 0 ? 0 : size >= 100 ? 0 : size >= 10 ? 1 : 2;

    return `${size.toFixed(digits)} ${units[index]}`;
};

export const formatUpdated = (value: string): string => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
    });
};

export const titleCaseTag = (value: string): string => {
    const special: Record<string, string> = {
        neoforge: 'NeoForge',
        client: 'Client',
        server: 'Server',
        'client-and-server': 'Client/Server',
    };

    if (special[value]) {
        return special[value];
    }

    return value
        .split('-')
        .map(
            (part) =>
                part.charAt(0).toUpperCase() + part.slice(1),
        )
        .join(' ');
};

export const buildCardTags = (item: CatalogItem): CardTag[] => {
    const tags: CardTag[] = [];

    const push = (value: string, loader: boolean): void => {
        if (!tags.some((tag) => tag.label === value)) {
            tags.push({ label: value, loader });
        }
    };

    if (item.environment) {
        push(item.environment, false);
    }

    item.categories.forEach((category) => push(category, false));
    item.loaders.forEach((loader) => push(loader, true));

    return tags;
};

export const versionLabel = (version: CatalogVersion): string => {
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

export const uniqueSorted = (
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
