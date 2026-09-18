import React from 'react';
import { CatalogVersion, InstallRecordData, ModVersion } from '../types';
import {
    contentKindLabel,
    contentNoun,
    formatBytes,
    formatDate,
    titleCaseTag,
} from '../utils/constants';

/**
 * One selectable version of an installed addon. The modpack version list and
 * the single-file mod version list normalize into this one shape, so the
 * update window renders both without branching.
 */
export interface UpdateVersionOption {
    version_id: string;
    version_number: string;
    game_versions: string[];
    loaders: string[];
    date_published: string | null;
    file_size: number | null;
    source: string;
}

export const toUpdateOptions = (
    versions: Array<CatalogVersion | ModVersion>,
): UpdateVersionOption[] =>
    versions.map((version) => ({
        version_id: version.version_id,
        version_number: version.version_number,
        game_versions: version.game_versions ?? [],
        loaders: version.loaders ?? [],
        date_published: version.date_published ?? null,
        file_size: version.file_size ?? null,
        source: version.source,
    }));

/**
 * Which way the chosen version moves the installation. Both upstream lists
 * arrive newest-first, so comparing positions is enough; an installed version
 * that is no longer listed leaves the direction unknown.
 */
export const updateDirection = (
    record: InstallRecordData,
    versions: UpdateVersionOption[],
    selection: string | null,
): 'upgrade' | 'downgrade' | 'reinstall' | 'update' => {
    const selected = versions.findIndex(
        (version) => version.source === selection,
    );

    const installedId = record.version_id ?? '';

    const installed = installedId === ''
        ? -1
        : versions.findIndex(
            (version) => version.version_id === installedId,
        );

    if (selected === -1 || installed === -1) {
        return 'update';
    }

    if (selected === installed) {
        return 'reinstall';
    }

    return selected < installed ? 'upgrade' : 'downgrade';
};

const DIRECTION_LABELS: Record<string, string> = {
    upgrade: 'Upgrade',
    downgrade: 'Downgrade',
    reinstall: 'Reinstall',
    update: 'Update',
};

export const updateActionLabel = (
    direction: 'upgrade' | 'downgrade' | 'reinstall' | 'update',
    versionNumber: string,
): string => `${DIRECTION_LABELS[direction]} to ${versionNumber}`;

interface UpdateVersionBodyProps {
    record: InstallRecordData;
    versions: UpdateVersionOption[] | null;
    loading: boolean;
    error: string | null;
    selection: string | null;
    installLoading: boolean;
    installBlocked: boolean;
    onSelect: (source: string) => void;
    onRetry: () => void;
    onConfirm: () => void;
    onCancel: () => void;
}

export const UpdateVersionBody = ({
    record,
    versions,
    loading,
    error,
    selection,
    installLoading,
    installBlocked,
    onSelect,
    onRetry,
    onConfirm,
    onCancel,
}: UpdateVersionBodyProps) => {
    const noun = contentNoun(record.content_kind);

    const kindLabel = contentKindLabel(record.content_kind);

    const selected =
        versions?.find((version) => version.source === selection) ?? null;

    const direction = updateDirection(record, versions ?? [], selection);

    const busy = installLoading || installBlocked;

    return (
        <div className="modpackinstaller-confirm">
            <p className="modpackinstaller-update-current">
                <strong>{record.display_name}</strong>{' '}
                {kindLabel !== null && `(${kindLabel}) `}
                is installed at version{' '}
                <strong>{record.version || 'unknown'}</strong>
                {record.minecraft_version
                    && ` for Minecraft ${record.minecraft_version}`}
                {record.loader && ` · ${titleCaseTag(record.loader)}`}
                . Pick the version to install instead.
            </p>

            {loading && (
                <div
                    className="modpackinstaller-catalog-state"
                    role="status"
                >
                    Loading versions ...
                </div>
            )}

            {!loading && error && (
                <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                    <p role="alert">{error}</p>

                    <button type="button" onClick={onRetry}>
                        Retry
                    </button>
                </div>
            )}

            {!loading
                && !error
                && versions !== null
                && versions.length === 0 && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                        <p>
                            No installable {noun} versions are available for
                            this project.
                        </p>
                    </div>
                )}

            {!loading
                && !error
                && versions !== null
                && versions.length > 0 && (
                    <div
                        className="modpackinstaller-version-picker-options"
                        role="radiogroup"
                        aria-label={`${noun} version`}
                    >
                        {versions.map((version) => {
                            const checked = selection === version.source;

                            const installed =
                                record.version_id !== null
                                && record.version_id !== ''
                                && version.version_id
                                    === record.version_id;

                            const pills = [
                                ...version.game_versions.slice(0, 3),
                                ...version.loaders,
                            ];

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
                                    onClick={() => onSelect(version.source)}
                                >
                                    <span className="modpackinstaller-version-option-name">
                                        {version.version_number}
                                        {installed && ' · installed'}
                                    </span>

                                    {pills.length > 0 && (
                                        <span className="modpackinstaller-pill-row">
                                            {pills.map((pill) => (
                                                <span
                                                    className="modpackinstaller-pill"
                                                    key={pill}
                                                >
                                                    {pill}
                                                </span>
                                            ))}
                                        </span>
                                    )}

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

            <div className="modpackinstaller-confirm-actions">
                <button
                    type="button"
                    className="modpackinstaller-confirm-cancel"
                    onClick={onCancel}
                    disabled={busy}
                >
                    Cancel
                </button>

                <button
                    type="button"
                    className="modpackinstaller-confirm-accept"
                    onClick={onConfirm}
                    disabled={
                        busy
                        || selection === null
                        || selected === null
                    }
                >
                    {installLoading
                        ? 'Starting ...'
                        : selected === null
                            ? 'Select a version'
                            : updateActionLabel(
                                direction,
                                selected.version_number,
                            )}
                </button>
            </div>
        </div>
    );
};
