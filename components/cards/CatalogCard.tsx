import React from 'react';
import { CatalogItem } from '../types';
import { buildCardTags, formatUpdated, formatCount } from '../utils/constants';
import { ModpackIcon } from './ModpackIcon';
import { CardBanner } from './CardBanner';
import { PillTags } from './PillTags';
import {
    DownloadStatIcon,
    FollowsStatIcon,
    UpdatedStatIcon,
    OpenIcon,
    ViewIcon,
} from '../icons';

/** Icon-only Open/View pair used inside the list card's meta row. */
const ListCardActions = ({
    item,
    onOpen,
    disabled,
}: {
    item: CatalogItem;
    onOpen: (item: CatalogItem) => void;
    disabled: boolean;
}) => (
    <div className="modpackinstaller-catalog-card-actions">
        <button
            type="button"
            className="modpackinstaller-card-action-open modpackinstaller-card-action-icon"
            onClick={() => onOpen(item)}
            disabled={disabled}
            aria-label="Open modpack"
            title="Open"
        >
            <OpenIcon />
        </button>

        {item.project_url && (
            <a
                className="modpackinstaller-card-action-details modpackinstaller-card-action-icon"
                href={item.project_url}
                target="_blank"
                rel="noopener noreferrer"
                referrerPolicy="no-referrer"
                aria-label="View on provider page"
                title="View"
            >
                <ViewIcon />
            </a>
        )}
    </div>
);

export const CatalogCard = ({
    item,
    view,
    onOpen,
    disabled,
    providerLabels,
}: {
    item: CatalogItem;
    view: 'grid' | 'list';
    onOpen: (item: CatalogItem) => void;
    disabled: boolean;
    providerLabels: Record<string, string>;
}) => {
    const tags = buildCardTags(item);

    const statItems = [
        item.downloads !== null && (
            <span key="downloads" title="Downloads">
                <DownloadStatIcon />
                {formatCount(item.downloads)}
            </span>
        ),
        item.follows !== null && (
            <span key="follows" title="Follows">
                <FollowsStatIcon />
                {formatCount(item.follows)}
            </span>
        ),
        item.updated_at && (
            <span key="updated" title="Last updated">
                <UpdatedStatIcon />
                {formatUpdated(item.updated_at)}
            </span>
        ),
    ].filter(Boolean) as React.ReactNode[];

    return (
        <article
            className={`modpackinstaller-catalog-card modpackinstaller-catalog-card--${view}`}
        >
            <div className="modpackinstaller-card-media">
                {view === 'grid' && <CardBanner item={item} />}

                {view === 'list' && (
                    <div className="modpackinstaller-card-logo">
                        <ModpackIcon item={item} compact />
                    </div>
                )}
            </div>

            <div className="modpackinstaller-catalog-card-content">
                <div className="modpackinstaller-catalog-card-header">
                    <div className="modpackinstaller-catalog-card-title">
                        <h4 title={item.name}>{item.name}</h4>

                        <span className="modpackinstaller-catalog-card-provider">
                            {providerLabels[item.provider] ?? item.provider}
                        </span>
                    </div>

                    <span className="modpackinstaller-catalog-card-author">
                        {view === 'list'
                            ? `by ${item.author || 'unknown'}`
                            : (item.author || 'Unknown author')}
                    </span>
                    </div>

                <p className="modpackinstaller-catalog-card-summary">
                    {item.summary || 'No description available.'}
                </p>

                {view === 'grid' && tags.length > 0 && (
                    <PillTags tags={tags} />
                )}

                {view === 'grid' && (
                    <div className="modpackinstaller-catalog-card-stats">
                        {statItems}
                    </div>
                )}

                {view === 'list' && tags.length > 0 && (
                    <PillTags tags={tags} />
                )}

                {view === 'list' && (
                    <div className="modpackinstaller-catalog-card-meta">
                        {statItems.length > 0 && (
                            <div className="modpackinstaller-catalog-card-stats">
                                {statItems}
                            </div>
                        )}

                        {/* The two actions share the stats row, filling the
                         * free space after the last stat — no empty band. */}
                        <ListCardActions
                            item={item}
                            onOpen={onOpen}
                            disabled={disabled}
                        />
                    </div>
                )}

                {view === 'grid' && (
                    <div className="modpackinstaller-catalog-card-actions">
                        <button
                            type="button"
                            className="modpackinstaller-card-action-open"
                            onClick={() => onOpen(item)}
                            disabled={disabled}
                            aria-label="Open modpack"
                            title="Open"
                        >
                            <OpenIcon />
                            <span>Open</span>
                        </button>

                        {item.project_url && (
                            <a
                                className="modpackinstaller-card-action-details"
                                href={item.project_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                referrerPolicy="no-referrer"
                                aria-label="View on provider page"
                                title="View"
                            >
                                <ViewIcon />
                                <span>Details</span>
                            </a>
                        )}
                    </div>
                )}
            </div>
        </article>
    );
};
