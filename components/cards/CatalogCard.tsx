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
} from '../icons';

export const CatalogCard = ({
    item,
    view,
    onOpen,
    disabled,
}: {
    item: CatalogItem;
    view: 'grid' | 'list';
    onOpen: (item: CatalogItem) => void;
    disabled: boolean;
}) => {
    const tags = buildCardTags(item);

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
                            {item.provider}
                        </span>
                    </div>

                    <span className="modpackinstaller-catalog-card-author">
                        {item.author || 'Unknown author'}
                    </span>
                </div>

                <p className="modpackinstaller-catalog-card-summary">
                    {item.summary || 'No description available.'}
                </p>

                {tags.length > 0 && <PillTags tags={tags} />}

                <div className="modpackinstaller-catalog-card-stats">
                    {item.downloads !== null && (
                        <span title="Downloads">
                            <DownloadStatIcon />
                            {formatCount(item.downloads)}
                        </span>
                    )}

                    {item.follows !== null && (
                        <span title="Follows">
                            <FollowsStatIcon />
                            {formatCount(item.follows)}
                        </span>
                    )}

                    {item.updated_at && (
                        <span title="Last updated">
                            <UpdatedStatIcon />
                            {formatUpdated(item.updated_at)}
                        </span>
                    )}
                </div>

                <div className="modpackinstaller-catalog-card-actions">
                    <button
                        type="button"
                        className="modpackinstaller-card-action-open"
                        onClick={() => onOpen(item)}
                        disabled={disabled}
                    >
                        Open
                    </button>

                    {item.project_url && (
                        <a
                            className="modpackinstaller-card-action-details"
                            href={item.project_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerPolicy="no-referrer"
                        >
                            Details
                        </a>
                    )}
                </div>
            </div>
        </article>
    );
};
