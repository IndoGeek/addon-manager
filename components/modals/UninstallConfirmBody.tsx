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
    return (
        <div className="modpackinstaller-confirm">
            <p>
                Uninstalling{' '}
                <strong>
                    {pendingUninstall?.display_name}
                </strong>{' '}
                will remove the modpack and all of its files from
                this server. This cannot be undone.
            </p>

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
