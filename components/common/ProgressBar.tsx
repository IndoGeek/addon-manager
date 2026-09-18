import React from 'react';

/**
 * The extension's one progress bar: a slim track with a green fill. It is
 * shared by the whole-run card and by every per-file / per-stage row so an
 * install reads identically everywhere, whatever the provider is doing.
 *
 * A null percent means "no measurable total yet", which slides a short fill
 * instead of pretending to be at a fixed position.
 */
export const ProgressBar = ({
    percent,
    ariaLabel,
    className = '',
}: {
    percent: number | null;
    ariaLabel?: string;
    className?: string;
}) => {
    const indeterminate = percent === null;
    const value = Math.max(0, Math.min(100, percent ?? 0));

    return (
        <div
            className={`modpackinstaller-progress${
                indeterminate ? ' modpackinstaller-progress--indeterminate' : ''
            }${className ? ` ${className}` : ''}`}
            role="progressbar"
            aria-label={ariaLabel}
            aria-valuenow={indeterminate ? undefined : Math.round(value)}
            aria-valuemin={0}
            aria-valuemax={100}
        >
            <span style={{ width: indeterminate ? '35%' : `${value}%` }} />
        </div>
    );
};
