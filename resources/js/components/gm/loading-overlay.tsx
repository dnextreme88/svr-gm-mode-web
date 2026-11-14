/**
 * How long a loading overlay shows before the page moves on, so it doesn't
 * just flash: starting the season, and the end of the initial draft.
 */
export const LOADING_OVERLAY_DELAY_MS = 2500;

/** The pause once a player has seen this overlay before (UI audit, 2026-11-12). */
export const LOADING_OVERLAY_REPEAT_MS = 800;

/**
 * The full pause the first time a player sees an overlay (`key`), a short
 * one after that, so returning players aren't held up every time. Browser
 * storage is a convenience: when it's unavailable the full pause is used.
 */
export function loadingOverlayDelay(key: string): number {
    const storageKey = `gm.overlay-seen.${key}`;

    try {
        if (window.localStorage.getItem(storageKey) !== null) {
            return LOADING_OVERLAY_REPEAT_MS;
        }

        window.localStorage.setItem(storageKey, '1');
    } catch {
        // Private mode or blocked storage: keep the full pause.
    }

    return LOADING_OVERLAY_DELAY_MS;
}

/**
 * Covers the page with a spinner and a line saying what's happening, until
 * the next page opens.
 */
export function LoadingOverlay({
    text,
    testId,
}: {
    text: string;
    testId?: string;
}) {
    return (
        <div
            role="status"
            aria-live="polite"
            data-testid={testId}
            className="fixed inset-0 z-50 flex items-center justify-center bg-arena/80 px-4 backdrop-blur-[2px]"
        >
            <div className="gm-panel flex items-center gap-4 border-t-2 border-t-gold px-6 py-5">
                <span
                    aria-hidden
                    className="size-8 shrink-0 rounded-full border-4 border-white/10 border-t-gold motion-safe:animate-spin"
                />
                <span className="font-condensed tracking-wider text-ink uppercase">
                    {text}
                </span>
            </div>
        </div>
    );
}
