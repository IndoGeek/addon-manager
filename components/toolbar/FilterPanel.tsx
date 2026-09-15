import React from 'react';
import { CatalogFilters, MultiFilterKey, ProviderCapabilities, ProviderFacets } from '../types';
import { ENVIRONMENT_OPTIONS } from '../utils/constants';

interface FilterPanelProps {
    capabilities: ProviderCapabilities | null;
    facets: ProviderFacets;
    filters: CatalogFilters;
    onToggleListValue: (key: MultiFilterKey, value: string) => void;
    onToggleEnvironment: (value: string) => void;
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
    onToggleEnvironment,
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
                                        <label
                                            className="modpackinstaller-filter-option"
                                            key={value}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={filters.categories.includes(
                                                    value,
                                                )}
                                                onChange={() =>
                                                    onToggleListValue(
                                                        'categories',
                                                        value,
                                                    )
                                                }
                                            />

                                            <span>
                                                {value}
                                            </span>
                                        </label>
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
                                        <label
                                            className="modpackinstaller-filter-option"
                                            key={value}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={filters.gameVersions.includes(
                                                    value,
                                                )}
                                                onChange={() =>
                                                    onToggleListValue(
                                                        'gameVersions',
                                                        value,
                                                    )
                                                }
                                            />

                                            <span>
                                                {value}
                                            </span>
                                        </label>
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
                                        <label
                                            className="modpackinstaller-filter-option"
                                            key={value}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={filters.loaders.includes(
                                                    value,
                                                )}
                                                onChange={() =>
                                                    onToggleListValue(
                                                        'loaders',
                                                        value,
                                                    )
                                                }
                                            />

                                            <span>
                                                {value}
                                            </span>
                                        </label>
                                    ),
                                )}
                            </div>
                        </fieldset>
                    )}

                {capabilities?.environment !== false
                    && facets.environments.length > 0 && (
                        <fieldset className="modpackinstaller-filter-group">
                            <legend>
                                Environment
                            </legend>

                            <div className="modpackinstaller-filter-options">
                                {ENVIRONMENT_OPTIONS.map(
                                    (option) => (
                                        <label
                                            className="modpackinstaller-filter-option"
                                            key={option.value}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={
                                                    filters.environment
                                                    === option.value
                                                }
                                                onChange={() =>
                                                    onToggleEnvironment(
                                                        option.value,
                                                    )
                                                }
                                            />

                                            <span>
                                                {option.label}
                                            </span>
                                        </label>
                                    ),
                                )}
                            </div>
                        </fieldset>
                    )}
            </div>

            <div className="modpackinstaller-filter-actions">
                <button
                    type="button"
                    onClick={onClose}
                >
                    Done
                </button>

                <button
                    type="button"
                    onClick={onReset}
                    disabled={
                        activeFilterCount === 0
                        || searching
                    }
                >
                    Clear filters
                </button>
            </div>
        </div>
    );
};
