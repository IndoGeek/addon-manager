import React from 'react';

export const CircularProgress = ({
    percent,
    size = 72,
    thickness = 6,
    label,
}: {
    percent: number;
    size?: number;
    thickness?: number;
    label?: React.ReactNode;
}) => {
    const clamped = Math.max(0, Math.min(100, percent));
    const radius = (size - thickness) / 2;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference * (1 - clamped / 100);

    return (
        <div
            className="modpackinstaller-circular"
            style={{ width: size, height: size }}
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(clamped)}
        >
            <svg width={size} height={size} aria-hidden="true">
                <circle
                    className="modpackinstaller-circular-track"
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    strokeWidth={thickness}
                />
                <circle
                    className="modpackinstaller-circular-bar"
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    strokeWidth={thickness}
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    strokeLinecap="round"
                />
            </svg>

            <div className="modpackinstaller-circular-center">
                {label !== undefined
                    ? label
                    : `${Math.round(clamped)}%`}
            </div>
        </div>
    );
};