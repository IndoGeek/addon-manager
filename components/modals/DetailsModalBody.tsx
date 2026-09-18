import React from 'react';
import {
    CatalogDescriptionData,
    CatalogItem,
    CatalogVersion,
    InstallProgressData,
    ModDependency,
    ModpackMetadata,
    ModVersion,
    StatusMessage,
} from '../types';
import { ModpackIcon } from '../cards/ModpackIcon';
import { ManualDownloadNotice } from './ManualDownloadNotice';
import { PillTags } from '../cards/PillTags';
import { Dropdown } from '../common/Dropdown';
import {
    DownloadStatIcon,
    DownloadIcon,
    FollowsStatIcon,
    OpenIcon,
    UpdatedStatIcon,
    WarningIcon,
    CheckIcon,
} from '../icons';
import {
    buildCardTags,
    contentKindLabel,
    formatBytes,
    formatCount,
    formatDate,
    formatUpdated,
    versionLabel,
} from '../utils/constants';

/** A dependency recommendation plus the build matching the current pair. */
export interface ModDependencyCard extends ModDependency {
    /** The dependency project's versions, null while loading. */
    versions: ModVersion[] | null;
    /** The version matching the selected loader + MC pair, if any. */
    resolvedVersion: ModVersion | null;
}

interface DetailsModalBodyProps {
    detailsItem: CatalogItem;
    providerLabel: string | null;
    /**
     * Whether the open entry is single-file content (mods, plugins,
     * datapacks, resource packs, shaders) and uses the loader/Minecraft
     * version window instead of the modpack version list.
     */
    isContentEntry: boolean;
    /** Catalog kind of the open entry, shown as a pill before install. */
    contentKind: string | null;
    modalVersions: CatalogVersion[] | null;
    modalVersionsLoading: boolean;
    modalVersionsError: string | null;
    modVersions: ModVersion[] | null;
    modVersionsLoading: boolean;
    modVersionsError: string | null;
    modLoaderSelection: string;
    modMcSelection: string;
    onModLoaderChange: (value: string) => void;
    onModMcChange: (value: string) => void;
    /** Recommendations of the resolved version + their compatible builds. */
    modDependencyCards: ModDependencyCard[];
    /** Project ids the user opted into installing alongside the main mod. */
    selectedDependencyIds: string[];
    onToggleDependency: (projectId: string) => void;
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
    isContentEntry,
    contentKind,
    modalVersions,
    modalVersionsLoading,
    modalVersionsError,
    modVersions,
    modVersionsLoading,
    modVersionsError,
    modLoaderSelection,
    modMcSelection,
    onModLoaderChange,
    onModMcChange,
    modDependencyCards,
    selectedDependencyIds,
    onToggleDependency,
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

    // ── Mod version window ──────────────────────────────────────────
    const modLoaders = modVersions === null
        ? []
        : Array.from(
            new Set(modVersions.flatMap((version) => version.loaders)),
        ).sort();

    const modGameVersions = modVersions === null
        ? []
        : Array.from(
            new Set(modVersions.flatMap((version) => version.game_versions)),
        )
            .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }));

    // Combos that actually exist for the current other dropdown's value.
    const modLoadersForMc = modVersions === null || modMcSelection === ''
        ? modLoaders
        : modLoaders.filter((loader) =>
            modVersions.some(
                (version) =>
                    version.loaders.includes(loader)
                    && version.game_versions.includes(modMcSelection),
            ),
        );

    // CurseForge does not record a loader for every content type: plugins,
    // resource packs, data packs and shaders publish files tagged with
    // Minecraft versions only (verified live — a plugin file's modLoader is
    // null). Those get a single dropdown instead of an invented loader list.
    const modHasLoaders = modLoaders.length > 0;

    const modMcForLoader = modVersions === null
        || !modHasLoaders
        || modLoaderSelection === ''
            ? modGameVersions
            : modGameVersions.filter((mc) =>
                modVersions.some(
                    (version) =>
                        version.game_versions.includes(mc)
                        && version.loaders.includes(modLoaderSelection),
                ),
            );

    const modSelectedVersion =
        modVersions !== null
        && modMcSelection !== ''
        && (!modHasLoaders || modLoaderSelection !== '')
            ? modVersions.find(
                (version) =>
                    version.game_versions.includes(modMcSelection)
                    && (
                        !modHasLoaders
                        || version.loaders.includes(modLoaderSelection)
                    ),
            ) ?? null
            : null;

    // A complete selection with a matching version exists → install shows.
    const modReady = isContentEntry && modSelectedVersion !== null;

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

            {/* ── Header card: logo, name, author, summary, tags, View + Download actions ── */}
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
                    <div
                        className={`modpackinstaller-version-picker${
                            isContentEntry && !modHasLoaders
                                ? ' modpackinstaller-version-picker--compact'
                                : ''
                        }`}
                    >
                        <div className="modpackinstaller-version-picker-header">
                            <h5>
                                {isContentEntry
                                    ? (modHasLoaders
                                        ? 'Select loader and Minecraft version'
                                        : 'Select Minecraft version')
                                    : 'Select a version'}
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

                        {isContentEntry ? (
                            <ModVersionPickerBody
                                modVersions={modVersions}
                                loading={modVersionsLoading}
                                error={modVersionsError}
                                loaderSelection={modLoaderSelection}
                                mcSelection={modMcSelection}
                                contentKind={contentKind}
                                onLoaderChange={onModLoaderChange}
                                onMcChange={onModMcChange}
                                hasLoaders={modHasLoaders}
                                loaders={modHasLoaders ? modLoadersForMc : []}
                                gameVersions={modMcForLoader}
                                selected={modSelectedVersion}
                                ready={modReady}
                                dependencyCards={modDependencyCards}
                                selectedDependencyIds={selectedDependencyIds}
                                onToggleDependency={onToggleDependency}
                                installLoading={modalInstallLoading}
                                installProgress={installProgress}
                                installBlocked={installBlocked}
                                onInstall={onInstall}
                            />
                        ) : (
                            <>
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
                            </>
                        )}
                    </div>
                </div>
            )}
        </>
    );
};

// Version selection window for single-file content (mods): two dropdowns
// (loader, Minecraft version). The install button appears only once both
// selections resolve to a published version; dependency recommendations are
// informational and never block the install.
const ModVersionPickerBody = ({
    modVersions,
    loading,
    error,
    loaderSelection,
    mcSelection,
    contentKind,
    onLoaderChange,
    onMcChange,
    hasLoaders,
    loaders,
    gameVersions,
    selected,
    ready,
    dependencyCards,
    selectedDependencyIds,
    onToggleDependency,
    installLoading,
    installProgress,
    installBlocked,
    onInstall,
}: {
    modVersions: ModVersion[] | null;
    loading: boolean;
    error: string | null;
    loaderSelection: string;
    mcSelection: string;
    onLoaderChange: (value: string) => void;
    onMcChange: (value: string) => void;
    /** Catalog kind of the entry being installed, shown as a pill. */
    contentKind: string | null;
    /**
     * Whether this content type declares loaders at all. False for CurseForge
     * plugins, resource packs, data packs and shaders, which are tagged with
     * Minecraft versions only — those get a single dropdown.
     */
    hasLoaders: boolean;
    loaders: string[];
    gameVersions: string[];
    selected: ModVersion | null;
    ready: boolean;
    dependencyCards: ModDependencyCard[];
    selectedDependencyIds: string[];
    onToggleDependency: (projectId: string) => void;
    installLoading: boolean;
    installProgress: InstallProgressData | null;
    installBlocked: boolean;
    onInstall: () => void;
}) => {
    if (loading) {
        return (
            <div
                className="modpackinstaller-catalog-state"
                role="status"
            >
                Loading versions ...
            </div>
        );
    }

    if (error) {
        return (
            <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                <p role="alert">
                    {error}
                </p>
            </div>
        );
    }

    if (modVersions === null || modVersions.length === 0) {
        return (
            <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                <p>No versions are available for this project.</p>
            </div>
        );
    }

    return (
        <>
            <div
                className={`modpackinstaller-mod-version-selects${
                    hasLoaders
                        ? ''
                        : ' modpackinstaller-mod-version-selects--single'
                }`}
            >
                {hasLoaders && (
                    <Dropdown
                        id="modpackinstaller-mod-loader"
                        label="Loader"
                        value={loaderSelection}
                        onChange={onLoaderChange}
                        options={loaders.map((loader) => ({
                            value: loader,
                            label: loader,
                        }))}
                        hideLabel={false}
                        fixedMenu
                    />
                )}

                <Dropdown
                    id="modpackinstaller-mod-mc"
                    label="Minecraft version"
                    value={mcSelection}
                    onChange={onMcChange}
                    options={gameVersions.map((mc) => ({
                        value: mc,
                        label: mc,
                    }))}
                    hideLabel={false}
                    fixedMenu
                />
            </div>

            {ready && selected !== null && (
                <div className="modpackinstaller-modal-selected">
                    <div className="modpackinstaller-pill-row modpackinstaller-modal-selected-pills">
                        <span className="modpackinstaller-pill">
                            {selected.version_number}
                        </span>

                        <span className="modpackinstaller-pill">
                            {mcSelection}
                        </span>

                        {loaderSelection !== '' && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--loader">
                                {loaderSelection}
                            </span>
                        )}

                        {contentKindLabel(contentKind) !== null && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--kind">
                                {contentKindLabel(contentKind)}
                            </span>
                        )}

                        {selected.file_size !== null && (
                            <span className="modpackinstaller-pill modpackinstaller-pill--storage">
                                {formatBytes(selected.file_size)}
                            </span>
                        )}
                    </div>

                    {dependencyCards.length > 0 && (
                        <div
                            className="modpackinstaller-mod-dependency-hint"
                            role="note"
                        >
                            <span className="modpackinstaller-mod-dependency-title">
                                Recommended dependencies
                            </span>

                            <div className="modpackinstaller-mod-dependency-list">
                                {dependencyCards.map((card) => {
                                    const checked =
                                        selectedDependencyIds.includes(
                                            card.project_id,
                                        );

                                    const disabled =
                                        card.resolvedVersion === null;

                                    return (
                                        <div
                                            key={card.project_id}
                                            className={`modpackinstaller-mod-dependency-card${
                                                checked
                                                    ? ' modpackinstaller-mod-dependency-card--selected'
                                                    : ''
                                            }${
                                                disabled
                                                    ? ' modpackinstaller-mod-dependency-card--disabled'
                                                    : ''
                                            }`}
                                        >
                                            {card.icon_url && (
                                                <img
                                                    src={card.icon_url}
                                                    alt=""
                                                    className="modpackinstaller-mod-dependency-icon"
                                                    loading="lazy"
                                                    referrerPolicy="no-referrer"
                                                />
                                            )}

                                            <div className="modpackinstaller-mod-dependency-info">
                                                <span
                                                    className="modpackinstaller-mod-dependency-name"
                                                    title={card.title}
                                                >
                                                    {card.title}
                                                </span>

                                                <span className="modpackinstaller-mod-dependency-meta">
                                                    {card.resolvedVersion !== null
                                                        ? card.resolvedVersion.version_number
                                                        : card.versions === null
                                                            ? 'Checking versions ...'
                                                            : 'No build for this version'}
                                                    {' · '}
                                                    {card.type === 'required'
                                                        ? 'required'
                                                        : 'optional'}
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                className={`modpackinstaller-mod-dependency-select${
                                                    checked
                                                        ? ' modpackinstaller-mod-dependency-select--on'
                                                        : ''
                                                }`}
                                                disabled={disabled}
                                                aria-pressed={checked}
                                                aria-label={
                                                    checked
                                                        ? `Don't install ${card.title}`
                                                        : `Also install ${card.title}`
                                                }
                                                title={
                                                    disabled
                                                        ? 'No compatible build for the selected version'
                                                        : checked
                                                            ? 'Will be installed together with the main file'
                                                            : 'Select to install together with the main file'
                                                }
                                                onClick={() =>
                                                    onToggleDependency(
                                                        card.project_id,
                                                    )
                                                }
                                            >
                                                {checked ? <CheckIcon /> : 'Select'}
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>

                            <p className="modpackinstaller-mod-dependency-note">
                                Recommendations only — the install works
                                without them.
                            </p>
                        </div>
                    )}

                    <div className="modpackinstaller-modal-actions">
                        <button
                            type="button"
                            onClick={onInstall}
                            disabled={
                                installLoading || installBlocked
                            }
                            className={`modpackinstaller-modal-actions-button modpackinstaller-install-button${
                                installBlocked
                                    ? ' modpackinstaller-modal-actions-button--locked'
                                    : ''
                            }`}>
                            {installLoading && (
                                <span
                                    className={`modpackinstaller-install-progress-fill${
                                        installProgress?.indeterminate
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
                                    : installLoading
                                        ? `Installing ...${
                                              installProgress
                                              && !installProgress.indeterminate
                                                  ? ` ${installProgress.percent}%`
                                                  : ''
                                          }`
                                        : 'Install'}
                            </span>
                        </button>
                    </div>
                </div>
            )}

            {!ready
                && loaderSelection !== ''
                && mcSelection !== '' && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                        <p>
                            {hasLoaders
                                ? 'No version matches that loader and Minecraft version combination. Try another pair.'
                                : 'No version is published for that Minecraft version. Try another one.'}
                        </p>
                    </div>
                )}

            {!ready
                && (loaderSelection === '' || mcSelection === '') && (
                    <p className="modpackinstaller-mod-version-note">
                        {hasLoaders
                            ? 'Choose a loader and a Minecraft version to see the install option.'
                            : 'Choose a Minecraft version to see the install option.'}
                    </p>
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
                                Not declared by this project
                            </span>
                        )}
                </div>
            </div>

            {/* Only shown when the project actually declares loaders: CurseForge
                plugins, resource packs, data packs and shaders are tagged with
                Minecraft versions only, so an empty "Loaders" row would imply
                missing data that was never recorded upstream. */}
            {item.loaders.length > 0 && (
                <div className="modpackinstaller-detail-compat-group">
                    <span className="modpackinstaller-detail-compat-label">
                        Loaders
                    </span>

                    <div className="modpackinstaller-detail-compat-values">
                        {item.loaders.map((loader) => (
                            <span
                                key={loader}
                                className="modpackinstaller-pill modpackinstaller-pill--loader"
                            >
                                {loader}
                            </span>
                        ))}
                    </div>
                </div>
            )}

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
