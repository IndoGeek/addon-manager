import React from 'react';
import {
    ActiveInstallRecord,
    InstallProgressData,
    InstallRecordData,
    StatusMessage,
} from '../types';
import { InstalledModpackImage } from '../cards/InstalledModpackImage';
import { ActiveInstallCard } from './ActiveInstallCard';
import {
    RefreshIcon,
    SpinnerIcon,
    TrashIcon,
    UploadIcon,
    WarningIcon,
} from '../icons';
import { contentKindLabel, formatDate } from '../utils/constants';

interface InstalledModpacksBodyProps {
    installed: InstallRecordData[] | null;
    installedLoading: boolean;
    installedError: string | null;
    installedStatus: StatusMessage | null;
    lifecycleRecordId: string | null;
    activeInstall: ActiveInstallRecord | null;
    activeProgress: InstallProgressData | null;
    outcomeBanner: StatusMessage | null;
    providerLabels: Record<string, string>;
    onCancelActive: () => void;
    onDismissOutcome: () => void;
    onRefresh: () => void;
    onUpdate: (record: InstallRecordData) => void;
    onRestore: (record: InstallRecordData) => void;
    onUninstall: (record: InstallRecordData) => void;
}

export const InstalledModpacksBody = ({
    installed,
    installedLoading,
    installedError,
    installedStatus,
    lifecycleRecordId,
    activeInstall,
    activeProgress,
    outcomeBanner,
    providerLabels,
    onCancelActive,
    onDismissOutcome,
    onRefresh,
    onUpdate,
    onRestore,
    onUninstall,
}: InstalledModpacksBodyProps) => {
    return (
        <>
            {outcomeBanner && (
                <div
                    className={`modpackinstaller-status modpackinstaller-status--${outcomeBanner.kind} modpackinstaller-status--dismissible`}
                    role={
                        outcomeBanner.kind === 'error'
                            ? 'alert'
                            : 'status'
                    }
                >
                    <span>{outcomeBanner.message}</span>

                    <button
                        type="button"
                        className="modpackinstaller-status-dismiss"
                        onClick={onDismissOutcome}
                        aria-label="Dismiss"
                    >
                        &times;
                    </button>
                </div>
            )}

            {installedStatus && (
                <div
                    className={`modpackinstaller-status modpackinstaller-status--${installedStatus.kind}`}
                    role={
                        installedStatus.kind === 'error'
                            ? 'alert'
                            : 'status'
                    }
                >
                    {installedStatus.message}
                </div>
            )}

            {activeInstall && (
                <ActiveInstallCard
                    active={activeInstall}
                    progress={activeProgress}
                    onCancel={onCancelActive}
                    onDismiss={onDismissOutcome}
                    providerLabels={providerLabels}
                />
            )}

            {installed === null && installedLoading && (
                <div
                    className="modpackinstaller-catalog-state"
                    role="status"
                >
                    Loading installed addons ...
                </div>
            )}

            {installed !== null && installedError && (
                <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                    <p role="alert">{installedError}</p>

                    <button
                        type="button"
                        onClick={onRefresh}
                    >
                        Retry
                    </button>
                </div>
            )}

            {installed !== null
                && !installedError
                && installed.length === 0
                && !installedLoading
                && activeInstall === null && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                        <p>No modpacks installed</p>
                    </div>
                )}

            {installed !== null
                && !installedError
                && installed.length > 0 && (
                    <div className="modpackinstaller-installed-list">
                        {installed.map((record) => {
                            const missingCount =
                                record.integrity?.missing_count ?? 0;

                            const degraded =
                                record.integrity?.status === 'degraded';

                            const busy = lifecycleRecordId === record.id;

                            return (
                                <article
                                    className="modpackinstaller-installed-item"
                                    key={record.id}
                                >
                                    <InstalledModpackImage record={record} />

                                    <div className="modpackinstaller-installed-item-content">
                                        <div className="modpackinstaller-installed-item-title">
                                            <h4 title={record.display_name}>
                                                {record.display_name}
                                            </h4>

                                            <span className="modpackinstaller-catalog-card-provider">
                                                {providerLabels[record.provider]
                                                    ?? record.provider}
                                            </span>
                                        </div>

                                        <div className="modpackinstaller-pill-row">
                                            <span className="modpackinstaller-pill">
                                                {record.version}
                                            </span>

                                            {record.minecraft_version && (
                                                <span className="modpackinstaller-pill">
                                                    {record.minecraft_version}
                                                </span>
                                            )}

                                            {record.loader && (
                                                <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                                    {record.loader}
                                                </span>
                                            )}

                                            {contentKindLabel(
                                                record.content_kind,
                                            ) !== null && (
                                                <span className="modpackinstaller-pill modpackinstaller-pill--kind">
                                                    {contentKindLabel(
                                                        record.content_kind,
                                                    )}
                                                </span>
                                            )}
                                        </div>

                                        <p className="modpackinstaller-installed-meta">
                                            Installed{' '}
                                            {formatDate(
                                                record.installed_at,
                                            )}
                                            {' '}· Updated{' '}
                                            {formatDate(
                                                record.updated_at,
                                            )}
                                        </p>

                                        {degraded && (
                                            <p
                                                className="modpackinstaller-installed-warning"
                                                role="status"
                                                title={
                                                    record.integrity
                                                        ?.missing
                                                        ? record.integrity.missing.join('\n')
                                                        : undefined
                                                }
                                            >
                                                <WarningIcon />{' '}
                                                {missingCount}{' '}
                                                {missingCount === 1
                                                    ? 'file'
                                                    : 'files'}{' '}
                                                missing
                                            </p>
                                        )}
                                    </div>

                                    <div className="modpackinstaller-installed-actions">
                                        {degraded && (
                                            <button
                                                type="button"
                                                className="modpackinstaller-icon-button modpackinstaller-icon-button--yellow"
                                                onClick={() =>
                                                    onRestore(record)
                                                }
                                                disabled={
                                                    lifecycleRecordId
                                                        !== null
                                                }
                                                aria-label="Restore missing modpack files"
                                                title="Restore"
                                            >
                                                {busy
                                                    ? <SpinnerIcon />
                                                    : <RefreshIcon />}
                                            </button>
                                        )}

                                        <button
                                            type="button"
                                            className="modpackinstaller-icon-button modpackinstaller-icon-button--green"
                                            onClick={() => onUpdate(record)}
                                            disabled={
                                                lifecycleRecordId !== null
                                            }
                                            aria-label="Update modpack"
                                            title="Update"
                                        >
                                            {busy
                                                ? <SpinnerIcon />
                                                : <UploadIcon />}
                                        </button>

                                        <button
                                            type="button"
                                            className="modpackinstaller-icon-button modpackinstaller-icon-button--red"
                                            onClick={() =>
                                                onUninstall(record)
                                            }
                                            disabled={
                                                lifecycleRecordId !== null
                                            }
                                            aria-label="Uninstall modpack"
                                            title="Uninstall"
                                        >
                                            <TrashIcon />
                                        </button>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}
        </>
    );
};
