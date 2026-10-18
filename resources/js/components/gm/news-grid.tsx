import { useEffect, useRef, useState } from 'react';
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
    payload: Record<string, unknown>;
    promoted: boolean;
};

export type NewsPage = {
    week: number;
    season: number;
    season_week: number;
    headlines: Record<'raw' | 'smackdown' | 'neutral', NewsCard | null>;
    stories: Record<'raw' | 'smackdown', NewsCard[]>;
    ticker: NewsCard[];
    fan_support: null;
};

const slotLabel = {
    raw: 'Raw',
    smackdown: 'SmackDown!',
    neutral: 'WWE',
} as const;

/**
 * The News carousel (§19): one page per week, newest first. Each page has
 * three headline cards, four story cards and a ticker.
 */
export function NewsGrid({ pages }: { pages: NewsPage[] }) {
    const [index, setIndex] = useState(0);
    const [expanded, setExpanded] = useState<number | null>(null);
    const touchStart = useRef<number | null>(null);
    const page = pages[index];

    const go = (delta: number) => {
        setExpanded(null);
        setIndex((i) => Math.min(pages.length - 1, Math.max(0, i + delta)));
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowRight') {
                go(1);
            }

            if (e.key === 'ArrowLeft') {
                go(-1);
            }
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
            <div className="mb-4 flex items-center justify-between gap-3">
                <button
                    type="button"
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                    onClick={() => go(-1)}
                    disabled={index === 0}
                >
                    ◀ Newer
                </button>
                <div className="text-center" aria-live="polite">
                    <div className="gm-label">Season {page.season}</div>
                    <div
                        className="gm-heading text-2xl"
                        data-testid="news-week"
                    >
                        Week {page.season_week}
                    </div>
                </div>
                <button
                    type="button"
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                    onClick={() => go(1)}
                    disabled={index === pages.length - 1}
                >
                    Older ▶
                </button>
            </div>

            <div
                role="group"
                aria-label="Headlines"
                className="grid gap-4 md:grid-cols-3"
            >
                {(['raw', 'smackdown', 'neutral'] as const).map((slot) =>
                    card(page.headlines[slot], slot, 'headline', `h-${slot}`),
                )}
            </div>

            <div
                role="group"
                aria-label="Stories"
                className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
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

            <Ticker items={page.ticker} />
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
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={expanded}
                className="block w-full p-4 text-left"
            >
                <div className="flex items-center justify-between gap-2">
                    <span
                        className={cn(
                            'gm-chyron px-2 py-0.5 font-condensed text-[0.65rem] font-bold tracking-[0.18em] uppercase',
                            slot === 'neutral'
                                ? 'bg-gold text-arena'
                                : 'bg-brand text-white',
                        )}
                    >
                        {slotLabel[slot]}
                    </span>
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
                        !expanded && 'line-clamp-3',
                    )}
                >
                    {item.text}
                </p>
                {expanded && (
                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 border-t border-white/8 pt-3 text-xs text-ink-dim">
                        {Object.entries(item.payload).map(([key, value]) => (
                            <div key={key} className="contents">
                                <dt className="capitalize">
                                    {key.replaceAll('_', ' ')}
                                </dt>
                                <dd className="text-ink">{String(value)}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </button>
        </article>
    );
}

function Ticker({ items }: { items: NewsCard[] }) {
    const entries = [...items.map((i) => i.text), 'Fan support trend: —'];

    return (
        <div
            role="marquee"
            aria-label="News ticker"
            className="mt-4 flex items-stretch overflow-hidden border border-white/8 bg-black/50"
        >
            <span className="z-10 flex items-center bg-brand px-3 font-condensed text-xs font-bold tracking-[0.2em] text-white uppercase">
                Ticker
            </span>
            <div className="relative flex-1 overflow-hidden py-2">
                <ul className="flex w-max animate-gm-ticker gap-10 pl-6 text-sm whitespace-nowrap text-ink-dim hover:[animation-play-state:paused]">
                    {[...entries, ...entries].map((text, i) => (
                        <li
                            key={i}
                            aria-hidden={i >= entries.length}
                            data-testid={
                                i < entries.length ? 'ticker-item' : undefined
                            }
                        >
                            <span className="mr-3 text-brand-hot">◆</span>
                            {text}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
