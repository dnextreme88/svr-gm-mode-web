import { useEffect, useRef, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { brandName, prefersReducedMotion } from '@/lib/gm';
import { useModalFocus } from '@/lib/use-modal-focus';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';
import type { WrestlerRow } from '@/types/gm';

/**
 * How long a re-draft pick's reel spins, and how long the drafted name
 * shows afterwards. Shorter than the first-pick reel: it plays for every
 * random pick of the re-draft.
 */
export const SUPERSTAR_REEL_SPIN_MS = 2600;
export const SUPERSTAR_REEL_FLASH_MS = 1100;
const REDUCED_MS = 400;

type Named = Pick<WrestlerRow, 'id' | 'name'> & Partial<WrestlerRow>;

/**
 * Slot-machine reveal of a random re-draft pick (house rule, 2026-11-16),
 * and of the rival GM's re-draft tags (2026-11-17: who it protects, who it
 * takes with the manual pick; `heading` and the texts say which),
 * like the first-pick reel on a new career. The pick is already made on
 * the server; the reel runs through everyone it could have been (`pool`)
 * and stops on `result`, then calls `onDone` so the page can play the move.
 */
export function SuperstarReel({
    pool,
    result,
    brand,
    pickNumber,
    onDone,
    heading,
    spinningText,
    landedText,
}: {
    pool: Named[];
    result: Named;
    brand: Brand;
    pickNumber?: number;
    onDone: () => void;
    /** What the reel is for, above it (default "Pick N"). */
    heading?: string;
    /** The line while it spins (default "{Brand} is drafting…"). */
    spinningText?: string;
    /** The line once it stops (default "{Brand} drafts {name}!"). */
    landedText?: string;
}) {
    const title = heading ?? `Pick ${pickNumber ?? ''}`.trim();
    // The faces it cycles through: the pool, with the result always in it.
    const faces = pool.some((w) => w.id === result.id)
        ? pool
        : [...pool, result];
    const [index, setIndex] = useState(0);
    const [landed, setLanded] = useState(false);
    const facesRef = useRef(faces);
    const onDoneRef = useRef(onDone);

    useEffect(() => {
        facesRef.current = faces;
        onDoneRef.current = onDone;
    });

    useEffect(() => {
        const reduced = prefersReducedMotion();
        const spinMs = reduced ? REDUCED_MS : SUPERSTAR_REEL_SPIN_MS;
        const started = Date.now();
        let timer: ReturnType<typeof setTimeout>;

        const tick = () => {
            const elapsed = Date.now() - started;

            if (elapsed >= spinMs) {
                setIndex(
                    Math.max(
                        0,
                        facesRef.current.findIndex((w) => w.id === result.id),
                    ),
                );
                setLanded(true);
                timer = setTimeout(
                    () => onDoneRef.current(),
                    reduced ? REDUCED_MS : SUPERSTAR_REEL_FLASH_MS,
                );

                return;
            }

            setIndex((current) => (current + 1) % facesRef.current.length);
            // Ease out: fast flicker at first, slowing towards the stop.
            const progress = Math.min(1, elapsed / spinMs);
            timer = setTimeout(tick, 50 + progress * progress * 260);
        };

        timer = setTimeout(tick, 50);

        return () => clearTimeout(timer);
    }, [result.id]);

    const face = faces[index] ?? result;

    // Focus moves in and stays in while it spins (UI audit, 2026-11-20).
    const dialog = useRef<HTMLDivElement>(null);
    useModalFocus(true, dialog);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm outline-none"
            ref={dialog}
            tabIndex={-1}
            role="dialog"
            aria-modal="true"
            aria-label={heading ?? `Re-draft pick ${pickNumber}`}
        >
            <div
                className="gm-panel w-full max-w-md p-8 text-center"
                data-brand-tint={brand}
            >
                <div className="flex items-center justify-center gap-3">
                    <BrandBug brand={brand} size="sm" />
                    <span className="font-condensed text-base tracking-wider text-ink-dim uppercase">
                        {title}
                    </span>
                </div>
                <div
                    className={cn(
                        'relative mx-auto mt-6 flex h-28 items-center justify-center overflow-hidden border-y-2 border-white/15 bg-black/40 px-4',
                        landed &&
                            'border-brand shadow-[0_0_60px_-10px_var(--gm-brand)]',
                    )}
                >
                    <div
                        key={
                            landed ? `landed-${face.id}` : `${index}-${face.id}`
                        }
                        data-testid="superstar-reel-face"
                        data-face={face.id}
                        className={cn(
                            'gm-heading max-w-full text-3xl break-words text-ink md:text-4xl',
                            landed ? 'animate-gm-slam' : 'animate-gm-rise',
                        )}
                    >
                        <WrestlerName
                            wrestler={face as WrestlerRow}
                            portrait="lg"
                        />
                    </div>
                </div>
                <p
                    className={cn(
                        'mt-6 font-condensed text-lg tracking-wider uppercase',
                        // The spinning line pulses while it draws; the
                        // landed line holds still in the brand colour.
                        landed
                            ? 'text-brand-hot'
                            : 'animate-gm-pulse text-ink-dim motion-reduce:animate-none',
                    )}
                    aria-live="polite"
                >
                    {landed
                        ? (landedText ??
                          `${brandName(brand)} drafts ${result.name}!`)
                        : (spinningText ?? `${brandName(brand)} is drafting…`)}
                </p>
            </div>
        </div>
    );
}
