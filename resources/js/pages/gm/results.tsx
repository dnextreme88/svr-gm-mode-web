import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import { AttendanceChip } from '@/components/gm/attendance-chip';
import type { AttendanceBreakdown } from '@/components/gm/attendance-chip';
import { CashInTag, TitleMark } from '@/components/gm/badges';
import { BookToWinCard } from '@/components/gm/book-to-win-card';
import { BusyLabel } from '@/components/gm/busy-label';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { SideNames } from '@/components/gm/champion-tag';
import { FatigueProgress } from '@/components/gm/fatigue-progress';
import type { FatigueStep } from '@/components/gm/fatigue-progress';
import { OutcomeCard } from '@/components/gm/outcome-card';
import { StoryModal } from '@/components/gm/story-modal';
import type { ConditionalStoryPopup } from '@/components/gm/story-modal';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import { brandName, pluralize, prefersReducedMotion } from '@/lib/gm';
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
    attendance?: number | null;
    attendance_breakdown?: AttendanceBreakdown | null;
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
    /** After a PPV night (house rule, 2026-11-01): the weekly show to book next. */
    continue_to?: { name: string; url: string } | null;
    /** House rule: the briefcase holder may cash in after the world title match that just aired. */
    cash_in_offer?: { holder: string; champion: string; title: string } | null;
    /** House rule (2026-11-07): conditional match stories not yet seen, in air order. */
    conditional_stories?: ConditionalStoryPopup[];
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
/** On phones the result card sits below the list: bring it into view. */
function showCardOnSmallScreens() {
    if (window.matchMedia?.('(max-width: 1023px)').matches !== true) {
        return;
    }

    requestAnimationFrame(() =>
        document.getElementById('result-card')?.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'start',
        }),
    );
}

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
        cash_in_offer = null,
        conditional_stories = [],
        continue_to = null,
        shows,
        new_emails,
    } = usePage<Props>().props;
    const rival = brand !== undefined && brand !== game.player_brand;
    const [busy, setBusy] = useState(false);
    const [action, setAction] = useState<string | null>(null);
    const reel = useMemo<ReelItem[]>(
        () =>
            shows.flatMap((show) => {
                // The Main Event is the last match, never a cash-in placed
                // before or after the card's matches.
                const main = show.outcomes.findLastIndex(
                    (o) =>
                        o.mitb_cash_in !== 'before' &&
                        o.mitb_cash_in !== 'after',
                );

                return show.outcomes.map((outcome, i) => ({
                    show,
                    outcome,
                    isMainEvent: i === main && show.outcomes.length > 1,
                }));
            }),
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
    // Conditional stories (house rule, 2026-11-07): one pop-up at a time;
    // `closed` counts this run's, for "1 of 2".
    const [closed, setClosed] = useState(0);
    const story = busy ? null : (conditional_stories[0] ?? null);
    const storyOpen = story !== null;

    useEffect(() => {
        if (conditional_stories.length === 0) {
            setClosed(0);
        }
    }, [conditional_stories.length]);
    const current = open === null ? undefined : reel[open];

    const go = useCallback(
        (delta: number) => {
            if (open === null || reel.length === 0) {
                return;
            }

            setDirection(delta < 0 ? 'left' : 'right');

            // Step over matches still waiting their turn: they can't be
            // opened from the list either.
            let next = open;

            for (let i = 0; i < reel.length; i++) {
                next = (next + delta + reel.length) % reel.length;

                if (!isWaiting(reel[next].outcome)) {
                    setOpen(next);

                    return;
                }
            }
        },
        [open, reel],
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

        // The move to the next match waits while a story is up.
        if (
            decided === null ||
            !result ||
            result.outcome.pending ||
            storyOpen
        ) {
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
    }, [decided, reel, storyOpen]);

    // Picking a match shows it; there's nothing to close. It also cancels
    // the move to the next match.
    const select = (index: number) => {
        setDecided(null);
        setDirection(open !== null && index < open ? 'left' : 'right');
        setOpen(index);
        showCardOnSmallScreens();
    };

    // `action` names the request in flight, so the button that sent it can
    // show it's working (like Air week's "On air…").
    const post = (
        url: string,
        data: Record<string, number | null | Array<number | null>> = {},
        name: string | null = null,
    ) => {
        setBusy(true);
        setAction(name);
        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setBusy(false);
                setAction(null);
            },
        });
    };

    // House rule: the briefcase holder joins the world title match up next.
    const cashIn = (match: PendingMatch) =>
        post(
            gm.results.cashIn.url({ save: game.id, week: week_number }),
            { slot_id: match.id },
            'cash-in',
        );

    const closeStory = (id: number) => {
        setClosed((n) => n + 1);
        post(
            gm.results.stories.seen.url({
                save: game.id,
                week: week_number,
                trigger: id,
            }),
        );
    };

    const answerCashIn = (yes: boolean) =>
        post(gm.results.cashInOffer.url({ save: game.id, week: week_number }), {
            cash_in: yes ? 1 : 0,
        });

    const decide = (
        match: PendingMatch,
        side: number | null,
        bouts: Array<number | null> = [],
    ) => {
        setDecided(match.id);
        post(
            gm.results.decide.url({ save: game.id, week: week_number }),
            {
                slot_id: match.id,
                winner_side: side,
                ...(match.gauntlet ? { bouts } : {}),
            },
            side === null ? 'simulate' : `side-${side}`,
        );
    };

    // Simulate all, while the player's matches wait to be decided. It shows
    // a spinner and "Simulating…" until the results are in.
    const simulateAll = airing && !rival && (
        <button
            type="button"
            className="gm-btn gm-btn-ghost"
            disabled={busy}
            aria-busy={action === 'simulate-all' || undefined}
            onClick={() =>
                post(
                    gm.results.simulateAll.url({
                        save: game.id,
                        week: week_number,
                    }),
                    {},
                    'simulate-all',
                )
            }
        >
            <BusyLabel busy={action === 'simulate-all'} busyText="Simulating…">
                Simulate all
            </BusyLabel>
        </button>
    );

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
                {from_schedule ? (
                    <>
                        {simulateAll}
                        <Link
                            href={gm.schedule.url(game.id)}
                            className="gm-btn"
                        >
                            Back to schedule
                        </Link>
                    </>
                ) : airing ? (
                    <>
                        {simulateAll}
                        <button
                            type="button"
                            className="gm-btn"
                            disabled
                            title="Decide every match to continue"
                        >
                            Continue
                        </button>
                    </>
                ) : continue_to ? (
                    <Link href={continue_to.url} className="gm-btn">
                        Continue to {continue_to.name}
                    </Link>
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
                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                    <div className="min-w-0 space-y-6">
                        {airing && !rival && current?.outcome.pending && (
                            <button
                                type="button"
                                className="gm-btn gm-btn-gold w-full lg:hidden"
                                onClick={showCardOnSmallScreens}
                            >
                                Decide match {current.outcome.position}
                            </button>
                        )}
                        {shows.map((show) => (
                            <Panel
                                key={show.id}
                                title={show.name}
                                titleClassName="text-2xl"
                                bodyClassName="p-0"
                                actions={
                                    <AttendanceChip
                                        attendance={show.attendance ?? null}
                                        breakdown={
                                            show.attendance_breakdown ?? null
                                        }
                                    />
                                }
                            >
                                <ol className="divide-y divide-white/5">
                                    {rowsFor(show).map((row, _, rows) => {
                                        if (row.kind === 'promo') {
                                            // Promos are numbered in the order they air.
                                            const aired = rows
                                                .filter(
                                                    (r) => r.kind === 'promo',
                                                )
                                                .indexOf(row);

                                            return (
                                                <PromoRow
                                                    key={`promo-${row.promo.position}`}
                                                    showId={show.id}
                                                    promo={row.promo}
                                                    number={aired + 1}
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
                                {pluralize(new_emails, 'new email')}.{' '}
                                <Link
                                    href={gm.email.url(game.id)}
                                    className="text-brand-hot underline underline-offset-4"
                                >
                                    Open inbox
                                </Link>
                            </p>
                        )}
                    </div>

                    <div
                        id="result-card"
                        className="min-w-0 scroll-mt-4 space-y-6 lg:sticky lg:top-4"
                    >
                        {current ? (
                            <div
                                data-testid="result-detail"
                                className="overflow-x-clip"
                            >
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
                                            action={action}
                                            onDecide={(side, bouts) =>
                                                decide(
                                                    current.outcome as PendingMatch,
                                                    side,
                                                    bouts,
                                                )
                                            }
                                            onCashIn={() =>
                                                cashIn(
                                                    current.outcome as PendingMatch,
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
                                    Your superstars' fatigue shows here once
                                    every match is decided.
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

            <StoryModal
                story={story}
                index={closed + 1}
                total={closed + conditional_stories.length}
                onClose={() => story && closeStory(story.id)}
            />

            <ConfirmDialog
                open={cash_in_offer !== null && !busy && !storyOpen}
                title="Cash in Money in the Bank?"
                message={
                    cash_in_offer
                        ? `${cash_in_offer.holder} can cash in on ${cash_in_offer.champion} right now. A ${cash_in_offer.title} match goes on next, and the week waits for your answer.`
                        : ''
                }
                cancelLabel="Not now"
                confirmLabel="Cash in"
                onCancel={() => answerCashIn(false)}
                onConfirm={() => answerCashIn(true)}
            />
        </>
    );
}

/** A match still waiting its turn: not up next and not decided. */
function isWaiting(o: MatchOutcome | PendingMatch): boolean {
    return o.pending === true && !o.is_next;
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
    // Results come in one match at a time: a match still waiting its turn
    // is greyed out and can't be opened (no hover, skipped by Tab).
    const waiting = isWaiting(o);

    return (
        <li data-testid={`result-${show.id}-${o.position}`}>
            {/* The whole row shows the result's card; picking it again keeps it. */}
            <button
                type="button"
                onClick={onSelect}
                disabled={waiting}
                aria-current={selected || undefined}
                aria-label={
                    waiting
                        ? `${show.name} match ${o.position} is waiting for the matches before it`
                        : `Show details for ${show.name} match ${o.position}`
                }
                className={cn(
                    'block w-full border-l-2 px-4 py-3 text-left transition-colors',
                    waiting
                        ? 'cursor-not-allowed opacity-60'
                        : 'group hover:bg-brand',
                    selected ? 'border-brand bg-white/5' : 'border-transparent',
                )}
            >
                <span className="flex items-start justify-between gap-3">
                    <span className="min-w-0 text-lg font-semibold group-hover:text-white">
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
                    <span className="flex max-w-[45%] shrink-0 items-center gap-1 text-right sm:max-w-none sm:whitespace-nowrap">
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
                        <span className="inline-flex items-center gap-1 px-2 py-px font-condensed text-sm font-bold tracking-[0.14em] text-gold-hot uppercase">
                            <TitleMark name={o.title} />
                            {o.title}
                            {!o.champions?.length && ' (vacant)'}
                        </span>
                    )}
                    {!o.pending && o.title_banner === 'new_champion' && (
                        <span className="font-bold text-gold-hot group-hover:text-white">
                            NEW CHAMPION
                        </span>
                    )}
                    {o.mitb_cash_in && (
                        <CashInTag className="text-xs group-hover:text-white" />
                    )}
                </span>
                {o.stipulation && (
                    <span
                        data-testid={`stipulation-${show.id}-${o.position}`}
                        className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-dim group-hover:text-white/85"
                    >
                        <span className="border border-brand-hot/70 px-2 py-px font-condensed text-xs font-bold tracking-[0.14em] text-brand-hot uppercase group-hover:border-white/80 group-hover:text-white">
                            {StipulationLabels[o.stipulation]}
                            {o.stipulation_title &&
                                `: ${o.stipulation_title.replace(' Championship', '')}`}
                        </span>
                        {(o.contested_titles ?? []).map((t) => (
                            <span
                                key={t}
                                data-testid="contested-title"
                                className="inline-flex items-center gap-1 px-2 py-px font-condensed text-sm font-bold tracking-[0.14em] text-gold-hot uppercase"
                            >
                                <TitleMark name={t} /> fasfas
                                {t}
                            </span>
                        ))}
                        {!o.pending && o.stipulation_outcome && (
                            <span>{o.stipulation_outcome}</span>
                        )}
                    </span>
                )}
            </button>
        </li>
    );
}

function PromoRow({
    showId,
    promo,
    number,
}: {
    showId: number;
    promo: ResultPromo;
    number: number;
}) {
    const status = promo.status === null ? null : PROMO_STATUS[promo.status];

    return (
        <li
            data-testid={`promo-${showId}-${promo.position}`}
            data-waiting={status === null || undefined}
            className={cn(
                'border-l-2 border-transparent px-4 py-3',
                // Greyed out like a waiting match until it airs.
                status === null && 'opacity-60',
            )}
        >
            <span className="flex items-start justify-between gap-3">
                <span className="text-lg font-semibold">
                    <span className="gm-label mr-2 align-middle text-sm text-ink-dim">
                        Promo {number}
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
            {(promo.targets.length > 0 || promo.title) && (
                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-dim">
                    {promo.targets.length > 0 && (
                        <span>
                            {promo.targets.map((t, i) => (
                                <Fragment key={t.id}>
                                    {i > 0 && ' & '}
                                    <WrestlerName wrestler={t} />
                                </Fragment>
                            ))}
                        </span>
                    )}
                    {promo.title && (
                        <span
                            data-testid={`promo-title-${showId}-${promo.position}`}
                            className="inline-flex items-center gap-1 bg-gradient-to-b from-gold-hot to-gold px-2 py-px font-condensed text-sm font-bold tracking-[0.14em] text-arena uppercase"
                        >
                            <TitleMark name={promo.title} />
                            {promo.title}
                        </span>
                    )}
                </span>
            )}
        </li>
    );
}
