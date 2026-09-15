import React from 'react';
import { CancelIcon, CheckIcon } from '../icons';

interface ReplaceConfirmBodyProps {
    onCancel: () => void;
    onConfirm: () => void;
}

export const ReplaceConfirmBody = ({
    onCancel,
    onConfirm,
}: ReplaceConfirmBodyProps) => {
    return (
        <div className="modpackinstaller-confirm">
            <p>
                Installing this modpack will replace your existing
                modpack. Do you still want to proceed?
            </p>

            <div className="modpackinstaller-confirm-actions">
                <button
                    type="button"
                    className="modpackinstaller-confirm-stay"
                    onClick={onCancel}
                >
                    <CancelIcon />
                    Cancel
                </button>

                <button
                    type="button"
                    className="modpackinstaller-confirm-accept"
                    onClick={onConfirm}
                >
                    <CheckIcon />
                    Yes
                </button>
            </div>
        </div>
    );
};