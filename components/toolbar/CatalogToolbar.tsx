import React, { useEffect, useRef } from 'react';
import { Dropdown } from '../common/Dropdown';
import { FilterIcon, GridIcon, ListIcon, PackageIcon, SearchIcon, StackIcon } from '../icons';
import { STACK_OPTIONS } from '../utils/constants';
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

    stackValue: string;
    onStackChange: (value: string) => void;

    filtersOpen: boolean;
    onToggleFilters: () => void;
    activeFilterCount: number;

    view: 'grid' | 'list';
    onViewChange: (view: 'grid' | 'list') => void;

    installedCount: number | null;
    badgeCount: number | null;
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
    stackValue,
    onStackChange,
    filtersOpen,
    onToggleFilters,
    activeFilterCount,
    view,
    onViewChange,
    installedCount,
    badgeCount,
    onOpenInstalled,
}: ToolbarProps) => {
    const searchInputRef = useRef<HTMLInputElement | null>(null);

    // Scroll positions (page and extension container) captured when the
    // input gains focus. The virtual keyboard opening itself fires a small
    // scroll on some mobile browsers, so the dismiss threshold must be
    // large enough to ignore that.
    const focusScrollY = useRef<{ windowY: number; rootTop: number }>({
        windowY: 0,
        rootTop: 0,
    });

    useEffect(() => {
        const onScroll = (event: Event) => {
            const input = searchInputRef.current;

            if (!input || document.activeElement !== input) {
                return;
            }

            const target = event.target;
            const isDocument = target === document;
            const element = isDocument ? null : (target as Element);

            // Only the page itself or the extension's own container can
            // meaningfully move the input; a tiny inner scroller (a dropdown
            // menu, for instance) must not dismiss the keyboard.
            if (!isDocument && !element?.closest('.modpackinstaller-root')) {
                return;
            }

            const before = isDocument
                ? (focusScrollY.current.windowY ?? 0)
                : (focusScrollY.current.rootTop ?? 0);

            const after = isDocument
                ? window.scrollY
                : (element as Element).scrollTop;

            // The keyboard opening itself fires a small scroll on some
            // mobile browsers; anything beyond that threshold means the
            // user moved away from the input, which is the moment to
            // dismiss the keyboard.
            if (Math.abs(after - before) > 40) {
                input.blur();
            }
        };

        // Capture phase on document: scroll events do not bubble, and the
        // panel may scroll inside an inner container rather than the window.
        document.addEventListener('scroll', onScroll, {
            capture: true,
            passive: true,
        });

        return () => document.removeEventListener('scroll', onScroll, {
            capture: true,
        });
    }, []);

    const submitAndDismissKeyboard = () => {
        searchInputRef.current?.blur();

        onQuerySubmit();
    };

    return (
        <div className="modpackinstaller-browser-toolbar">
            <div className="modpackinstaller-search">
                <div className="modpackinstaller-controls-row">
                    <div className="modpackinstaller-search-box">
                        <input
                            ref={searchInputRef}
                            id="modpackinstaller-search"
                            type="search"
                            value={query}
                            onChange={(event) => onQueryChange(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    submitAndDismissKeyboard();
                                }
                            }}
                            onFocus={(event) => {
                                // Remember where the page and the extension
                                // container were when focus landed, so the
                                // scroll-away dismissal compares like with
                                // like.
                                const container = (
                                    event.target as HTMLElement
                                ).closest('.modpackinstaller-root');

                                focusScrollY.current = {
                                    windowY: window.scrollY,
                                    rootTop: container?.scrollTop ?? 0,
                                };
                            }}
                            // Never disabled while searching: disabling a
                            // focused input dismisses the mobile keyboard
                            // mid-typing. The debounced search simply keeps
                            // running underneath whatever is typed next.
                            placeholder="Search modpacks"
                            aria-label="Search modpacks"
                            aria-busy={catalogBusy}
                        />

                        {query.trim() !== '' && (
                            <button
                                type="button"
                                className="modpackinstaller-search-clear"
                                onClick={onQueryClear}
                                // Keep the keyboard open: preventDefault on
                                // mousedown stops the input losing focus.
                                onMouseDown={(event) => event.preventDefault()}
                                aria-label="Clear search"
                                disabled={catalogBusy}
                            >
                                &times;
                            </button>
                        )}

                        <button
                            type="button"
                            className="modpackinstaller-search-go"
                            onClick={submitAndDismissKeyboard}
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

                        {badgeCount !== null && badgeCount > 0 && (
                            <span
                                className="modpackinstaller-badge"
                                aria-hidden="true"
                            >
                                {badgeCount}
                            </span>
                        )}
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

                <Dropdown
                    id="modpackinstaller-stack"
                    label="Stack"
                    value={stackValue}
                    onChange={onStackChange}
                    options={STACK_OPTIONS}
                    disabled={catalogBusy}
                    compact
                    icon={<StackIcon />}
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
