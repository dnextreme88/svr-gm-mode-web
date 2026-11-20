/**
 * Whether a page-wide arrow-key shortcut (the results' outcome card, the
 * News weeks) should leave this key press alone (UI audit, 2026-11-20): the
 * player is typing, moving through a control, inside a dialog, or holding a
 * modifier for the browser's own shortcut.
 */
export function ignoresPageArrows(e: KeyboardEvent): boolean {
    if (
        e.defaultPrevented ||
        e.altKey ||
        e.ctrlKey ||
        e.metaKey ||
        e.shiftKey
    ) {
        return true;
    }

    const target = e.target;

    if (!(target instanceof Element)) {
        return false;
    }

    if (
        target.closest(
            'input, textarea, select, [contenteditable=""], [contenteditable="true"], [role="dialog"], [role="alertdialog"], [role="listbox"], [role="radiogroup"], [role="tablist"], [role="slider"], [role="menu"], [role="grid"]',
        )
    ) {
        return true;
    }

    // A modal open elsewhere on the page (focus may still be on <body>).
    return (
        document.querySelector(
            '[role="dialog"][aria-modal="true"], [role="alertdialog"]',
        ) !== null
    );
}
