import React from 'react';
import { Dropdown } from '../common/Dropdown';
import { FilterIcon, GridIcon, ListIcon, PackageIcon, SearchIcon } from '../icons';
import { SORT_OPTIONS } from '../utils/constants';

interface ToolbarProps {
    query: string;
    onQueryChange: (value: string) => void;
    onQuerySubmit: () => void;
    onQueryClear: () => void;
    catalogBusy: boolean;

    providerValue: string;
    onProviderChange: (value: string) => void;
    providerOptions: Array<{
        value: string;
        label: string;
        detail?: string;
        disabled?: boolean;
    }>;

    sortValue: string;
    onSortChange: (value: string) => void;

    filtersOpen: boolean;
    onToggleFilters: () => void;
    activeFilterCount: number;

    view: 'grid' | 'list';
    onViewChange: (view: 'grid' | 'list') => void;

    installedCount: number | null;
    onOpenInstalled: () => void;
}

export const CatalogToolbar = ({
    query,
    onQueryChange,
    onQuerySubmit,
    onQueryClear,
    catalogBusy,
    providerValue,
    onProviderChange,
    providerOptions,
    sortValue,
    onSortChange,
    filtersOpen,
    onToggleFilters,
    activeFilterCount,
    view,
    onViewChange,
    installedCount,
    onOpenInstalled,
}: ToolbarProps) => {
    return (
        <div className="modpackinstaller-browser-toolbar">
            <div className="modpackinstaller-search">
                <div className="modpackinstaller-controls-row">
                    <div className="modpackinstaller-search-box">
                        <input
                            id="modpackinstaller-search"
                            type="search"
                            value={query}
                            onChange={(event) => onQueryChange(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    onQuerySubmit();
                                }
                            }}
                            placeholder="Search modpacks"
                            disabled={catalogBusy}
                            aria-label="Search modpacks"
                        />

                        {query.trim() !== '' && (
                            <button
                                type="button"
                                className="modpackinstaller-search-clear"
                                onClick={onQueryClear}
                                aria-label="Clear search"
                                disabled={catalogBusy}
                            >
                                &times;
                            </button>
                        )}

                        <button
                            type="button"
                            className="modpackinstaller-search-go"
                            onClick={onQuerySubmit}
                            disabled={catalogBusy}
                            aria-label="Search"
                        >
                            <SearchIcon />
                        </button>
                    </div>

                    <button
                        type="button"
                        className="modpackinstaller-installed-toggle"
                        onClick={onOpenInstalled}
                        aria-label={
                            installedCount !== null && installedCount > 0
                                ? `Installed modpacks (${installedCount})`
                                : 'Installed modpacks'
                        }
                        title={
                            installedCount !== null && installedCount > 0
                                ? `Installed modpacks (${installedCount})`
                                : 'Installed modpacks'
                        }
                    >
                        <PackageIcon />
                    </button>
                </div>
            </div>

            <div className="modpackinstaller-toolbar-row">
                <Dropdown
                    id="modpackinstaller-provider"
                    label="Provider"
                    value={providerValue}
                    onChange={onProviderChange}
                    options={providerOptions}
                    disabled={catalogBusy}
                />

                <Dropdown
                    id="modpackinstaller-sort"
                    label="Sort"
                    value={sortValue}
                    onChange={onSortChange}
                    options={SORT_OPTIONS}
                    disabled={catalogBusy}
                />

                <button
                    type="button"
                    className={`modpackinstaller-filters-toggle${
                        filtersOpen
                            ? ' modpackinstaller-filters-toggle--active'
                            : ''
                    }`}
                    aria-label="Filters"
                    title={activeFilterCount > 0 ? `Filters (${activeFilterCount})` : 'Filters'}
                    aria-expanded={filtersOpen}
                    onClick={onToggleFilters}
                    disabled={catalogBusy}
                >
                    <FilterIcon />
                    {activeFilterCount > 0 && (
                        <span className="modpackinstaller-filter-count">
                            {activeFilterCount}
                        </span>
                    )}
                </button>

                <div
                    className="modpackinstaller-view-toggle"
                    role="group"
                    aria-label="Result view"
                >
                    <button
                        type="button"
                        aria-label="Grid view"
                        title="Grid view"
                        aria-pressed={view === 'grid'}
                        className={
                            view === 'grid'
                                ? 'modpackinstaller-view-toggle--active'
                                : undefined
                        }
                        onClick={() => onViewChange('grid')}
                    >
                        <GridIcon />
                    </button>

                    <button
                        type="button"
                        aria-label="List view"
                        title="List view"
                        aria-pressed={view === 'list'}
                        className={
                            view === 'list'
                                ? 'modpackinstaller-view-toggle--active'
                                : undefined
                        }
                        onClick={() => onViewChange('list')}
                    >
                        <ListIcon />
                    </button>
                </div>
            </div>
        </div>
    );
};
