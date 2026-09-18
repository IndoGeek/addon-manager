import React from 'react';
import { StatusMessage } from '../types';

/** A button carried by a notification, e.g. retrying the load that just failed. */
export interface ToastAction {
    label: string;
    onClick: () => void;
}

/** A notification popup; the id is what dismissal and the auto-close timer key on. */
export interface ToastMessage extends StatusMessage {
    id: number;
    /** Stable identity: a repeat of the same notification replaces its own popup. */
    key: string;
    action?: ToastAction;
}

/** How long a notification stays on screen before it dismisses itself. */
export const TOAST_TIMEOUT_MS = 10_000;

/**
 * Page-level notification popups. They sit above every modal and stay
 * visible while the panel scrolls, so a load that fails behind a closed
 * window is still reported.
 */
export const ToastStack = ({
    toasts,
    onDismiss,
}: {
    toasts: ToastMessage[];
    onDismiss: (id: number) => void;
}) => {
    if (toasts.length === 0) {
        return null;
    }

    return (
        <div
            className="modpackinstaller-toasts"
            role="region"
            aria-label="Notifications"
        >
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    className={`modpackinstaller-toast modpackinstaller-toast--${toast.kind}`}
                    role={toast.kind === 'error' ? 'alert' : 'status'}
                >
                    <span className="modpackinstaller-toast-message">
                        {toast.message}
                    </span>

                    {toast.action && (
                        <button
                            type="button"
                            className="modpackinstaller-toast-action"
                            onClick={() => {
                                onDismiss(toast.id);
                                toast.action?.onClick();
                            }}
                        >
                            {toast.action.label}
                        </button>
                    )}

                    <button
                        type="button"
                        className="modpackinstaller-toast-dismiss"
                        onClick={() => onDismiss(toast.id)}
                        aria-label="Dismiss notification"
                        title="Dismiss"
                    >
                        &times;
                    </button>
                </div>
            ))}
        </div>
    );
};
