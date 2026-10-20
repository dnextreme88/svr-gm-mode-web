import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FatigueProgress } from '@/components/gm/fatigue-progress';
import type { FatigueStep } from '@/components/gm/fatigue-progress';
import { OutcomeCard } from '@/components/gm/outcome-card';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type ShowResult = CardShow & {
    outcomes: MatchOutcome[];
    fatigue: FatigueStep[];
};

type Props = GamePageProps & {
    week_number: number;
    shows: ShowResult[];
    new_emails: number;
};

type ReelItem = {
    show: ShowResult;
    outcome: MatchOutcome;
    isMainEvent: boolean;
};

/**
 * The week's results (§20): every result on one list. A result's chevron
 * opens its full outcome card beside the list, with arrows to step through
 * the card, and the show's fatigue progression underneath.
 */
export default function Results() {
    const { game, week_number, shows, new_emails } = usePage<Props>().props;
    const reel = useMemo<ReelItem[]>(
        () =>
            shows.flatMap((show) =>
                show.outcomes.map((outcome, i) => ({
                    show,
                    outcome,
                    isMainEvent:
                        i === show.outcomes.length - 1 &&
                        show.outcomes.length > 1,
                })),
            ),
        [shows],
    );
    const [open, setOpen] = useState<number | null>(null);
    const [direction, setDirection] = useState<'left' | 'right'>('right');
    const current = open === null ? undefined : reel[open];

    const go = useCallback(
        (delta: number) => {
            if (open === null || reel.length === 0) {
                return;
            }

            setDirection(delta < 0 ? 'left' : 'right');
            setOpen((open + delta + reel.length) % reel.length);
        },
        [open, reel.length],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowRight') {
                go(1);
            } else if (e.key === 'ArrowLeft') {
                go(-1);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [go]);

    const toggle = (index: number) => {
        setDirection('right');
        setOpen((o) => (o === index ? null : index));
    };

    return (
        <>
            <Head title={`Week ${week_number} results`} />
            <PageTitle kicker={`Week ${week_number}`} title="That’s a wrap">
                <Link href={gm.news.url(game.id)} className="gm-btn">
                    Continue
                </Link>
            </PageTitle>

            {reel.length === 0 && (
                <EmptyState>No matches were booked this week.</EmptyState>
            )}

            {reel.length > 0 && (
                <div className="grid items-start gap-6 lg:grid-cols-2">
                    <div className="space-y-6">
                        {shows.map((show) => (
                            <Panel
                                key={show.id}
                                title={show.name}
                                titleClassName="text-2xl"
                                bodyClassName="p-0"
                            >
                                <ol className="divide-y divide-white/5">
                                    {show.outcomes.map((o) => {
                                        const index = reel.findIndex(
                                            (r) =>
                                                r.show.id === show.id &&
                                                r.outcome.position ===
                                                    o.position,
                                        );
                                        const selected = index === open;

                                        return (
                                            <li
                                                key={o.position}
                                                data-testid={`result-${show.id}-${o.position}`}
                                            >
                                                {/* The whole row opens the result's card. */}
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggle(index)
                                                    }
                                                    aria-expanded={selected}
                                                    aria-label={`${selected ? 'Hide' : 'Show'} details for ${show.name} match ${o.position}`}
                                                    className={cn(
                                                        'group block w-full border-l-2 px-4 py-3 text-left transition-colors hover:bg-brand',
                                                        selected
                                                            ? 'border-brand bg-white/5'
                                                            : 'border-transparent',
                                                    )}
                                                >
                                                    <span className="flex items-start justify-between gap-3">
                                                        <span className="text-lg font-semibold group-hover:text-white">
                                                            <span
                                                                data-testid={`match-label-${show.id}-${o.position}`}
                                                                className={cn(
                                                                    'gm-label mr-2 align-middle text-sm',
                                                                    o.position ===
                                                                        show.match_slot_count
                                                                        ? 'text-gold'
                                                                        : 'text-ink-dim',
                                                                    'group-hover:text-white',
                                                                )}
                                                            >
                                                                {o.position ===
                                                                show.match_slot_count
                                                                    ? 'Main Event'
                                                                    : `M${o.position}`}
                                                            </span>
                                                            {o.winners.join(
                                                                ' & ',
                                                            )}
                                                            <span className="mx-1.5 text-ink-faint group-hover:text-white/70">
                                                                def.
                                                            </span>
                                                            {o.losers.join(
                                                                ', ',
                                                            )}
                                                        </span>
                                                        <span className="flex shrink-0 items-center gap-1 whitespace-nowrap">
                                                            <span className="gm-label group-hover:text-white">
                                                                {o.method_label}
                                                            </span>
                                                            <ChevronRight
                                                                aria-hidden
                                                                data-testid="result-chevron"
                                                                strokeWidth={3}
                                                                className={cn(
                                                                    'size-6 text-ink-dim transition-transform duration-300 group-hover:text-white',
                                                                    selected &&
                                                                        'rotate-180 text-brand-hot',
                                                                )}
                                                            />
                                                        </span>
                                                    </span>
                                                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-dim group-hover:text-white/85">
                                                        <span>
                                                            {o.match_type}
                                                        </span>
                                                        {o.title && (
                                                            <span className="bg-gradient-to-b from-gold-hot to-gold px-2 py-px font-condensed text-xs font-bold tracking-[0.14em] text-arena uppercase">
                                                                ◆ {o.title}
                                                            </span>
                                                        )}
                                                        {o.title_banner ===
                                                            'new_champion' && (
                                                            <span className="font-bold text-gold-hot group-hover:text-white">
                                                                NEW CHAMPION
                                                            </span>
                                                        )}
                                                        {o.stipulation_outcome && (
                                                            <span>
                                                                ·{' '}
                                                                {
                                                                    o.stipulation_outcome
                                                                }
                                                            </span>
                                                        )}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </Panel>
                        ))}

                        {new_emails > 0 && (
                            <p className="text-sm text-ink-dim">
                                {new_emails} new email(s).{' '}
                                <Link
                                    href={gm.email.url(game.id)}
                                    className="text-brand-hot underline underline-offset-4"
                                >
                                    Open inbox
                                </Link>
                            </p>
                        )}
                    </div>

                    <div className="space-y-6 lg:sticky lg:top-4">
                        {current ? (
                            <div data-testid="result-detail">
                                <div
                                    key={open}
                                    className={
                                        direction === 'right'
                                            ? 'animate-gm-slide-from-right'
                                            : 'animate-gm-slide-from-left'
                                    }
                                >
                                    <OutcomeCard
                                        outcome={current.outcome}
                                        showName={current.show.name}
                                        isMainEvent={current.isMainEvent}
                                    />
                                </div>
                                <div className="mt-3 flex items-center justify-between">
                                    <span className="gm-num text-lg text-ink-dim">
                                        {(open ?? 0) + 1} / {reel.length}
                                    </span>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            className="gm-btn gm-btn-ghost gm-btn-sm"
                                            onClick={() => go(-1)}
                                            aria-label="Previous result"
                                        >
                                            ◀
                                        </button>
                                        <button
                                            type="button"
                                            className="gm-btn gm-btn-ghost gm-btn-sm"
                                            onClick={() => go(1)}
                                            aria-label="Next result"
                                        >
                                            ▶
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="gm-panel px-4 py-6 text-center text-sm text-ink-faint">
                                Open a result with its{' '}
                                <ChevronRight
                                    aria-hidden
                                    strokeWidth={3}
                                    className="inline size-4 align-middle text-ink"
                                />{' '}
                                to see the full match.
                            </p>
                        )}

                        <FatigueProgress
                            key={(current ?? reel[0]).show.id}
                            wrestlers={(current ?? reel[0]).show.fatigue}
                            config={game.fatigue_config}
                        />
                    </div>
                </div>
            )}
        </>
    );
}
