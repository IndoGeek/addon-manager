import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { DropdownOption } from '../types';

export const Dropdown = ({
    id,
    label,
    value,
    onChange,
    options,
    disabled,
    compact = false,
    icon,
    hideLabel = false,
    /** Render the menu into a document.body portal with fixed viewport
     * coords. Escapes overflow clipping AND containing-block traps from
     * backdrop-filter/transform ancestors (e.g. the version picker). */
    fixedMenu = false,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: DropdownOption[];
    disabled?: boolean;
    /** Compact mode: square icon-only trigger sized like the view toggle. */
    compact?: boolean;
    icon?: React.ReactNode;
    /** Hide the visible heading label (kept for a11y attributes). */
    hideLabel?: boolean;
    fixedMenu?: boolean;
}) => {
    const [open, setOpen] = useState(false);

    // Viewport coordinates for the fixed-position menu variant, measured from the trigger each time the menu opens.
    const [menuCoords, setMenuCoords] = useState<{
        top: number | null;
        bottom: number | null;
        left: number;
        width: number;
    } | null>(null);

    const wrapperRef = useRef<HTMLDivElement | null>(null);

    const menuRef = useRef<HTMLDivElement | null>(null);

    const selected = options.find((option) => option.value === value);

    const focusOption = (index: number) => {
        const menu = menuRef.current;

        if (!menu) {
            return;
        }

        const buttons = Array.from(
            menu.querySelectorAll(
                'button[role="option"]:not(:disabled)',
            ),
        ) as HTMLButtonElement[];

        if (buttons.length === 0) {
            return;
        }

        const clamped =
            ((index % buttons.length) + buttons.length) %
            buttons.length;

        buttons[clamped].focus();
    };

    const onTriggerKeyDown = (
        event: React.KeyboardEvent<HTMLButtonElement>,
    ) => {
        if (event.key === 'Enter' || event.key === ' ') {
            setOpen((current) => !current);
            event.preventDefault();

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();

        if (!open) {
            setOpen(true);
            window.setTimeout(() => {
                focusOption(
                    event.key === 'ArrowUp'
                        ? options.length - 1
                        : 0,
                );
            }, 0);

            return;
        }

        const menu = menuRef.current;

        if (!menu) {
            return;
        }

        const buttons = Array.from(
            menu.querySelectorAll(
                'button[role="option"]:not(:disabled)',
            ),
        ) as HTMLButtonElement[];

        if (buttons.length === 0) {
            return;
        }

        const currentIndex = buttons.findIndex(
            (button) => button === document.activeElement,
        );

        focusOption(
            event.key === 'ArrowDown'
                ? currentIndex + 1
                : currentIndex - 1,
        );
    };

    const onMenuKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'Home') {
            event.preventDefault();
            focusOption(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            focusOption(options.length - 1);
        }
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        if (fixedMenu && wrapperRef.current) {
            const rect = wrapperRef.current.getBoundingClientRect();

            const spaceBelow = window.innerHeight - rect.bottom;

            setMenuCoords(
                spaceBelow >= 290
                    ? {
                        top: rect.bottom + 6,
                        bottom: null,
                        left: rect.left,
                        width: rect.width,
                    }
                    : {
                        top: null,
                        bottom: window.innerHeight - rect.top + 6,
                        left: rect.left,
                        width: rect.width,
                    },
            );
        }

        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;

            // Ignore clicks inside the trigger wrapper AND inside the menu itself — the portal'd menu lives outside the...
            if (
                wrapperRef.current
                && !wrapperRef.current.contains(target)
                && (
                    menuRef.current === null
                    || !menuRef.current.contains(target)
                )
            ) {
                setOpen(false);
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open, fixedMenu]);

    return (
        <div
            ref={wrapperRef}
            className={`modpackinstaller-dropdown${
                compact
                    ? ' modpackinstaller-dropdown--compact'
                    : ''
            }`}
        >
            {!compact && !hideLabel && (
                <label id={`${id}-label`} htmlFor={id}>
                    {label}
                </label>
            )}

            <button
                id={id}
                type="button"
                className="modpackinstaller-dropdown-trigger"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-labelledby={`${id}-label ${id}`}
                aria-label={compact ? label : undefined}
                title={compact ? `${label}: ${selected?.label ?? ''}` : undefined}
                disabled={disabled || options.length === 0}
                onClick={() => setOpen((current) => !current)}
                onKeyDown={onTriggerKeyDown}
            >
                {compact ? (
                    <>
                        {icon}

                        <span className="modpackinstaller-dropdown-value">
                            {label}
                        </span>
                    </>
                ) : (
                    <span className="modpackinstaller-dropdown-value modpackinstaller-dropdown-trigger-inner">
                        {selected?.icon}

                        <span>
                            {selected ? selected.label : 'Any'}
                        </span>
                    </span>
                )}

                <span
                    className="modpackinstaller-dropdown-caret"
                    aria-hidden="true"
                >
                    ▾
                </span>
            </button>

            {open && (fixedMenu ? (
                menuCoords !== null
                && createPortal(
                    <div
                        ref={menuRef}
                        className="modpackinstaller-dropdown-menu modpackinstaller-dropdown-menu--fixed"
                        role="listbox"
                        aria-labelledby={`${id}-label`}
                        onKeyDown={onMenuKeyDown}
                        style={{
                            top: menuCoords.top ?? 'auto',
                            bottom: menuCoords.bottom ?? 'auto',
                            left: menuCoords.left,
                            minWidth: menuCoords.width,
                        }}
                    >
                        {options.map((option) => (
                            <button
                                type="button"
                                key={option.value}
                                role="option"
                                aria-selected={option.value === value}
                                className={`modpackinstaller-dropdown-option${
                                    option.value === value
                                        ? ' modpackinstaller-dropdown-option--active'
                                        : ''
                                }`}
                                disabled={option.disabled}
                                aria-label={compact ? option.label : undefined}
                                title={compact ? option.label : undefined}
                                onClick={() => {
                                    onChange(option.value);
                                    setOpen(false);
                                }}
                            >
                                <span className="modpackinstaller-dropdown-option-label">
                                    {option.icon}

                                    <span>
                                        {option.label}
                                    </span>
                                </span>

                                {option.detail && (
                                    <small>{option.detail}</small>
                                )}
                            </button>
                        ))}
                    </div>,
                    document.body,
                )
            ) : (
                <div
                    ref={menuRef}
                    className="modpackinstaller-dropdown-menu"
                    role="listbox"
                    aria-labelledby={`${id}-label`}
                    onKeyDown={onMenuKeyDown}
                >
                    {options.map((option) => (
                        <button
                            type="button"
                            key={option.value}
                            role="option"
                            aria-selected={option.value === value}
                            className={`modpackinstaller-dropdown-option${
                                option.value === value
                                    ? ' modpackinstaller-dropdown-option--active'
                                    : ''
                            }`}
                            disabled={option.disabled}
                            aria-label={compact ? option.label : undefined}
                            title={compact ? option.label : undefined}
                            onClick={() => {
                                onChange(option.value);
                                setOpen(false);
                            }}
                        >
                            <span className="modpackinstaller-dropdown-option-label">
                                {option.icon}

                                <span>
                                    {option.label}
                                </span>
                            </span>

                            {option.detail && (
                                <small>{option.detail}</small>
                            )}
                        </button>
                    ))}
                </div>
            ))}
        </div>
    );
};
