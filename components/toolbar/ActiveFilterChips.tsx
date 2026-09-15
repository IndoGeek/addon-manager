import React from 'react';
import { CatalogFilters, MultiFilterKey } from '../types';
import { ENVIRONMENT_OPTIONS } from '../utils/constants';

interface ActiveFilterChipsProps {
    filters: CatalogFilters;
    onToggleListValue: (key: MultiFilterKey, value: string) => void;
    onToggleEnvironment: (value: string) => void;
    searching: boolean;
}

export const buildActiveChips = (
    filters: CatalogFilters,
    onToggleListValue: (key: MultiFilterKey, value: string) => void,
    onToggleEnvironment: (value: string) => void,
): Array<{
    key: string;
    label: string;
    onRemove: () => void;
}> => {
    const chips: Array<{
        key: string;
        label: string;
        onRemove: () => void;
    }> = [];

    filters.categories.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        chips.push({
            key: `category:${value}`,
            label: `Category: ${value}`,
            onRemove: () => onToggleListValue('categories', value),
        });
    });

    filters.gameVersions.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        chips.push({
            key: `game_version:${value}`,
            label: `MC ${value}`,
            onRemove: () => onToggleListValue('gameVersions', value),
        });
    });

    filters.loaders.forEach((value) => {
        if (value.trim() === '') {
            return;
        }

        chips.push({
            key: `loader:${value}`,
            label: `Loader: ${value}`,
            onRemove: () => onToggleListValue('loaders', value),
        });
    });

    if (filters.environment !== '') {
        const environmentLabel =
            ENVIRONMENT_OPTIONS.find(
                (option) => option.value === filters.environment,
            )?.label ?? filters.environment;

        chips.push({
            key: `environment:${filters.environment}`,
            label: `Environment: ${environmentLabel}`,
            onRemove: () => onToggleEnvironment(filters.environment),
        });
    }

    return chips;
};

export const ActiveFilterChips = ({
    filters,
    onToggleListValue,
    onToggleEnvironment,
    searching,
}: ActiveFilterChipsProps) => {
    const chips = buildActiveChips(
        filters,
        onToggleListValue,
        onToggleEnvironment,
    );

    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="modpackinstaller-active-filters">
            {chips.map((chip) => (
                <span
                    className="modpackinstaller-chip"
                    key={chip.key}
                >
                    {chip.label}

                    <button
                        type="button"
                        aria-label={`Remove ${chip.label}`}
                        onClick={chip.onRemove}
                        disabled={searching}
                    >
                        &times;
                    </button>
                </span>
            ))}
        </div>
    );
};
