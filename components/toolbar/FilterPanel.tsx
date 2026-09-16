import React from 'react';
import { CatalogFilters, MultiFilterKey, ProviderCapabilities, ProviderFacets } from '../types';
import { CheckIcon, EraserIcon } from '../icons';

interface FilterPanelProps {
    capabilities: ProviderCapabilities | null;
    facets: ProviderFacets;
    filters: CatalogFilters;
    onToggleListValue: (key: MultiFilterKey, value: string) => void;
    onReset: () => void;
    onClose: () => void;
    activeFilterCount: number;
    searching: boolean;
}

export const FilterPanel = ({
    capabilities,
    facets,
    filters,
    onToggleListValue,
    onReset,
    onClose,
    activeFilterCount,
    searching,
}: FilterPanelProps) => {
    return (
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
                                        <button
                                            type="button"
                                            className={`modpackinstaller-filter-option${
                                                filters.categories.includes(
                                                    value,
                                                )
                                                    ? ' modpackinstaller-filter-option--active'
                                                    : ''
                                            }`}
                                            key={value}
                                            aria-pressed={filters.categories.includes(
                                                value,
                                            )}
                                            onClick={() =>
                                                onToggleListValue(
                                                    'categories',
                                                    value,
                                                )
                                            }
                                        >
                                            {value}
                                        </button>
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
                                        <button
                                            type="button"
                                            className={`modpackinstaller-filter-option${
                                                filters.gameVersions.includes(
                                                    value,
                                                )
                                                    ? ' modpackinstaller-filter-option--active'
                                                    : ''
                                            }`}
                                            key={value}
                                            aria-pressed={filters.gameVersions.includes(
                                                value,
                                            )}
                                            onClick={() =>
                                                onToggleListValue(
                                                    'gameVersions',
                                                    value,
                                                )
                                            }
                                        >
                                            {value}
                                        </button>
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
                                        <button
                                            type="button"
                                            className={`modpackinstaller-filter-option${
                                                filters.loaders.includes(
                                                    value,
                                                )
                                                    ? ' modpackinstaller-filter-option--active'
                                                    : ''
                                            }`}
                                            key={value}
                                            aria-pressed={filters.loaders.includes(
                                                value,
                                            )}
                                            onClick={() =>
                                                onToggleListValue(
                                                    'loaders',
                                                    value,
                                                )
                                            }
                                        >
                                            {value}
                                        </button>
                                    ),
                                )}
                            </div>
                        </fieldset>
                    )}
            </div>

            <div className="modpackinstaller-filter-actions">
                <button
                    type="button"
                    className="modpackinstaller-filter-apply"
                    onClick={onClose}
                    aria-label="Apply filters"
                    title="Apply filters"
                >
                    <CheckIcon />
                </button>

                <button
                    type="button"
                    className="modpackinstaller-filter-reset"
                    onClick={onReset}
                    disabled={
                        activeFilterCount === 0
                        || searching
                    }
                    aria-label="Clear filters"
                    title="Clear filters"
                >
                    <EraserIcon />
                </button>
            </div>
        </div>
    );
};
