import React, { useEffect, useRef, useState } from 'react';
import { InstallRecordData } from '../types';

export const InstalledModpackImage = ({
    record,
}: {
    record: InstallRecordData;
}) => {
    const mounted = useRef(true);

    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [record.icon_url, record.id]);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    const initial =
        record.display_name.trim().charAt(0).toUpperCase() || '?';

    if (!record.icon_url || failed) {
        return (
            <div
                className="modpackinstaller-installed-image modpackinstaller-installed-image--fallback"
                aria-hidden="true"
            >
                {initial}
            </div>
        );
    }

    return (
        <img
            src={record.icon_url}
            alt=""
            className="modpackinstaller-installed-image"
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
