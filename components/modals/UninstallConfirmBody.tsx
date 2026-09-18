import React from 'react';
import { InstallRecordData } from '../types';

interface UninstallConfirmBodyProps {
    pendingUninstall: InstallRecordData | null;
    uninstalling: boolean;
    onCancel: () => void;
    onConfirm: (record: InstallRecordData) => void;
}

export const UninstallConfirmBody = ({
    pendingUninstall,
    uninstalling,
    onCancel,
    onConfirm,
}: UninstallConfirmBodyProps) => {
    // Single-file content (mods, plugins, datapacks, resource packs, shaders): only the recorded file is removed —...
    const isContent =
        pendingUninstall?.content_type === 'content';

    return (
        <div className="modpackinstaller-confirm">
            {isContent ? (
                <p>
                    Uninstalling{' '}
                    <strong>
                        {pendingUninstall?.display_name}
                    </strong>{' '}
                    will remove only the file this extension installed. Your
                    other mods, plugins, and data are not touched. This
                    cannot be undone.
                </p>
            ) : (
                <p>
                    Uninstalling{' '}
                    <strong>
                        {pendingUninstall?.display_name}
                    </strong>{' '}
                    will remove the modpack and all of its files from
                    this server. This cannot be undone.
                </p>
            )}

            <div className="modpackinstaller-confirm-actions">
                <button
                    type="button"
                    className="modpackinstaller-confirm-cancel"
                    onClick={onCancel}
                    disabled={uninstalling}
                >
                    Cancel
                </button>

                <button
                    type="button"
                    className="modpackinstaller-confirm-danger"
                    onClick={() => {
                        if (pendingUninstall === null) {
                            return;
                        }

                        onConfirm(pendingUninstall);
                    }}
                    disabled={
                        uninstalling
                        || pendingUninstall === null
                    }
                >
                    {uninstalling
                        ? 'Uninstalling ...'
                        : 'Confirm Uninstallation'}
                </button>
            </div>
        </div>
    );
};
