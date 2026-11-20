import { Pause, Play } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { DisclosureChevron, DisclosurePanel } from '@/components/gm/disclosure';
import { ignoresPageArrows } from '@/lib/keyboard';
import { useUrlNumber } from '@/lib/use-url-param';
import { cn } from '@/lib/utils';
import { NewsEventTypeLabels } from '@/types/enums';
import type { NewsEventType, NewsTier } from '@/types/enums';

export type NewsCard = {
    id: number;
    type: NewsEventType;
    tier: NewsTier;
    slot: 'raw' | 'smackdown' | 'neutral';
    priority: number;
    text: string;
    summary: string;
    payload: Record<string, unknown>;
    promoted: boolean;
};

export type NewsPage = {
    week: number;
    season: number;
    season_week: number;
    headlines: Record<'raw' | 'smackdown' | 'neutral', NewsCard | null>;
    stories: Record<'raw' | 'smackdown', NewsCard[]>;
    /** News that didn't make a card (match results have their own lines). */
    ticker: NewsCard[];
    /**
     * Each brand's ticker (house rule, 2026-11-17): a line per match on its
     * weekly show; in a week it's on a PPV, the weekly show from the week before.
     */
    tickers?: Record<'raw' | 'smackdown', string[]>;
    /** The week's crowds: each weekly show, and each PPV night. */
    attendance: AttendanceNight[];
};

export type AttendanceNight = {
    name: string;
    ppv: boolean;
    crowd: number;
    /** The brands on that night (a shared PPV has both). */
    brands?: ('raw' | 'smackdown')[];
};

const slotLabel = {
    raw: 'Raw',
    smackdown: 'Smackdown',
    neutral: 'WWE',
} as const;

/**
 * The News carousel (§19): one page per played week, newest first. Each page
 * has three headline cards (Raw, WWE, Smackdown), four story cards and a
 * ticker. Every card pairs its headline with a short summary of why.
 */
export function NewsGrid({
    pages,
    wrapUp = false,
}: {
    pages: NewsPage[];
    /**
     * House rule (2026-11-20): during the end-of-week flow the page stays on
     * the week that just aired, with no Older / Newer buttons, arrow keys or
     * swipes.
     */
    wrapUp?: boolean;
}) {
    // The week on screen lives in the URL (`?week=`, the absolute week), so a
    // refresh or Back keeps it (UI audit, 2026-11-20).
    const newest = pages[0]?.week ?? 0;
    const [week, setWeek] = useUrlNumber('week', newest);
    const found = wrapUp ? 0 : pages.findIndex((p) => p.week === week);
    const index = found === -1 ? 0 : found;
    const [expanded, setExpanded] = useState<number | null>(null);
    const touchStart = useRef<number | null>(null);
    const olderRef = useRef<HTMLButtonElement>(null);
    const newerRef = useRef<HTMLButtonElement>(null);
    const page = pages[index];
    const hasOlder = !wrapUp && index < pages.length - 1;
    const hasNewer = !wrapUp && index > 0;

    const go = (delta: number) => {
        if (wrapUp) {
            return;
        }

        const next =
            pages[Math.min(pages.length - 1, Math.max(0, index + delta))];

        if (next) {
            setExpanded(null);
            setWeek(next.week);
        }
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (
                (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') ||
                ignoresPageArrows(e)
            ) {
                return;
            }

            go(e.key === 'ArrowRight' ? 1 : -1);
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    if (!page) {
        return (
            <p className="text-ink-dim">
                No news yet. Air a week to get the presses rolling.
            </p>
        );
    }

    const card = (
        item: NewsCard | null | undefined,
        slot: NewsCard['slot'],
        kind: 'headline' | 'story',
        key: string,
    ) => (
        <NewsCardView
            key={key}
            item={item ?? null}
            slot={slot}
            kind={kind}
            expanded={item ? expanded === item.id : false}
            onToggle={() =>
                item && setExpanded(expanded === item.id ? null : item.id)
            }
        />
    );

    return (
        <section
            aria-roledescription="carousel"
            aria-label="News by week"
            onTouchStart={(e) =>
                (touchStart.current = e.touches[0]?.clientX ?? null)
            }
            onTouchEnd={(e) => {
                const start = touchStart.current;
                const end = e.changedTouches[0]?.clientX;

                if (
                    start !== null &&
                    end !== undefined &&
                    Math.abs(end - start) > 50
                ) {
                    go(end < start ? 1 : -1);
                }

                touchStart.current = null;
            }}
        >
            <div className="mb-4 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                <div>
                    {/* House rule (2026-11-20): a button with nowhere to go is hidden. */}
                    {hasOlder && (
                        <button
                            ref={olderRef}
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            onClick={() => {
                                // The last page hides Older: focus moves to
                                // Newer instead of dropping to the page.
                                if (index + 1 >= pages.length - 1) {
                                    requestAnimationFrame(() =>
                                        newerRef.current?.focus(),
                                    );
                                }

                                go(1);
                            }}
                        >
                            <span aria-hidden>◀</span> Older
                        </button>
                    )}
                </div>
                <div className="text-center" aria-live="polite">
                    <div className="gm-label">Season {page.season}</div>
                    <div
                        className="gm-heading text-2xl"
                        data-testid="news-week"
                    >
                        Week {page.season_week}
                    </div>
                </div>
                <div className="text-right">
                    {hasNewer && (
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            ref={newerRef}
                            onClick={() => {
                                if (index - 1 <= 0) {
                                    requestAnimationFrame(() =>
                                        olderRef.current?.focus(),
                                    );
                                }

                                go(-1);
                            }}
                        >
                            Newer <span aria-hidden>▶</span>
                        </button>
                    )}
                </div>
            </div>

            <div
                role="group"
                aria-label="Headlines"
                className="grid gap-4 md:grid-cols-3 md:items-start"
            >
                {(['raw', 'neutral', 'smackdown'] as const).map((slot) =>
                    card(page.headlines[slot], slot, 'headline', `h-${slot}`),
                )}
            </div>

            <div
                role="group"
                aria-label="Stories"
                className="mt-4 grid gap-4 sm:grid-cols-2 sm:items-start lg:grid-cols-4"
            >
                {(['raw', 'smackdown'] as const).flatMap((slot) =>
                    [0, 1].map((i) =>
                        card(
                            page.stories[slot][i],
                            slot,
                            'story',
                            `s-${slot}-${i}`,
                        ),
                    ),
                )}
            </div>

            {/* House rule (2026-11-17): a ticker per brand, match results only. */}
            <div className="mt-4 space-y-2">
                {(['raw', 'smackdown'] as const).map((brand) => (
                    <Ticker
                        key={brand}
                        brand={brand}
                        label={slotLabel[brand]}
                        entries={(page.tickers?.[brand] ?? []).map((text) => ({
                            text,
                            gold: false,
                        }))}
                    />
                ))}
            </div>
        </section>
    );
}

function NewsCardView({
    item,
    slot,
    kind,
    expanded,
    onToggle,
}: {
    item: NewsCard | null;
    slot: NewsCard['slot'];
    kind: 'headline' | 'story';
    expanded: boolean;
    onToggle: () => void;
}) {
    const tint = slot === 'neutral' ? undefined : slot;
    const summaryId = useId();
    // A story's header toggles its summary; a headline's is plain.
    const Header = kind === 'story' ? 'button' : 'div';

    if (!item) {
        return (
            <article
                data-brand-tint={tint}
                data-testid={`${kind}-card`}
                className="gm-panel flex min-h-28 items-center justify-center p-4 text-sm text-ink-faint"
            >
                {slotLabel[slot]}: no {kind} this week
            </article>
        );
    }

    return (
        <article
            data-brand-tint={tint}
            data-testid={`${kind}-card`}
            className={cn(
                'gm-panel',
                slot === 'neutral' &&
                    'shadow-[inset_0_2px_0_var(--color-gold)]',
            )}
        >
            <Header
                {...(kind === 'story'
                    ? {
                          type: 'button',
                          onClick: onToggle,
                          'aria-expanded': expanded,
                          'aria-controls': summaryId,
                      }
                    : {})}
                className="block w-full p-4 text-left"
            >
                <div className="flex items-center justify-between gap-2">
                    {slot === 'neutral' ? (
                        <span className="gm-chyron py-0.5 pl-2 font-condensed font-bold tracking-[0.18em] text-arena uppercase [--gm-chyron-bg:var(--color-gold)]">
                            {slotLabel[slot]}
                        </span>
                    ) : (
                        <BrandBug brand={slot} size="sm" className="h-7" />
                    )}
                    <span className="gm-label">
                        {NewsEventTypeLabels[item.type]}
                    </span>
                </div>
                <p
                    className={cn(
                        'mt-3 text-ink',
                        kind === 'headline'
                            ? 'gm-heading text-xl leading-tight font-semibold md:text-2xl'
                            : 'text-sm font-medium',
                        // Headlines are never cut off (UI audit, 2026-11-12).
                    )}
                >
                    {item.text}
                </p>
                {kind === 'story' && (
                    // House rule (2026-11-15): a story's summary starts hidden.
                    <span className="mt-2 inline-flex min-h-11 items-center gap-1 font-condensed text-xs font-semibold tracking-wider text-ink-dim uppercase sm:min-h-0">
                        <DisclosureChevron open={expanded} />
                        {expanded ? 'Hide story' : 'Show story'}
                    </span>
                )}
            </Header>
            {kind === 'story' ? (
                <DisclosurePanel open={expanded} id={summaryId}>
                    <p
                        data-testid="news-summary"
                        className="mx-4 mb-4 border-t border-white/8 pt-3 text-sm leading-relaxed whitespace-pre-line text-ink-dim"
                    >
                        {item.summary}
                    </p>
                </DisclosurePanel>
            ) : (
                <p
                    data-testid="news-summary"
                    className="mx-4 mb-4 border-t border-white/8 pt-3 text-sm leading-relaxed whitespace-pre-line text-ink-dim"
                >
                    {item.summary}
                </p>
            )}
        </article>
    );
}

/** "Fan attendance: Monday Night Raw 15,050 · Friday Night Smackdown 12,300". */
export function attendanceLine(nights: AttendanceNight[]): string | null {
    return nights.length === 0
        ? null
        : `Fan attendance: ${nights.map((n) => `${n.name} ${n.crowd.toLocaleString('en-US')}`).join(' · ')}`;
}

/**
 * One brand's ticker (house rule, 2026-11-17): a scrolling line of its match
 * results, nothing else.
 */
function Ticker({
    label,
    brand,
    entries,
}: {
    label: string;
    brand?: 'raw' | 'smackdown';
    entries: { text: string; gold: boolean }[];
}) {
    // A pause button for keyboard and touch (UI audit, 2026-11-20): hover
    // alone isn't enough to stop moving text.
    const [paused, setPaused] = useState(false);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div
            role="marquee"
            aria-label={`${label} ticker`}
            data-brand-tint={brand}
            data-testid={`ticker-${brand ?? 'neutral'}`}
            className="flex items-stretch overflow-hidden border border-white/8 bg-black/50"
        >
            <span className="z-10 flex w-28 shrink-0 items-center justify-center bg-brand px-3 font-condensed font-bold tracking-[0.2em] text-white uppercase">
                {label}
            </span>
            <div className="relative flex-1 overflow-hidden py-2">
                <ul
                    className={cn(
                        'flex w-max animate-gm-ticker gap-10 pl-6 text-sm whitespace-nowrap text-ink-dim hover:[animation-play-state:paused]',
                        paused && '[animation-play-state:paused]',
                    )}
                >
                    {[...entries, ...entries].map(({ text, gold }, i) => (
                        <li
                            key={i}
                            aria-hidden={i >= entries.length}
                            data-testid={
                                i < entries.length ? 'ticker-item' : undefined
                            }
                            className={cn(
                                gold && 'font-semibold text-gold-hot',
                            )}
                        >
                            <span aria-hidden className="mr-3 text-brand-hot">
                                ◆
                            </span>
                            {text}
                        </li>
                    ))}
                </ul>
            </div>
            <button
                type="button"
                onClick={() => setPaused((p) => !p)}
                // One name; aria-pressed says whether it's paused, so the
                // state isn't announced twice (UI audit, 2026-11-20).
                aria-pressed={paused}
                aria-label={`Pause the ${label} ticker`}
                title={paused ? 'Play' : 'Pause'}
                className="z-10 flex w-11 shrink-0 items-center justify-center border-l border-white/8 text-ink-dim hover:bg-white/8 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-gold"
            >
                {paused ? (
                    <Play className="size-4" aria-hidden />
                ) : (
                    <Pause className="size-4" aria-hidden />
                )}
            </button>
        </div>
    );
}
