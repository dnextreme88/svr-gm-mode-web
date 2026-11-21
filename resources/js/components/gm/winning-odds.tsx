import { useId, useState } from 'react';
import { cn } from '@/lib/utils';
import type { WinningOddsSide } from '@/types/gm';

/** Segments per bar: each is 5%. */
const SEGMENTS = 20;

function points(n: number): string {
    return n > 0 ? `+${n}` : `${n}`;
}

function chanceText(chance: number): string {
    return `${Number.isInteger(chance) ? chance : chance.toFixed(1)}%`;
}

/** Every side's modifiers as plain text, for the hover tooltip. */
export function oddsBreakdownText(odds: WinningOddsSide[]): string {
    return odds
        .map((side) => {
            const lines = side.factors.length
                ? side.factors.map((f) => `  ${f.label}: ${points(f.points)}`)
                : ['  No modifiers'];

            return [`${side.label}: ${chanceText(side.chance)}`, ...lines].join(
                '\n',
            );
        })
        .join('\n');
}

/**
 * Each side's chance to win a simulated match (house rule, 2026-11-21), as
 * a bar of square 5% segments, like the fatigue meter but not slanted. A
 * click or tap opens what made the odds (no hover-only information); hovering
 * shows them as a tooltip too. Book to Win ignores the odds.
 */
export function WinningOdds({
    odds,
    className,
}: {
    odds: WinningOddsSide[] | null | undefined;
    className?: string;
}) {
    // Opens inline, under the bars: the outcome cards clip anything that
    // sticks out of them, so a floating panel would be cut off.
    const [open, setOpen] = useState(false);
    const breakdownId = useId();

    if (!odds || odds.length < 2) {
        return null;
    }

    const best = Math.max(...odds.map((side) => side.chance));

    return (
        <section
            data-testid="winning-odds"
            aria-label="Winning odds"
            className={cn('relative mx-auto max-w-xl text-left', className)}
        >
            <div className="mb-2 flex items-baseline justify-between gap-3">
                <h4 className="gm-label">Winning odds</h4>
                <button
                    type="button"
                    data-testid="winning-odds-toggle"
                    title={oddsBreakdownText(odds)}
                    aria-expanded={open}
                    aria-controls={breakdownId}
                    onClick={() => setOpen((o) => !o)}
                    className="font-condensed text-sm tracking-wide text-gold-hot uppercase underline decoration-gold/40 decoration-dotted underline-offset-4 hover:decoration-gold"
                >
                    Why these odds?
                </button>
            </div>
            <ul className="space-y-1.5">
                {odds.map((side) => {
                    const filled = (side.chance / 100) * SEGMENTS;

                    return (
                        <li
                            key={side.side}
                            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 sm:grid-cols-[minmax(0,12rem)_1fr_3.5rem]"
                        >
                            <span className="truncate text-sm text-ink">
                                {side.label}
                            </span>
                            <span className="gm-num text-right text-sm text-ink sm:order-last">
                                {chanceText(side.chance)}
                            </span>
                            <div
                                role="meter"
                                aria-label={`${side.label} win chance`}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={side.chance}
                                aria-valuetext={chanceText(side.chance)}
                                className="col-span-2 flex h-2.5 gap-[2px] sm:col-span-1"
                            >
                                {Array.from({ length: SEGMENTS }, (_, i) => {
                                    const fill = Math.max(
                                        0,
                                        Math.min(1, filled - i),
                                    );

                                    return (
                                        <span
                                            key={i}
                                            className="relative flex-1 overflow-hidden bg-white/10"
                                        >
                                            <span
                                                className={cn(
                                                    'absolute inset-y-0 left-0',
                                                    side.chance === best
                                                        ? 'bg-gold-hot'
                                                        : 'bg-ink-dim',
                                                )}
                                                style={{
                                                    width: `${fill * 100}%`,
                                                }}
                                            />
                                        </span>
                                    );
                                })}
                            </div>
                        </li>
                    );
                })}
            </ul>
            {open && (
                <div
                    id={breakdownId}
                    className="mt-3 border-t border-white/10 pt-3 text-sm"
                    data-testid="winning-odds-breakdown"
                >
                    <p className="mb-2 text-ink-dim">
                        Every side starts level. Each modifier is percentage
                        points: a side&apos;s own points add to it and come off
                        the other sides. Book to Win ignores the odds.
                    </p>
                    {odds.map((side) => (
                        <div key={side.side} className="mt-2 first:mt-0">
                            <div className="flex justify-between gap-3 font-semibold">
                                <span className="min-w-0 truncate">
                                    {side.label}
                                </span>
                                <span className="gm-num">
                                    {chanceText(side.chance)}
                                </span>
                            </div>
                            {side.factors.length === 0 ? (
                                <p className="text-ink-faint">No modifiers</p>
                            ) : (
                                <dl className="grid grid-cols-[1fr_auto] gap-x-4">
                                    {side.factors.map((f) => (
                                        <div key={f.label} className="contents">
                                            <dt className="text-ink-dim">
                                                {f.label}
                                            </dt>
                                            <dd
                                                className={cn(
                                                    'gm-num text-right',
                                                    f.points < 0
                                                        ? 'text-zone-serious'
                                                        : 'text-gold-hot',
                                                )}
                                            >
                                                {points(f.points)}%
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
