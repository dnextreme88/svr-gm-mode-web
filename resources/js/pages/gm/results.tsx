import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import { BookToWinCard } from '@/components/gm/book-to-win-card';
import { SideNames } from '@/components/gm/champion-tag';
import { FatigueProgress } from '@/components/gm/fatigue-progress';
import type { FatigueStep } from '@/components/gm/fatigue-progress';
import { OutcomeCard } from '@/components/gm/outcome-card';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import { brandName } from '@/lib/gm';
import gm from '@/routes/gm';
import { StipulationLabels } from '@/types/enums';
import type { Brand } from '@/types/enums';
import type {
    CardShow,
    GamePageProps,
    MatchOutcome,
    PendingMatch,
    ResultPromo,
} from '@/types/gm';

type ShowResult = CardShow & {
    outcomes: Array<MatchOutcome | PendingMatch>;
    promos?: ResultPromo[];
    fatigue: FatigueStep[];
};

/** How long a decided match's result stays up before the next match shows. */
export const NEXT_MATCH_DELAY_MS = 5000;

type Props = GamePageProps & {
    week_number: number;
    season_week?: number;
    /** Whose shows these are; the Schedule calendar can open the rival's. */
    brand?: Brand;
    /** The show clicked on the calendar: its first result opens. */
    focus_show_id?: number | null;
    /** The match linked from Title History: its result opens. */
    focus_match_id?: number | null;
    from_schedule?: boolean;
    /** The week is airing live: the player's matches are still being decided. */
    airing?: boolean;
    shows: ShowResult[];
    new_emails: number;
};

type ReelItem = {
    show: ShowResult;
    outcome: MatchOutcome | PendingMatch;
    isMainEvent: boolean;
};

type Row =
    | { kind: 'match'; outcome: MatchOutcome | PendingMatch }
    | { kind: 'promo'; promo: ResultPromo };

const PROMO_STATUS: Record<
    NonNullable<ResultPromo['status']>,
    { label: string; className: string }
> = {
    succeeded: { label: 'Succeeded', className: 'text-zone-safe' },
    failed: { label: 'Failed', className: 'text-zone-serious' },
    fizzled: { label: 'Fizzled', className: 'text-zone-serious' },
    backfired: { label: 'Backfired', className: 'text-zone-serious' },
};

/**
 * Matches and promos in air order: each promo sits before the match it aired
 * in front of; promos placed after the last booked match close the show.
 */
function rowsFor(show: ShowResult): Row[] {
    const promos = [...(show.promos ?? [])];
    const rows: Row[] = [];

    for (const outcome of show.outcomes) {
        while (promos.length > 0) {
            const index = promos.findIndex(
                (p) => p.before_position <= outcome.position,
            );

            if (index === -1) {
                break;
            }

            rows.push({ kind: 'promo', promo: promos.splice(index, 1)[0] });
        }

        rows.push({ kind: 'match', outcome });
    }

    return [
        ...rows,
        ...promos.map((promo) => ({ kind: 'promo' as const, promo })),
    ];
}

/**
 * The week's results (§20): every result on one list, with the promos where
 * they aired. A result's chevron opens its full outcome card beside the
 * list, with arrows to step through the card, and the show's fatigue
 * progression underneath.
 *
 * Live results: while the week airs, the player's matches are decided here
 * in air order. The next one opens a card with Book to Win for each side and
 * Simulate match; Simulate all decides the rest. Fatigue shows once the
 * whole week is in.
 */
export default function Results() {
    const {
        game,
        week_number,
        season_week,
        brand,
        focus_show_id,
        focus_match_id,
        from_schedule,
        airing = false,
        shows,
        new_emails,
    } = usePage<Props>().props;
    const rival = brand !== undefined && brand !== game.player_brand;
    const [busy, setBusy] = useState(false);
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
    // The card on the right always shows a match: the one asked for, else
    // the next to decide while the week airs, else the first.
    const [open, setOpen] = useState<number | null>(() => {
        if (reel.length === 0) {
            return null;
        }

        const index = reel.findIndex((r) =>
            focus_match_id
                ? r.outcome.id === focus_match_id
                : focus_show_id
                  ? r.show.id === focus_show_id
                  : r.outcome.pending === true && r.outcome.is_next,
        );

        return index === -1 ? 0 : index;
    });
    // The match just decided: once its result is in, the card moves on to
    // the next match to decide after a short look at the result.
    const [decided, setDecided] = useState<number | null>(null);
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

    useEffect(() => {
        const result = reel.find((r) => r.outcome.id === decided);

        if (decided === null || !result || result.outcome.pending) {
            return;
        }

        const next = reel.findIndex(
            (r) => r.outcome.pending === true && r.outcome.is_next,
        );

        if (next === -1) {
            setDecided(null);

            return;
        }

        const timer = setTimeout(() => {
            setDirection('right');
            setOpen(next);
            setDecided(null);
        }, NEXT_MATCH_DELAY_MS);

        return () => clearTimeout(timer);
    }, [decided, reel]);

    // Picking a match shows it; there's nothing to close. It also cancels
    // the move to the next match.
    const select = (index: number) => {
        setDecided(null);
        setDirection(open !== null && index < open ? 'left' : 'right');
        setOpen(index);
    };

    const post = (
        url: string,
        data: Record<string, number | null | Array<number | null>> = {},
    ) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };

    const decide = (
        match: PendingMatch,
        side: number | null,
        bouts: Array<number | null> = [],
    ) => {
        setDecided(match.id);
        post(gm.results.decide.url({ save: game.id, week: week_number }), {
            slot_id: match.id,
            winner_side: side,
            ...(match.gauntlet ? { bouts } : {}),
        });
    };

    return (
        <>
            <Head title={`Week ${week_number} results`} />
            <PageTitle
                kicker={`Week ${season_week ?? week_number}${rival && brand ? ` · ${brandName(brand)}` : ''}`}
                title={
                    rival
                        ? 'Rival results'
                        : airing
                          ? 'On air'
                          : 'That’s a wrap'
                }
            >
                {airing && !rival && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost"
                        disabled={busy}
                        onClick={() =>
                            post(
                                gm.results.simulateAll.url({
                                    save: game.id,
                                    week: week_number,
                                }),
                            )
                        }
                    >
                        Simulate all
                    </button>
                )}
                {from_schedule ? (
                    <Link href={gm.schedule.url(game.id)} className="gm-btn">
                        Back to schedule
                    </Link>
                ) : airing ? (
                    <button
                        type="button"
                        className="gm-btn"
                        disabled
                        title="Decide every match first"
                    >
                        Continue
                    </button>
                ) : (
                    <Link href={gm.news.url(game.id)} className="gm-btn">
                        Continue
                    </Link>
                )}
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
                                    {rowsFor(show).map((row) => {
                                        if (row.kind === 'promo') {
                                            return (
                                                <PromoRow
                                                    key={`promo-${row.promo.position}`}
                                                    showId={show.id}
                                                    promo={row.promo}
                                                />
                                            );
                                        }

                                        const o = row.outcome;
                                        const index = reel.findIndex(
                                            (r) =>
                                                r.show.id === show.id &&
                                                r.outcome.position ===
                                                    o.position,
                                        );

                                        return (
                                            <MatchRow
                                                key={o.position}
                                                show={show}
                                                outcome={o}
                                                selected={index === open}
                                                onSelect={() => select(index)}
                                            />
                                        );
                                    })}
                                </ol>
                            </Panel>
                        ))}

                        {new_emails > 0 && !from_schedule && !airing && (
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
                                    key={`${open}-${current.outcome.pending ? 'pending' : 'done'}`}
                                    className={
                                        direction === 'right'
                                            ? 'animate-gm-slide-from-right'
                                            : 'animate-gm-slide-from-left'
                                    }
                                >
                                    {current.outcome.pending ? (
                                        <BookToWinCard
                                            match={current.outcome}
                                            showName={current.show.name}
                                            isMainEvent={current.isMainEvent}
                                            busy={busy}
                                            onDecide={(side, bouts) =>
                                                decide(
                                                    current.outcome as PendingMatch,
                                                    side,
                                                    bouts,
                                                )
                                            }
                                        />
                                    ) : (
                                        <OutcomeCard
                                            outcome={current.outcome}
                                            showName={current.show.name}
                                            isMainEvent={current.isMainEvent}
                                        />
                                    )}
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

                        {airing ? (
                            <Panel title="Fatigue this week">
                                <p
                                    data-testid="fatigue-pending"
                                    className="text-sm text-ink-faint"
                                >
                                    Fatigue progression for your superstars are
                                    updated until you resolve all matches.
                                </p>
                            </Panel>
                        ) : (
                            <FatigueProgress
                                key={(current ?? reel[0]).show.id}
                                wrestlers={(current ?? reel[0]).show.fatigue}
                                config={game.fatigue_config}
                            />
                        )}
                    </div>
                </div>
            )}
        </>
    );
}

function MatchRow({
    show,
    outcome: o,
    selected,
    onSelect,
}: {
    show: ShowResult;
    outcome: MatchOutcome | PendingMatch;
    selected: boolean;
    onSelect: () => void;
}) {
    const mainEvent = o.position === show.match_slot_count;

    return (
        <li data-testid={`result-${show.id}-${o.position}`}>
            {/* The whole row shows the result's card; picking it again keeps it. */}
            <button
                type="button"
                onClick={onSelect}
                aria-current={selected || undefined}
                aria-label={`Show details for ${show.name} match ${o.position}`}
                className={cn(
                    'group block w-full border-l-2 px-4 py-3 text-left transition-colors hover:bg-brand',
                    selected ? 'border-brand bg-white/5' : 'border-transparent',
                    o.pending && !o.is_next && 'opacity-60',
                )}
            >
                <span className="flex items-start justify-between gap-3">
                    <span className="text-lg font-semibold group-hover:text-white">
                        <span
                            data-testid={`match-label-${show.id}-${o.position}`}
                            className={cn(
                                'gm-label mr-2 align-middle text-sm',
                                mainEvent ? 'text-gold' : 'text-ink-dim',
                                'group-hover:text-white',
                            )}
                        >
                            {mainEvent ? 'Main Event' : `M${o.position}`}
                        </span>
                        {o.pending ? (
                            o.sides.map((side, i) => (
                                <Fragment key={side.side}>
                                    {i > 0 && (
                                        <span className="mx-1.5 text-ink-faint group-hover:text-white/70">
                                            vs
                                        </span>
                                    )}
                                    <SideNames
                                        names={side.members.map((m) => m.name)}
                                        champions={o.champions}
                                        captains={o.captains}
                                        factions={o.factions}
                                        manager={side.manager}
                                    />
                                </Fragment>
                            ))
                        ) : (
                            <>
                                <SideNames
                                    names={o.winners}
                                    champions={o.champions}
                                    captains={o.captains}
                                    factions={o.factions}
                                    manager={o.winner_manager}
                                />
                                <span className="mx-1.5 text-ink-faint group-hover:text-white/70">
                                    def.
                                </span>
                                {o.loser_sides.map((side, i) => (
                                    <Fragment key={side.join()}>
                                        {i > 0 && ', '}
                                        <SideNames
                                            names={side}
                                            champions={o.champions}
                                            captains={o.captains}
                                            factions={o.factions}
                                            manager={o.loser_side_managers?.[i]}
                                        />
                                    </Fragment>
                                ))}
                            </>
                        )}
                    </span>
                    <span className="flex shrink-0 items-center gap-1 whitespace-nowrap">
                        {o.pending ? (
                            o.is_next ? (
                                <span className="gm-btn gm-btn-gold gm-btn-sm">
                                    Pending Result
                                </span>
                            ) : (
                                <span className="gm-label text-ink-faint">
                                    Waiting
                                </span>
                            )
                        ) : (
                            <span className="gm-label group-hover:text-white">
                                {o.method_label}
                            </span>
                        )}
                        <ChevronRight
                            aria-hidden
                            data-testid="result-chevron"
                            strokeWidth={3}
                            className={cn(
                                'size-6 text-ink-dim transition-transform duration-300 group-hover:text-white',
                                selected && 'rotate-180 text-brand-hot',
                            )}
                        />
                    </span>
                </span>
                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-dim group-hover:text-white/85">
                    <span>{o.match_type}</span>
                    {o.title && (
                        <span className="bg-gradient-to-b from-gold-hot to-gold px-2 py-px font-condensed text-xs font-bold tracking-[0.14em] text-arena uppercase">
                            ◆ {o.title}
                        </span>
                    )}
                    {!o.pending && o.title_banner === 'new_champion' && (
                        <span className="font-bold text-gold-hot group-hover:text-white">
                            NEW CHAMPION
                        </span>
                    )}
                </span>
                {o.stipulation && (
                    <span
                        data-testid={`stipulation-${show.id}-${o.position}`}
                        className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-dim group-hover:text-white/85"
                    >
                        <span className="border border-brand-hot/70 px-2 py-px font-condensed text-xs font-bold tracking-[0.14em] text-brand-hot uppercase group-hover:border-white/80 group-hover:text-white">
                            {StipulationLabels[o.stipulation]}
                        </span>
                        {!o.pending && o.stipulation_outcome && (
                            <span>{o.stipulation_outcome}</span>
                        )}
                    </span>
                )}
            </button>
        </li>
    );
}

function PromoRow({ showId, promo }: { showId: number; promo: ResultPromo }) {
    const status = promo.status === null ? null : PROMO_STATUS[promo.status];

    return (
        <li
            data-testid={`promo-${showId}-${promo.position}`}
            className="border-l-2 border-transparent px-4 py-3"
        >
            <span className="flex items-start justify-between gap-3">
                <span className="text-lg font-semibold">
                    <span className="gm-label mr-2 align-middle text-sm text-ink-dim">
                        Promo {promo.position}
                    </span>
                    {promo.type}
                </span>
                {status ? (
                    <span
                        className={cn(
                            'gm-label shrink-0 font-bold',
                            status.className,
                        )}
                    >
                        {status.label}
                    </span>
                ) : (
                    <span className="gm-label shrink-0 text-ink-faint">
                        Waiting
                    </span>
                )}
            </span>
            {promo.targets.length > 0 && (
                <span className="mt-1 block text-sm text-ink-dim">
                    {promo.targets.map((t, i) => (
                        <Fragment key={t.id}>
                            {i > 0 && ' & '}
                            <WrestlerName wrestler={t} />
                        </Fragment>
                    ))}
                </span>
            )}
        </li>
    );
}
