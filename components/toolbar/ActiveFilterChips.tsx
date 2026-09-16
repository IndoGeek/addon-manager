import React from 'react';
import { CatalogFilters, MultiFilterKey } from '../types';

interface ActiveFilterChipsProps {
    filters: CatalogFilters;
    onToggleListValue: (key: MultiFilterKey, value: string) => void;
    searching: boolean;
}

export const buildActiveChips = (
    filters: CatalogFilters,
    onToggleListValue: (key: MultiFilterKey, value: string) => void,
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

    return chips;
};

export const ActiveFilterChips = ({
    filters,
    onToggleListValue,
    searching,
}: ActiveFilterChipsProps) => {
    const chips = buildActiveChips(
        filters,
        onToggleListValue,
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
