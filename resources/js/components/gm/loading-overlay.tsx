/**
 * How long a loading overlay shows before the page moves on, so it doesn't
 * just flash: starting the season, and the end of the initial draft.
 */
export const LOADING_OVERLAY_DELAY_MS = 2500;

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
