import { CatalogItem, CatalogVersion, CardTag } from '../types';

export const API_BASE = '/api/client/extensions/modpackinstaller';
export const DEFAULT_PROVIDER = 'modrinth';
export const PAGE_LIMIT = 20;
export const VIEW_STORAGE_KEY = 'modpackinstaller-view';

export const activeInstallStorageKey = (server: string): string =>
    `modpackinstaller-active-install-${server}`;

export const SORT_OPTIONS: Array<{ value: string; label: string }> = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Most downloads' },
    { value: 'follows', label: 'Most follows' },
    { value: 'newest', label: 'Newest' },
    { value: 'updated', label: 'Recently updated' },
];

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
