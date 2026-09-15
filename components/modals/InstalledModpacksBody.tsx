import React from 'react';
import { InstallRecordData, StatusMessage } from '../types';
import { InstalledModpackImage } from '../cards/InstalledModpackImage';
import {
    RefreshIcon,
    SpinnerIcon,
    TrashIcon,
    UploadIcon,
    WarningIcon,
} from '../icons';
import { formatDate } from '../utils/constants';

interface InstalledModpacksBodyProps {
    installed: InstallRecordData[] | null;
    installedLoading: boolean;
    installedError: string | null;
    installedStatus: StatusMessage | null;
    lifecycleRecordId: string | null;
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
    onRefresh,
    onUpdate,
    onRestore,
    onUninstall,
}: InstalledModpacksBodyProps) => {
    return (
        <>
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

            {installed === null && installedLoading && (
                <div
                    className="modpackinstaller-catalog-state"
                    role="status"
                >
                    Loading installed modpacks ...
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
                && !installedLoading && (
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
                                                {record.provider}
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
