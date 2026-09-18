import React, { useEffect, useRef, useState } from 'react';
import {
    ActiveInstallFile,
    ActiveInstallRecord,
    InstallProgressData,
    InstallProgressFile,
    InstallProgressStage,
} from '../types';
import { ProgressBar } from '../common/ProgressBar';
import { CheckIcon, CancelIcon, SpinnerIcon } from '../icons';
import { contentKindLabel, formatBytes } from '../utils/constants';

const RUNNING_LABELS: Record<string, string> = {
    starting: 'Starting',
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

// Per-row state inside one install run.
type FileState = 'done' | 'active' | 'queued' | 'skipped';

const STATE_LABELS: Record<FileState, string> = {
    done: 'Done',
    active: 'In progress',
    queued: 'Waiting',
    skipped: 'Not completed',
};

// The state mark shared by every row: a tick once finished, a spinner while running, a muted dot while waiting.
const RowMark = ({ state }: { state: FileState }) => (
    <div className="modpackinstaller-active-file-mark" aria-hidden="true">
        {state === 'done' ? (
            <CheckIcon />
        ) : state === 'active' ? (
            <SpinnerIcon />
        ) : (
            <span className="modpackinstaller-active-file-dot" />
        )}
    </div>
);

// A single file inside the running install: its own card, its own state.
const ActiveFileCard = ({
    file,
    state,
    bytesLabel,
    percent,
    kind,
}: {
    file: ActiveInstallFile;
    state: FileState;
    bytesLabel: string | null;
    percent: number | null;
    /** Kind reported by the backend once this file's download starts. */
    kind?: string | null;
}) => {
    const mounted = useRef(true);

    const [failed, setFailed] = useState(false);

    useEffect(() => {
        return () => {
            mounted.current = false;
        };
    }, []);

    const initial = file.name.trim().charAt(0).toUpperCase() || '?';

    const kindLabel = contentKindLabel(kind ?? file.kind);

    const stateLabel =
        state === 'done'
            ? 'Downloaded'
            : state === 'active'
                ? `Downloading${
                      percent !== null ? ` ${percent}%` : ''
                  }${bytesLabel ? ` · ${bytesLabel}` : ''}`
                : state === 'queued'
                    ? 'Queued'
                    : 'Not installed';

    return (
        <div
            className={`modpackinstaller-installed-item modpackinstaller-active-file modpackinstaller-active-file--${state}`}
        >
            {file.icon_url && !failed ? (
                <img
                    src={file.icon_url}
                    alt=""
                    className="modpackinstaller-installed-image modpackinstaller-active-file-image"
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    onError={() => {
                        if (mounted.current) {
                            setFailed(true);
                        }
                    }}
                />
            ) : (
                <div
                    className="modpackinstaller-installed-image modpackinstaller-active-file-image modpackinstaller-installed-image--fallback"
                    aria-hidden="true"
                >
                    {initial}
                </div>
            )}

            <div className="modpackinstaller-installed-item-content">
                <div className="modpackinstaller-installed-item-title">
                    <h4 title={file.name}>{file.name}</h4>

                    {file.dependency && (
                        <span className="modpackinstaller-catalog-card-provider">
                            dependency
                        </span>
                    )}
                </div>

                {kindLabel !== null && (
                    <div className="modpackinstaller-pill-row">
                        <span className="modpackinstaller-pill modpackinstaller-pill--kind">
                            {kindLabel}
                        </span>
                    </div>
                )}

                <p
                    className={`modpackinstaller-active-file-state modpackinstaller-active-file-state--${state}`}
                >
                    {stateLabel}
                </p>

                {state === 'active' && (
                    <ProgressBar
                        percent={percent}
                        ariaLabel={`${file.name} download progress`}
                    />
                )}
            </div>

            <RowMark state={state} />
        </div>
    );
};

// One labelled step of a modpack install (fetch the archive, extract it, read the manifest, fetch the mods it...
const StageCard = ({
    stage,
    index,
    state,
}: {
    stage: InstallProgressStage;
    index: number;
    state: FileState;
}) => {
    const counts =
        stage.current !== null
        && stage.current !== undefined
        && stage.total !== null
        && stage.total !== undefined
            ? `${stage.current} / ${stage.total}`
            : null;

    const bytes =
        stage.downloaded_bytes !== null
        && stage.downloaded_bytes !== undefined
        && stage.total_bytes !== null
        && stage.total_bytes !== undefined
        && stage.total_bytes > 0
            ? `${formatBytes(stage.downloaded_bytes)} / ${formatBytes(stage.total_bytes)}`
            : null;

    // Counts ("142 / 300") ride in the title pill and the byte counters in the state line, so a counted step never...
    const detail = bytes;

    const percent = state === 'done' ? 100 : stage.percent;

    return (
        <div
            className={`modpackinstaller-installed-item modpackinstaller-active-file modpackinstaller-active-file--${state} modpackinstaller-active-stage`}
        >
            <div className="modpackinstaller-active-stage-index" aria-hidden="true">
                {index + 1}
            </div>

            <div className="modpackinstaller-installed-item-content">
                <div className="modpackinstaller-installed-item-title">
                    <h4 title={stage.label}>{stage.label}</h4>

                    {state === 'active' && counts !== null && (
                        <span className="modpackinstaller-catalog-card-provider">
                            {counts}
                        </span>
                    )}
                </div>

                <p
                    className={`modpackinstaller-active-file-state modpackinstaller-active-file-state--${state}`}
                >
                    {state === 'active' && detail !== null
                        ? `${STATE_LABELS[state]} · ${detail}`
                        : STATE_LABELS[state]}
                </p>

                {(state === 'active' || state === 'done') && (
                    <ProgressBar
                        percent={percent}
                        ariaLabel={`${stage.label} progress`}
                    />
                )}
            </div>

            <RowMark state={state} />
        </div>
    );
};

export const ActiveInstallCard = ({
    active,
    progress,
    onCancel,
    onDismiss,
    providerLabels,
}: {
    active: ActiveInstallRecord;
    progress: InstallProgressData | null;
    onCancel: () => void;
    onDismiss: () => void;
    providerLabels: Record<string, string>;
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

    // An update replaces installed content, so its card never claims a fresh install finished.
    const modeNoun = active.mode === 'update' ? 'Update' : 'Installation';

    const outcomeLabel =
        phase === 'complete'
            ? `${modeNoun} complete`
            : phase === 'cancelled'
                ? 'Download cancelled'
                : phase === 'failed'
                    ? `${modeNoun} failed`
                    : null;

    const downloadMeta =
        phase === 'download'
        && progress?.downloaded_bytes !== undefined
        && progress?.downloaded_bytes !== null
        && progress?.total_bytes !== undefined
        && progress?.total_bytes !== null
            ? `${formatBytes(progress.downloaded_bytes)} / ${formatBytes(progress.total_bytes)}`
            : null;

    // Multi-file runs (main content + selected dependencies) surface which file of the run is in flight; every...
    const fileCountMeta =
        phase === 'download'
        && progress?.file_count !== undefined
        && progress?.file_count !== null
        && progress.file_count > 1
        && progress?.file_index !== undefined
        && progress?.file_index !== null
            ? ` · file ${progress.file_index} of ${progress.file_count}`
            : null;

    const files = active.files ?? [];
    const multiFile = files.length > 1;
    const activeIndex = progress?.file_index ?? null;

    // Ordered steps the backend announced for this run (empty for content installs, which report one card per file...
    const stages = (progress?.stages ?? []).filter(
        (stage) => stage !== null && stage !== undefined,
    );

    const activeStage =
        stages.find((stage) => stage.state === 'active') ?? null;

    // Per-file counters from the concurrent engine, when it reports them.
    const reportedFiles = progress?.files ?? null;

    const hasReportedFiles =
        Array.isArray(reportedFiles)
        && reportedFiles.length === files.length;

    const fileState = (index: number): FileState => {
        if (phase === 'complete') {
            return 'done';
        }

        // The concurrent engine reports every file's own state, so a file is only "queued" when the engine has not...
        if (hasReportedFiles) {
            const reported = reportedFiles[index];

            if (reported.state === 'done') {
                return 'done';
            }

            if (reported.state === 'failed') {
                return 'skipped';
            }

            return running || cancelling ? 'active' : 'skipped';
        }

        // Sequentially streamed run: everything before the in-flight index is already on the server, everything after...
        if (activeIndex === null) {
            return phase === 'complete'
                ? 'done'
                : index === 0
                    ? 'active'
                    : 'queued';
        }

        const position = index + 1;

        if (position < activeIndex) {
            return 'done';
        }

        if (position > activeIndex) {
            return running || cancelling ? 'queued' : 'skipped';
        }

        return phase === 'failed' ? 'skipped' : 'active';
    };

    const stageState = (stage: InstallProgressStage): FileState => {
        if (phase === 'complete') {
            return 'done';
        }

        if (stage.state === 'done') {
            return 'done';
        }

        if (stage.state === 'active') {
            return running || cancelling ? 'active' : 'skipped';
        }

        return 'queued';
    };

    const percentOf = (
        downloaded: number | null,
        total: number | null,
    ): number | null =>
        downloaded !== null && total !== null && total > 0
            ? Math.min(100, Math.round((downloaded / total) * 100))
            : null;

    const filePercent = (index: number, state: FileState): number | null => {
        if (state === 'done') {
            return 100;
        }

        if (state !== 'active') {
            return null;
        }

        if (hasReportedFiles) {
            const reported: InstallProgressFile = reportedFiles[index];

            return percentOf(
                reported.downloaded_bytes,
                reported.total_bytes,
            );
        }

        return percentOf(
            progress?.downloaded_bytes ?? null,
            progress?.total_bytes ?? null,
        );
    };

    const fileBytesLabel = (index: number, state: FileState): string | null => {
        if (state !== 'active' || phase !== 'download') {
            return null;
        }

        if (hasReportedFiles) {
            const reported: InstallProgressFile = reportedFiles[index];

            if (
                reported.total_bytes !== null
                && reported.total_bytes > 0
            ) {
                return `${formatBytes(reported.downloaded_bytes)} / ${formatBytes(reported.total_bytes)}`;
            }

            return null;
        }

        return downloadMeta;
    };

    // "file X of Y" only makes sense while a run streams one file at a time.
    const streamedMeta = hasReportedFiles ? null : fileCountMeta;

    // The first file of a content run is the entry the user picked, so its kind labels the whole-run card...
    const mainKindLabel = contentKindLabel(files[0]?.kind ?? active.kind);

    // The whole-run bar shows the step in flight, so its label and its value always describe the same thing.
    const mainPercent = progress?.indeterminate
        ? null
        : (activeStage?.percent ?? percent);

    const mainLabel = activeStage !== null
        ? activeStage.label
        : phase === 'starting'
            ? `${RUNNING_LABELS.starting} ${active.mode === 'update' ? 'update' : 'installation'}`
            : (RUNNING_LABELS[phase] ?? 'Installing');

    const initial = active.name.trim().charAt(0).toUpperCase() || '?';

    return (
        <>
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
                            {providerLabels[active.provider] ?? active.provider}
                        </span>
                    </div>

                    <div className="modpackinstaller-pill-row">
                        <span className="modpackinstaller-pill">
                            {active.version}
                        </span>

                        {active.mc_version && (
                            <span className="modpackinstaller-pill">
                                {active.mc_version}
                            </span>
                        )}

                        {active.loader && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                {active.loader}
                            </span>
                        )}

                        {mainKindLabel !== null && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--kind">
                                {mainKindLabel}
                            </span>
                        )}

                        {multiFile && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                {files.length} files
                            </span>
                        )}
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
                            : `${mainLabel} ...${!multiFile && downloadMeta ? `  ${downloadMeta}` : ''}${streamedMeta ?? ''}`}
                    </p>

                    {!terminal && (
                        <div className="modpackinstaller-active-bar-row">
                            <ProgressBar
                                percent={mainPercent}
                                ariaLabel={`${active.name} ${
                                    active.mode === 'update'
                                        ? 'update'
                                        : 'install'
                                } progress`}
                            />

                            {mainPercent !== null && (
                                <span className="modpackinstaller-active-bar-value">
                                    {mainPercent}%
                                </span>
                            )}
                        </div>
                    )}
                </div>

                <div className="modpackinstaller-active-actions">
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
                            <SpinnerIcon />
                            <span>Cancelling ...</span>
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

            {stages.length > 0 && (
                <div className="modpackinstaller-active-files">
                    <span className="modpackinstaller-active-files-title">
                        {stages.length}{' '}
                        {stages.length === 1 ? 'stage' : 'stages'} in this{' '}
                        {active.mode === 'update' ? 'update' : 'install'}
                    </span>

                    <div
                        className="modpackinstaller-active-files-list"
                        aria-live="polite"
                    >
                        {stages.map((stage, index) => (
                            <StageCard
                                key={stage.key}
                                stage={stage}
                                index={index}
                                state={stageState(stage)}
                            />
                        ))}
                    </div>
                </div>
            )}

            {multiFile && (
                <div className="modpackinstaller-active-files">
                    <span className="modpackinstaller-active-files-title">
                        {files.length} files in this{' '}
                        {active.mode === 'update' ? 'update' : 'install'}
                    </span>

                    <div
                        className="modpackinstaller-active-files-list"
                        aria-live="polite"
                    >
                        {files.map((file, index) => {
                            const state = fileState(index);

                            return (
                                <ActiveFileCard
                                    key={`${index}:${file.name}`}
                                    file={file}
                                    state={state}
                                    bytesLabel={fileBytesLabel(index, state)}
                                    percent={filePercent(index, state)}
                                    kind={
                                        hasReportedFiles
                                            ? reportedFiles[index].kind
                                            : null
                                    }
                                />
                            );
                        })}
                    </div>
                </div>
            )}
        </>
    );
};
