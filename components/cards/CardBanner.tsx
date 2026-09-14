import React, { useEffect, useRef, useState } from 'react';
import { CatalogItem } from '../types';

export const CardBanner = ({ item }: { item: CatalogItem }) => {
    const mounted = useRef(true);

    const sources = [
        item.banner_url,
        item.icon_url,
    ].filter(
        (source): source is string =>
            typeof source === 'string' && source.length > 0,
    );

    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        setAttempt(0);
    }, [item.banner_url, item.icon_url, item.provider_project_id]);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    const source = sources[attempt];

    if (!source) {
        return (
            <div
                className="modpackinstaller-card-banner modpackinstaller-card-banner--fallback"
                aria-hidden="true"
            />
        );
    }

    return (
        <img
            src={source}
            alt=""
            className="modpackinstaller-card-banner"
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => {
                if (mounted.current) {
                    setAttempt((current) => current + 1);
                }
            }}
        />
    );
};
