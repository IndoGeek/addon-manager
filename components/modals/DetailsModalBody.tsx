import React from 'react';
import {
    CatalogDescriptionData,
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
    DownloadIcon,
    FollowsStatIcon,
    OpenIcon,
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
    modalDescription: CatalogDescriptionData | null;
    modalDescriptionLoading: boolean;
    modalDescriptionError: string | null;
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
    onRetryDescription: () => void;
    onInstall: () => void;
    onOpenVersionPicker: () => void;
    versionPickerOpen: boolean;
    onCloseVersionPicker: () => void;
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
    modalDescription,
    modalDescriptionLoading,
    modalDescriptionError,
    modalStatus,
    modalResult,
    modalInstallLoading,
    installProgress,
    installBlocked,
    willReplace,
    onSelectVersion,
    onRetryVersions,
    onRetryDescription,
    onInstall,
    onOpenVersionPicker,
    versionPickerOpen,
    onCloseVersionPicker,
}: DetailsModalBodyProps) => {
    const selectedVersion = modalVersions?.find(
        (version) => version.source === modalVersionSource,
    ) ?? null;

    const tags = buildCardTags(detailsItem);

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

            {/* ── Header card: logo, name, author, summary, tags,
                View + Download actions ── */}
            <section className="modpackinstaller-detail-card modpackinstaller-detail-header">
                <div className="modpackinstaller-detail-header-logo">
                    <ModpackIcon
                        item={detailsItem}
                        compact
                    />
                </div>

                <div className="modpackinstaller-detail-header-main">
                    <div className="modpackinstaller-detail-header-titlerow">
                        <h4 title={detailsItem.name}>
                            {detailsItem.name}
                        </h4>

                        <span className="modpackinstaller-catalog-card-provider">
                            {providerLabel ?? detailsItem.provider}
                        </span>
                    </div>

                    <span className="modpackinstaller-detail-header-author">
                        by {detailsItem.author || 'unknown'}
                    </span>

                    <p className="modpackinstaller-detail-header-summary">
                        {detailsItem.summary ||
                            'No description available.'}
                    </p>

                    <div className="modpackinstaller-detail-header-stats">
                        {detailsItem.downloads !== null && (
                            <span title="Downloads">
                                <DownloadStatIcon />
                                {formatCount(detailsItem.downloads)}
                            </span>
                        )}

                        {detailsItem.follows !== null && (
                            <span title="Follows">
                                <FollowsStatIcon />
                                {formatCount(detailsItem.follows)}
                            </span>
                        )}

                        {detailsItem.updated_at && (
                            <span title="Last updated">
                                <UpdatedStatIcon />
                                {formatUpdated(detailsItem.updated_at)}
                            </span>
                        )}
                    </div>

                    {tags.length > 0 && (
                        <PillTags tags={tags} />
                    )}
                </div>

                <div className="modpackinstaller-detail-header-actions">
                    {detailsItem.project_url && (
                        <a
                            href={detailsItem.project_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerPolicy="no-referrer"
                            className="modpackinstaller-detail-view-button"
                            aria-label="View on provider page"
                            title="View on provider"
                        >
                            <OpenIcon />
                        </a>
                    )}

                    <button
                        type="button"
                        className={`modpackinstaller-detail-download-button${
                            modalVersionSource
                                ? ' modpackinstaller-detail-download-button--armed'
                                : ''
                        }`}
                        onClick={onOpenVersionPicker}
                        disabled={installBlocked}
                        aria-label="Choose version and download"
                        title="Download"
                    >
                        <DownloadIcon />
                    </button>
                </div>
            </section>            {/* ── Description card ── */}
            <section className="modpackinstaller-detail-card">
                <h5 className="modpackinstaller-detail-section-title">
                    Description
                </h5>

                {modalDescriptionLoading && (
                    <div
                        className="modpackinstaller-catalog-state"
                        role="status"
                    >
                        Loading description ...
                    </div>
                )}

                {!modalDescriptionLoading
                    && modalDescriptionError && (
                        <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                            <p>{modalDescriptionError}</p>

                            <button
                                type="button"
                                onClick={onRetryDescription}
                            >
                                Retry
                            </button>
                        </div>
                    )}

                {!modalDescriptionLoading
                    && !modalDescriptionError
                    && modalDescription !== null
                    && modalDescription.html.trim() !== '' && (
                        <div
                            className="modpackinstaller-detail-description"
                            // Sanitized server-side by
                            // DescriptionSanitizer: allowlisted tags only,
                            // event handlers and scripting URLs stripped.
                            dangerouslySetInnerHTML={{
                                __html: modalDescription.html,
                            }}
                        />
                    )}

                {!modalDescriptionLoading
                    && !modalDescriptionError
                    && (modalDescription === null
                        || modalDescription.html.trim() === '') && (
                        <p className="modpackinstaller-detail-header-summary">
                            {detailsItem.summary ||
                                'No description available.'}
                        </p>
                    )}
            </section>

            {/* ── Compatibility card ── */}
            <section className="modpackinstaller-detail-card">
                <h5 className="modpackinstaller-detail-section-title">
                    Compatibility
                </h5>

                <CompatibilitySection item={detailsItem} />
            </section>

            {/* ── Author card ── */}
            <section className="modpackinstaller-detail-card modpackinstaller-detail-author">
                <h5 className="modpackinstaller-detail-section-title">
                    Author
                </h5>

                <div className="modpackinstaller-detail-author-row">
                    <span className="modpackinstaller-detail-author-name">
                        {detailsItem.author || 'Unknown author'}
                    </span>

                    {detailsItem.project_url && (
                        <a
                            href={detailsItem.project_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerPolicy="no-referrer"
                            className="modpackinstaller-detail-view-button"
                            aria-label={`View ${detailsItem.author ?? 'author'} on ${providerLabel ?? detailsItem.provider}`}
                            title={`View on ${providerLabel ?? detailsItem.provider}`}
                        >
                            <OpenIcon />
                        </a>
                    )}
                </div>
            </section>

            {modalResult && (
                <div className="modpackinstaller-detail-card modpackinstaller-modal-result">
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

            {versionPickerOpen && (
                <div
                    className="modpackinstaller-version-picker-overlay"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Select a version"
                    onClick={(event) => {
                        if (event.target === event.currentTarget) {
                            onCloseVersionPicker();
                        }
                    }}
                >
                    <div className="modpackinstaller-version-picker">
                        <div className="modpackinstaller-version-picker-header">
                            <h5>
                                Select a version
                            </h5>

                            <button
                                type="button"
                                className="modpackinstaller-modal-close"
                                onClick={onCloseVersionPicker}
                                aria-label="Close version selection"
                            >
                                &times;
                            </button>
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
                                    <p>No versions are available for
                                        this modpack.</p>
                                </div>
                            )}

                        {!modalVersionsLoading
                            && !modalVersionsError
                            && modalVersions !== null
                            && modalVersions.length > 0 && (
                                <div
                                    className="modpackinstaller-version-picker-options"
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

                                    {selectedVersion?.file_size !== null
                                        && selectedVersion?.file_size !== undefined && (
                                        <span className="modpackinstaller-pill modpackinstaller-pill--storage">
                                            {formatBytes(selectedVersion.file_size)}
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
                                            }`}>
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
                                                    ? 'Let it finish'
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
                                                                            Warning
                                                                        </span>
                                                                    </>
                                                                )
                                                                : 'Install'}
                                            </span>
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
};

const CompatibilitySection = ({
    item,
}: {
    item: CatalogItem;
}) => {
    const gameVersions = item.game_versions.slice(0, 8);

    return (
        <div className="modpackinstaller-detail-compat">
            <div className="modpackinstaller-detail-compat-group">
                <span className="modpackinstaller-detail-compat-label">
                    Minecraft
                </span>

                <div className="modpackinstaller-detail-compat-values">
                    {gameVersions.length > 0
                        ? gameVersions.map((version) => (
                            <span
                                key={version}
                                className="modpackinstaller-pill"
                            >
                                {version}
                            </span>
                        ))
                        : (
                            <span className="modpackinstaller-detail-compat-unknown">
                                Not declared by the modpack
                            </span>
                        )}
                </div>
            </div>

            <div className="modpackinstaller-detail-compat-group">
                <span className="modpackinstaller-detail-compat-label">
                    Loaders
                </span>

                <div className="modpackinstaller-detail-compat-values">
                    {item.loaders.length > 0
                        ? item.loaders.map((loader) => (
                            <span
                                key={loader}
                                className="modpackinstaller-pill modpackinstaller-pill--loader"
                            >
                                {loader}
                            </span>
                        ))
                        : (
                            <span className="modpackinstaller-detail-compat-unknown">
                                None declared
                            </span>
                        )}
                </div>
            </div>

            {item.environment && (
                <div className="modpackinstaller-detail-compat-group">
                    <span className="modpackinstaller-detail-compat-label">
                        Environment
                    </span>

                    <div className="modpackinstaller-detail-compat-values">
                        <span className="modpackinstaller-pill">
                            {item.environment}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
};
