import React, { useEffect, useRef, useState } from 'react';

/**
 * Window width family. `confirm` shrinks to the question it asks, `narrow`
 * is the fixed narrower column used by the update picker; both centre their
 * heading, since their titles are short enough to read better in the middle.
 */
export type ModalVariant = 'default' | 'confirm' | 'narrow';

export const Modal = ({
    open,
    labelledBy,
    title,
    onClose,
    headerActions,
    children,
    busy,
    variant = 'default',
}: {
    open: boolean;
    labelledBy: string;
    title: string;
    onClose: () => void;
    headerActions?: React.ReactNode;
    children: React.ReactNode;
    busy?: boolean;
    variant?: ModalVariant;
}) => {
    const [shown, setShown] = useState(open);

    const [phase, setPhase] = useState<'in' | 'out'>('in');

    const closeRef = useRef<HTMLButtonElement | null>(null);

    const previousFocus = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (open) {
            setShown(true);
            setPhase('in');

            return undefined;
        }

        if (shown) {
            setPhase('out');

            const timer = window.setTimeout(() => {
                setShown(false);
            }, 200);

            return () => window.clearTimeout(timer);
        }

        return undefined;
    }, [open, shown]);

    useEffect(() => {
        if (!shown || phase !== 'in') {
            return;
        }

        previousFocus.current =
            document.activeElement as HTMLElement | null;

        const timer = window.setTimeout(() => {
            closeRef.current?.focus();
        }, 30);

        return () => window.clearTimeout(timer);
    }, [open, shown, phase]);

    useEffect(() => {
        if (!open) {
            previousFocus.current?.focus();
        }
    }, [open]);

    useEffect(() => {
        if (!open || !shown) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = prevOverflow;
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open, shown, onClose]);

    if (!shown) {
        return null;
    }

    const variantClass = variant === 'default'
        ? ''
        : ` modpackinstaller-modal--${variant}`;

    return (
        <div
            className={`modpackinstaller-modal-overlay${
                phase === 'out'
                    ? ' modpackinstaller-modal-overlay--out'
                    : ''
            }`}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div
                className={`modpackinstaller-modal${variantClass}${
                    phase === 'out'
                        ? ' modpackinstaller-modal--out'
                        : ''
                }`}
                role="dialog"
                aria-modal="true"
                aria-labelledby={labelledBy}
                aria-busy={busy}
            >
                <div className="modpackinstaller-modal-header">
                    <h3 id={labelledBy}>{title}</h3>

                    <div className="modpackinstaller-modal-header-actions">
                        {headerActions}

                        <button
                            type="button"
                            ref={closeRef}
                            className="modpackinstaller-modal-close"
                            aria-label="Close"
                            onClick={onClose}
                        >
                            &times;
                        </button>
                    </div>
                </div>

                {children}
            </div>
        </div>
    );
};
