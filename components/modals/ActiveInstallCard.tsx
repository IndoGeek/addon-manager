import React, { useEffect, useRef, useState } from 'react';
import { ActiveInstallRecord, InstallProgressData } from '../types';
import { CircularProgress } from '../common/CircularProgress';
import { CancelIcon, SpinnerIcon } from '../icons';
import { formatBytes } from '../utils/constants';

const RUNNING_LABELS: Record<string, string> = {
    starting: 'Starting installation',
    download: 'Downloading',
    preparing: 'Preparing files',
    deploy: 'Deploying files',
    cancelling: 'Cancelling download',
};

export const TERMINAL_PHASES = ['complete', 'cancelled', 'failed'];

export const isActiveRunning = (
    progress: InstallProgressData | null,
): boolean =>
    progress !== null
    && !TERMINAL_PHASES.includes(progress.phase);

export const ActiveInstallCard = ({
    active,
    progress,
    onCancel,
    onDismiss,
}: {
    active: ActiveInstallRecord;
    progress: InstallProgressData | null;
    onCancel: () => void;
    onDismiss: () => void;
}) => {
    const mounted = useRef(true);

    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [active.icon_url, active.token]);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    const phase = progress?.phase ?? 'starting';
    const percent = progress?.percent ?? 0;
    const running = isActiveRunning(progress);
    const cancelling = phase === 'cancelling';
    const terminal = TERMINAL_PHASES.includes(phase);

    const outcomeLabel =
        phase === 'complete'
            ? 'Installation complete'
            : phase === 'cancelled'
                ? 'Download cancelled'
                : phase === 'failed'
                    ? 'Installation failed'
                    : null;

    const downloadMeta =
        phase === 'download'
        && progress?.downloaded_bytes !== undefined
        && progress?.downloaded_bytes !== null
        && progress?.total_bytes !== undefined
        && progress?.total_bytes !== null
            ? `${formatBytes(progress.downloaded_bytes)} / ${formatBytes(progress.total_bytes)}`
            : null;

    const initial = active.name.trim().charAt(0).toUpperCase() || '?';

    return (
        <article className="modpackinstaller-installed-item modpackinstaller-active-item">
            {active.icon_url && !failed ? (
                <img
                    src={active.icon_url}
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
            ) : (
                <div className="modpackinstaller-installed-image modpackinstaller-installed-image--fallback"
                    aria-hidden="true"
                >
                    {initial}
                </div>
            )}

            <div className="modpackinstaller-installed-item-content">
                <div className="modpackinstaller-installed-item-title">
                    <h4 title={active.name}>{active.name}</h4>

                    <span className="modpackinstaller-catalog-card-provider">
                        {active.provider}
                    </span>
                </div>

                <div className="modpackinstaller-pill-row">
                    <span className="modpackinstaller-pill">
                        {active.version}
                    </span>
                </div>

                <p
                    className={`modpackinstaller-installed-meta${
                        terminal
                            ? ` modpackinstaller-active-note modpackinstaller-active-note--${phase}`
                            : ''
                    }`}
                    role={terminal ? 'status' : undefined}
                >
                    {terminal
                        ? (progress?.message || outcomeLabel)
                        : `${RUNNING_LABELS[phase]
                            ?? 'Installing'} ...${downloadMeta ? `  ${downloadMeta}` : ''}`}
                </p>
            </div>

            <div className="modpackinstaller-active-actions">
                {cancelling ? (
                    <div className="modpackinstaller-circular modpackinstaller-circular--idle"
                        role="status"
                    >
                        <SpinnerIcon />
                    </div>
                ) : (
                    <CircularProgress
                        percent={terminal ? (phase === 'complete' ? 100 : 0) : percent}
                        label={terminal
                            ? (phase === 'complete' ? '100%' : '0%')
                            : undefined}
                    />
                )}

                {running && !cancelling && (
                    <button
                        type="button"
                        className="modpackinstaller-icon-button modpackinstaller-icon-button--red modpackinstaller-cancel-button"
                        onClick={onCancel}
                        aria-label="Cancel download"
                        title="Cancel download"
                    >
                        <CancelIcon />
                        <span>Cancel</span>
                    </button>
                )}

                {running && cancelling && (
                    <span className="modpackinstaller-active-cancelling">
                        Cancelling ...
                    </span>
                )}

                {terminal && (
                    <button
                        type="button"
                        className="modpackinstaller-active-dismiss"
                        onClick={onDismiss}
                    >
                        Dismiss
                    </button>
                )}
            </div>
        </article>
    );
};