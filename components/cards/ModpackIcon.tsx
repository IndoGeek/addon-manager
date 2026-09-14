import React, { useEffect, useRef, useState } from 'react';
import { CatalogItem } from '../types';

export const ModpackIcon = ({
    item,
    compact,
}: {
    item: CatalogItem;
    compact?: boolean;
}) => {
    const mounted = useRef(true);

    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [item.icon_url, item.provider_project_id]);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    if (!item.icon_url || failed) {
        const initial =
            item.name.trim().charAt(0).toUpperCase() || '?';

        return (
            <div
                className={`modpackinstaller-card-image modpackinstaller-card-image--fallback${
                    compact
                        ? ' modpackinstaller-card-image--compact'
                        : ''
                }`}
                aria-hidden="true"
            >
                {initial}
            </div>
        );
    }

    return (
        <img
            src={item.icon_url}
            alt=""
            className={`modpackinstaller-card-image${
                compact
                    ? ' modpackinstaller-card-image--compact'
                    : ''
            }`}
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => {
                if (mounted.current) {
                    setFailed(true);
                }
            }}
        />
    );
};
