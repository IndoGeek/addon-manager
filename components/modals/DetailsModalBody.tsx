import React from 'react';
import {
    CatalogItem,
    CatalogVersion,
    InstallProgressData,
    ModpackMetadata,
    StatusMessage,
} from '../types';
import { ModpackIcon } from '../cards/ModpackIcon';
import { ManualDownloadNotice } from './ManualDownloadNotice';
import { PillTags } from '../cards/PillTags';
import {
    DownloadStatIcon,
    FollowsStatIcon,
    UpdatedStatIcon,
    WarningIcon,
} from '../icons';
import {
    buildCardTags,
    formatBytes,
    formatCount,
    formatDate,
    formatUpdated,
    versionLabel,
} from '../utils/constants';

interface DetailsModalBodyProps {
    detailsItem: CatalogItem;
    providerLabel: string | null;
    modalVersions: CatalogVersion[] | null;
    modalVersionsLoading: boolean;
    modalVersionsError: string | null;
    modalVersionSource: string | null;
    modalMetadata: ModpackMetadata | null;
    modalMetadataLoading: boolean;
    modalMetadataError: string | null;
    modalStatus: StatusMessage | null;
    modalResult: {
        total_files: number;
        created: number;
        overwritten: number;
        backed_up: number;
    } | null;
    modalInstallLoading: boolean;
    installProgress: InstallProgressData | null;
    installBlocked: boolean;
    willReplace: boolean;
    onSelectVersion: (source: string) => void;
    onRetryVersions: () => void;
    onRetryMetadata: () => void;
    onInstall: () => void;
}

export const DetailsModalBody = ({
    detailsItem,
    providerLabel,
    modalVersions,
    modalVersionsLoading,
    modalVersionsError,
    modalVersionSource,
    modalMetadata,
    modalMetadataLoading,
    modalMetadataError,
    modalStatus,
    modalResult,
    modalInstallLoading,
    installProgress,
    installBlocked,
    willReplace,
    onSelectVersion,
    onRetryVersions,
    onRetryMetadata,
    onInstall,
}: DetailsModalBodyProps) => {
    const selectedFileSize =
        modalVersions?.find(
            (version) => version.source === modalVersionSource,
        )?.file_size ?? null;

    return (
        <>
            {modalStatus && (
                <div
                    className={`modpackinstaller-status modpackinstaller-status--${modalStatus.kind}`}
                    role={
                        modalStatus.kind === 'error'
                            ? 'alert'
                            : 'status'
                    }
                >
                    {modalStatus.message}
                </div>
            )}

            <div className="modpackinstaller-modal-body">
                <div className="modpackinstaller-modal-item">
                    <ModpackIcon
                        item={detailsItem}
                        compact
                    />

                    <div className="modpackinstaller-modal-item-meta">
                        <div className="modpackinstaller-metadata-title">
                            <h4 title={detailsItem.name}>
                                {detailsItem.name}
                            </h4>

                            <span className="modpackinstaller-catalog-card-provider">
                                {providerLabel ?? detailsItem.provider}
                            </span>
                        </div>

                        <PillTags
                            tags={buildCardTags(detailsItem)}
                        />

                        <div className="modpackinstaller-catalog-card-stats modpackinstaller-modal-stats">
                            {detailsItem.downloads !== null && (
                                <span title="Downloads">
                                    <DownloadStatIcon />
                                    {formatCount(
                                        detailsItem.downloads,
                                    )}
                                </span>
                            )}

                            {detailsItem.follows !== null && (
                                <span title="Follows">
                                    <FollowsStatIcon />
                                    {formatCount(
                                        detailsItem.follows,
                                    )}
                                </span>
                            )}

                            {detailsItem.updated_at && (
                                <span title="Last updated">
                                    <UpdatedStatIcon />
                                    {formatUpdated(
                                        detailsItem.updated_at,
                                    )}
                                </span>
                            )}
                        </div>

                        {detailsItem.project_url && (
                            <a
                                href={detailsItem.project_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                referrerPolicy="no-referrer"
                                className="modpackinstaller-modal-link"
                            >
                                View on{' '}
                                {providerLabel ??
                                    detailsItem.provider}
                            </a>
                        )}
                    </div>
                </div>

                <p className="modpackinstaller-catalog-card-summary modpackinstaller-modal-summary">
                    {detailsItem.summary ||
                        'No description available.'}
                </p>

                <div className="modpackinstaller-modal-versions">
                    <div className="modpackinstaller-modal-versions-heading">
                        <span className="modpackinstaller-modal-versions-title">
                            Version
                        </span>

                        <span className="modpackinstaller-modal-versions-count">
                            {modalVersionsLoading
                                ? 'Loading versions ...'
                                : modalVersions === null
                                    ? ''
                                    : modalVersions.length === 0
                                        ? 'No versions are available for this modpack.'
                                        : `${modalVersions.length} version${modalVersions.length === 1 ? '' : 's'}`}
                        </span>
                    </div>

                    {modalVersionsLoading && (
                        <div
                            className="modpackinstaller-catalog-state"
                            role="status"
                        >
                            Loading versions ...
                        </div>
                    )}

                    {!modalVersionsLoading
                        && modalVersionsError && (
                            <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                                <p role="alert">
                                    {modalVersionsError}
                                </p>

                                <button
                                    type="button"
                                    onClick={onRetryVersions}
                                >
                                    Retry
                                </button>
                            </div>
                        )}

                    {!modalVersionsLoading
                        && !modalVersionsError
                        && modalVersions !== null
                        && modalVersions.length === 0 && (
                            <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                                <p>No versions are available for this
                                    modpack.</p>
                            </div>
                        )}

                    {!modalVersionsLoading
                        && !modalVersionsError
                        && modalVersions !== null
                        && modalVersions.length > 0 && (
                            <div
                                className="modpackinstaller-version-options"
                                role="radiogroup"
                                aria-label="Version"
                            >
                                {modalVersions.map((version) => {
                                    const checked =
                                        modalVersionSource
                                        === version.source;

                                    return (
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={checked}
                                            key={version.source}
                                            className={`modpackinstaller-version-option${
                                                checked
                                                    ? ' modpackinstaller-version-option--selected'
                                                    : ''
                                            }`}
                                            onClick={() =>
                                                onSelectVersion(
                                                    version.source,
                                                )
                                            }
                                        >
                                            <span className="modpackinstaller-version-option-name">
                                                {versionLabel(
                                                    version,
                                                )}
                                            </span>

                                            <span className="modpackinstaller-version-option-meta">
                                                {version.date_published
                                                    ? `Published ${formatDate(version.date_published)}`
                                                    : 'Release date unavailable'}
                                                {version.file_size !== null
                                                    && ` · ${formatBytes(version.file_size)}`}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                </div>

                {modalMetadataLoading && (
                    <div
                        className="modpackinstaller-catalog-state"
                        role="status"
                    >
                        Resolving the selected version ...
                    </div>
                )}

                {!modalMetadataLoading
                    && modalMetadataError && (
                        <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                            <p role="alert">
                                {modalMetadataError}
                            </p>

                            <button
                                type="button"
                                onClick={onRetryMetadata}
                            >
                                Retry
                            </button>
                        </div>
                    )}

                {modalMetadata && (
                    <div className="modpackinstaller-modal-selected">
                        <div className="modpackinstaller-pill-row">
                            <span className="modpackinstaller-pill">
                                {modalMetadata.version}
                            </span>

                            {modalMetadata.minecraft_version && (
                                <span className="modpackinstaller-pill">
                                    {modalMetadata.minecraft_version}
                                </span>
                            )}

                            {modalMetadata.loader && (
                                <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                    {modalMetadata.loader}
                                </span>
                            )}

                            {selectedFileSize !== null && (
                                <span className="modpackinstaller-pill modpackinstaller-pill--storage">
                                    {formatBytes(selectedFileSize)}
                                </span>
                            )}
                        </div>

                        {modalMetadata.manual_download ? (
                            <ManualDownloadNotice
                                manual={
                                    modalMetadata.manual_download
                                }
                            />
                        ) : (
                            <div className="modpackinstaller-modal-actions">
                                <button
                                    type="button"
                                    onClick={onInstall}
                                    disabled={
                                        !modalVersionSource
                                        || modalInstallLoading
                                        || modalResult !== null
                                        || installBlocked
                                    }
                                    className={`modpackinstaller-modal-actions-button modpackinstaller-install-button${
                                        modalResult !== null
                                            ? ' modpackinstaller-modal-actions-button--success'
                                            : installBlocked
                                                ? ' modpackinstaller-modal-actions-button--locked'
                                                : willReplace
                                                    ? ' modpackinstaller-install-button--warning'
                                                    : ''
                                    }`}
                                >
                                    {modalInstallLoading && (
                                        <span
                                            className={`modpackinstaller-install-progress-fill${
                                                installProgress
                                                    ?.indeterminate
                                                    ? ' modpackinstaller-install-progress-fill--indeterminate'
                                                    : ''
                                            }`}
                                            style={{
                                                width: `${installProgress?.percent ?? 0}%`,
                                            }}
                                        />
                                    )}
                                    <span className="modpackinstaller-install-progress-label">
                                        {installBlocked
                                            ? '1 modpack installation in progress'
                                            : modalInstallLoading
                                                ? `Installing ...${
                                                      installProgress
                                                      && !installProgress.indeterminate
                                                          ? ` ${installProgress.percent}%`
                                                          : ''
                                                  }`
                                                : modalResult !== null
                                                    ? 'Installed'
                                                    : willReplace
                                                        ? (
                                                            <>
                                                                <WarningIcon />
                                                                <span className="modpackinstaller-install-button-warning-label">
                                                                    Warning: installing this modpack will replace your existing modpack
                                                                </span>
                                                            </>
                                                        )
                                                        : 'Install Modpack'}
                                    </span>
                                </button>
                            </div>
                        )}
                    </div>
                )}

                {modalResult && (
                    <div className="modpackinstaller-modal-result">
                        <div className="modpackinstaller-result-grid">
                            <div>
                                <span>Total files</span>
                                <strong>
                                    {modalResult.total_files}
                                </strong>
                            </div>

                            <div>
                                <span>Created</span>
                                <strong>
                                    {modalResult.created}
                                </strong>
                            </div>

                            <div>
                                <span>Overwritten</span>
                                <strong>
                                    {modalResult.overwritten}
                                </strong>
                            </div>

                            <div>
                                <span>Backups</span>
                                <strong>
                                    {modalResult.backed_up}
                                </strong>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
};
