import { useEffect, useRef, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { brandName, prefersReducedMotion } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';

/** How long the reel spins, and how long the winner flashes afterwards. */
export const REEL_SPIN_MS = 5000;
export const REEL_FLASH_MS = 1600;
const REDUCED_SPIN_MS = 600;

/**
 * Slot-machine reveal of who drafts first. The pick itself is made on the
 * server when the career is created; this only plays it out. It keeps
 * spinning until both the spin time has passed and `result` has arrived.
 */
export function FirstPickReel({
    result,
    onDone,
}: {
    result: Brand | null;
    onDone: () => void;
}) {
    const [face, setFace] = useState<Brand>('raw');
    const [landed, setLanded] = useState<Brand | null>(null);
    const resultRef = useRef(result);
    const onDoneRef = useRef(onDone);

    useEffect(() => {
        resultRef.current = result;
        onDoneRef.current = onDone;
    }, [result, onDone]);

    useEffect(() => {
        const reduced = prefersReducedMotion();
        const spinMs = reduced ? REDUCED_SPIN_MS : REEL_SPIN_MS;
        const started = Date.now();
        let timer: ReturnType<typeof setTimeout>;

        const tick = () => {
            const elapsed = Date.now() - started;
            const winner = resultRef.current;

            if (elapsed >= spinMs && winner !== null) {
                setFace(winner);
                setLanded(winner);
                timer = setTimeout(
                    () => onDoneRef.current(),
                    reduced ? REDUCED_SPIN_MS : REEL_FLASH_MS,
                );

                return;
            }

            setFace((current) => (current === 'raw' ? 'smackdown' : 'raw'));
            // Ease out: fast flicker at first, slowing towards the stop.
            const progress = Math.min(1, elapsed / spinMs);
            timer = setTimeout(tick, 60 + progress * progress * 340);
        };

        timer = setTimeout(tick, 60);

        return () => clearTimeout(timer);
    }, []);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label="Deciding the first pick"
        >
            <div
                className="gm-panel w-full max-w-md p-8 text-center"
                data-brand-tint={landed ?? undefined}
            >
                <div className="gm-label mb-6">Who picks first?</div>
                <div
                    className={cn(
                        'relative mx-auto flex h-32 items-center justify-center overflow-hidden border-y-2 border-white/15 bg-black/40',
                        landed &&
                            'border-brand shadow-[0_0_60px_-10px_var(--gm-brand)]',
                    )}
                >
                    <div
                        key={landed ? `landed-${face}` : face}
                        data-testid="reel-face"
                        data-face={face}
                        className={
                            landed ? 'animate-gm-slam' : 'animate-gm-rise'
                        }
                    >
                        <BrandBug brand={face} size="lg" />
                    </div>
                </div>
                <p
                    className={cn(
                        'mt-6 font-condensed text-lg tracking-wider uppercase',
                        landed
                            ? 'animate-gm-pulse text-brand-hot'
                            : 'text-ink-dim',
                    )}
                    aria-live="polite"
                >
                    {landed ? `${brandName(landed)} picks first!` : 'Spinning…'}
                </p>
            </div>
        </div>
    );
}
