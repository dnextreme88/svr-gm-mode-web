import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { PageTitle } from '@/components/gm/panel';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, BrandScope } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

/** What a show night does when clicked (`ScheduleController::decorate`). */
type AirState =
    | 'aired'
    | 'open'
    | 'confirmed'
    | 'waiting'
    | 'rival'
    | 'upcoming'
    | 'past';

type Air = {
    week: number;
    season_week: number;
    kind: 'weekly' | 'ppv';
    /** Null for a shared (Raw and Smackdown) PPV. */
    brand: Brand | null;
    ppv: { key: string; name: string; brand_scope: BrandScope } | null;
    name: string;
    mine: boolean;
    show_id: number | null;
    state: AirState;
    results_url: string | null;
    card_url: string | null;
    can_simulate: boolean;
};

type Day = { date: string; day: number; in_month: boolean; airs: Air[] };

type Month = { key: string; label: string; rows: Day[][] };

type Props = GamePageProps & {
    season: number;
    seasons: number[];
    months: Month[];
    initial_month: string;
};

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const stateLabel: Record<AirState, string | null> = {
    aired: 'Results',
    // The current week is marked by its animated border, not a word.
    open: null,
    confirmed: 'Booked',
    waiting: 'After PPV',
    rival: null,
    upcoming: null,
    past: null,
};

function shortName(air: Air): string {
    if (air.kind === 'ppv') {
        return air.name;
    }

    return air.brand === 'raw' ? 'Raw' : 'Smackdown';
}

function longDate(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * The season as a calendar (§3 Schedule): weeks start on Sunday, when PPVs
 * air, then Raw on Monday and Smackdown on Friday. Click an aired show for
 * its results (the rival's too), this week's cards to book them in date
 * order, or one of your own later show nights to simulate up to it.
 */
export default function Schedule() {
    const { game, season, seasons, months, initial_month } =
        usePage<Props>().props;
    const [index, setIndex] = useState(() =>
        Math.max(
            0,
            months.findIndex((m) => m.key === initial_month),
        ),
    );
    const [simulate, setSimulate] = useState<{ air: Air; date: string } | null>(
        null,
    );
    // Simulate to here, a week at a time: how many weeks are done out of
    // how many, and the last one aired. Null while it isn't running.
    const [progress, setProgress] = useState<{
        done: number;
        total: number;
        last: number | null;
    } | null>(null);
    const simulating = progress !== null;
    // Cancel while it runs: stop after the week in progress.
    const stopRequested = useRef(false);
    // Which way the last arrow went; null on first load, so nothing slides then.
    const [direction, setDirection] = useState<'next' | 'previous' | null>(
        null,
    );
    const month = months[index];
    const slide =
        direction === 'next'
            ? 'animate-gm-slide-from-below'
            : direction === 'previous'
              ? 'animate-gm-slide-from-above'
              : undefined;

    const go = (delta: -1 | 1) => {
        setDirection(delta === 1 ? 'next' : 'previous');
        setIndex((i) => Math.min(months.length - 1, Math.max(0, i + delta)));
    };

    if (!month) {
        return null;
    }

    const open = (air: Air, date: string) => {
        const href = air.results_url ?? air.card_url;

        if (href) {
            router.visit(href);
        } else if (air.can_simulate) {
            setSimulate({ air, date });
        }
    };

    return (
        <>
            <Head title="Schedule" />
            <PageTitle kicker={`Season ${season}`} title="Schedule">
                {seasons.length > 1 && (
                    <nav
                        aria-label="Seasons"
                        className="flex flex-wrap items-center gap-1"
                    >
                        <span className="gm-label mr-1">Season</span>
                        {seasons.map((n) => (
                            <Link
                                key={n}
                                href={gm.schedule.url(game.id, {
                                    query:
                                        n === game.season ? {} : { season: n },
                                })}
                                aria-current={n === season ? 'page' : undefined}
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    n !== season && 'gm-btn-ghost',
                                )}
                            >
                                {n}
                            </Link>
                        ))}
                    </nav>
                )}
            </PageTitle>

            <section
                aria-label="Calendar"
                className="gm-panel mx-auto p-3 sm:p-5"
            >
                <header className="mb-4 flex items-center justify-between gap-3">
                    <h2
                        key={month.key}
                        className={cn('gm-heading text-2xl text-ink', slide)}
                        data-testid="calendar-month"
                    >
                        {month.label}
                    </h2>
                    <div className="flex gap-1">
                        <button
                            type="button"
                            aria-label="Previous month"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={index === 0}
                            onClick={() => go(-1)}
                        >
                            <ChevronUp className="size-4" />
                        </button>
                        <button
                            type="button"
                            aria-label="Next month"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={index === months.length - 1}
                            onClick={() => go(1)}
                        >
                            <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>

                <div role="grid" aria-label={month.label}>
                    <div role="row" className="grid grid-cols-7 gap-1">
                        {weekdays.map((d) => (
                            <div
                                key={d}
                                role="columnheader"
                                className="pb-2 text-center font-condensed text-xs font-bold tracking-wider text-ink-dim uppercase"
                            >
                                {d}
                            </div>
                        ))}
                    </div>
                    {/* Keyed by month so each flip replays the slide. */}
                    <div
                        key={month.key}
                        role="rowgroup"
                        data-testid="calendar-days"
                        className={slide}
                    >
                        {month.rows.map((row) => (
                            <div
                                key={row[0]?.date}
                                role="row"
                                className="grid grid-cols-7 gap-1"
                            >
                                {row.map((day) => (
                                    <DayCell
                                        key={day.date}
                                        day={day}
                                        onOpen={(air) => open(air, day.date)}
                                    />
                                ))}
                            </div>
                        ))}
                    </div>
                </div>

                <ul className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-ink-dim">
                    <li className="flex items-center gap-1.5">
                        <span className="size-2.5 bg-gold" /> PPV (Sunday)
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span className="size-2.5 bg-brand" /> Your shows
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span className="size-2.5 bg-white/25" /> Rival shows
                    </li>
                    <li>
                        Click an aired show for its results, this week’s show to
                        book it, or one of your later shows to simulate to it.
                    </li>
                </ul>
            </section>

            <ConfirmDialog
                open={simulate !== null}
                title={
                    simulate
                        ? `Simulate to week ${simulate.air.season_week}?`
                        : ''
                }
                message={
                    simulate && (
                        <>
                            The computer books every one of your cards from week{' '}
                            {game.season_week} to week{' '}
                            {simulate.air.season_week - 1} and airs them.
                            Results, fatigue, injuries, title changes,
                            contracts, morale, news and emails all happen as
                            normal. You’ll pick up on week{' '}
                            {simulate.air.season_week} ({simulate.air.name},{' '}
                            {longDate(simulate.date)}). This can’t be undone.
                        </>
                    )
                }
                confirmLabel={simulating ? 'Simulating…' : 'Simulate'}
                confirmDisabled={simulating}
                cancelLabel={simulating ? 'Stop after this week' : 'Cancel'}
                footer={
                    progress && (
                        <SimulationProgress
                            done={progress.done}
                            total={progress.total}
                            last={progress.last}
                            stopping={stopRequested.current}
                        />
                    )
                }
                onCancel={() => {
                    if (simulating) {
                        stopRequested.current = true;
                        setProgress((p) => (p ? { ...p } : p));

                        return;
                    }

                    setSimulate(null);
                }}
                onConfirm={async () => {
                    if (!simulate || simulating) {
                        return;
                    }

                    const target = simulate.air.week;
                    const total = Math.max(1, target - game.week);
                    const aired: number[] = [];
                    let stopped: string | null = null;
                    stopRequested.current = false;
                    setProgress({ done: 0, total, last: null });

                    // One request per week, so the bar follows the real
                    // simulation; Stop takes effect between weeks.
                    while (!stopRequested.current) {
                        try {
                            const step = await requestJson<SimulationStep>(
                                'POST',
                                gm.simulate.step.url(game.id),
                                { week: target },
                            );

                            if (step.week !== null) {
                                aired.push(step.week);
                                setProgress({
                                    done: aired.length,
                                    total,
                                    last: step.season_week,
                                });
                            }

                            stopped = step.stopped;

                            if (step.done) {
                                break;
                            }
                        } catch (e) {
                            stopped =
                                e instanceof Error
                                    ? e.message
                                    : 'Something went wrong.';
                            break;
                        }
                    }

                    if (
                        stopRequested.current &&
                        stopped === null &&
                        aired.length < total
                    ) {
                        stopped = 'you stopped the simulation.';
                    }

                    router.post(
                        gm.simulate.finish.url(game.id),
                        { week: target, weeks: aired, stopped },
                        {
                            onFinish: () => {
                                setProgress(null);
                                setSimulate(null);
                            },
                        },
                    );
                }}
            />
        </>
    );
}

type SimulationStep = {
    /** The absolute week that aired, or null when nothing did. */
    week: number | null;
    season_week: number | null;
    done: boolean;
    stopped: string | null;
};

/**
 * Simulate to here's progress: weeks aired out of the total, as a bar that
 * fills week by week with moving stripes while it works.
 */
function SimulationProgress({
    done,
    total,
    last,
    stopping,
}: {
    done: number;
    total: number;
    last: number | null;
    stopping: boolean;
}) {
    const percent = Math.round((done / total) * 100);

    return (
        <div className="space-y-1.5" data-testid="simulation-progress">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-ink-dim" aria-live="polite">
                    {stopping
                        ? 'Stopping after this week…'
                        : last === null
                          ? 'Booking and airing the first week…'
                          : `Week ${last} aired.`}
                </span>
                <span className="gm-num text-ink">
                    {done} / {total} weeks
                </span>
            </div>
            <div
                role="progressbar"
                aria-label="Simulation progress"
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={done}
                aria-valuetext={`${done} of ${total} weeks simulated`}
                className="h-2.5 overflow-hidden bg-white/10"
            >
                <div
                    className="gm-progress-fill h-full bg-brand transition-[width] duration-500 ease-out"
                    style={{ width: `${Math.max(percent, 4)}%` }}
                />
            </div>
        </div>
    );
}

/**
 * A clickable show's hover, in its brand's colour: Raw red, Smackdown blue.
 * PPVs stay gold and brighten.
 */
function hoverFor(air: Air): string {
    if (air.kind === 'ppv' || air.brand === null) {
        return 'hover:brightness-125';
    }

    return air.brand === 'raw'
        ? 'hover:bg-raw hover:text-white hover:brightness-125'
        : 'hover:bg-sd hover:text-white hover:brightness-125';
}

function DayCell({ day, onOpen }: { day: Day; onOpen: (air: Air) => void }) {
    // The player's own cards this week; the rival's shows aren't highlighted.
    const thisWeek = day.airs.some((a) =>
        ['open', 'confirmed', 'waiting'].includes(a.state),
    );

    return (
        <div
            role="gridcell"
            data-date={day.date}
            aria-current={
                day.airs.some((a) => a.state === 'open') ? 'date' : undefined
            }
            className={cn(
                'flex min-h-16 flex-col gap-1 border border-transparent p-1 sm:min-h-24 sm:p-1.5',
                day.in_month ? 'bg-white/[0.03]' : 'opacity-35',
                thisWeek && 'gm-current-week',
            )}
        >
            <span
                className={cn(
                    'gm-num text-sm',
                    day.in_month ? 'text-ink' : 'text-ink-faint',
                )}
            >
                {day.day}
            </span>
            {day.airs.map((air) => {
                const clickable =
                    air.results_url !== null ||
                    air.card_url !== null ||
                    air.can_simulate;
                const label = stateLabel[air.state];

                return (
                    <button
                        key={`${air.kind}-${air.brand ?? 'shared'}`}
                        type="button"
                        disabled={!clickable}
                        data-testid={`air-${day.date}-${air.kind}-${air.brand ?? 'shared'}`}
                        title={
                            air.can_simulate
                                ? `Simulate to week ${air.season_week}`
                                : air.name
                        }
                        aria-label={`${air.name}, week ${air.season_week}${label ? `: ${label}` : ''}${air.state === 'open' ? ': this week, to book' : ''}${air.can_simulate ? ': simulate to here' : ''}`}
                        onClick={() => onOpen(air)}
                        className={cn(
                            'w-full truncate px-1 py-0.5 text-left font-condensed text-xs leading-tight tracking-wide uppercase sm:px-1.5',
                            air.kind === 'ppv'
                                ? 'bg-gold/90 font-bold text-arena'
                                : air.mine
                                  ? 'bg-brand font-semibold text-white'
                                  : 'bg-white/12 text-ink-dim',
                            air.state === 'aired' && 'opacity-70',
                            clickable
                                ? cn('cursor-pointer', hoverFor(air))
                                : 'cursor-default',
                            air.state === 'waiting' && 'opacity-60',
                        )}
                    >
                        {air.state === 'aired' && (
                            <span aria-hidden className="mr-1">
                                ✓
                            </span>
                        )}
                        {shortName(air)}
                        {label && air.state !== 'aired' && (
                            <span className="ml-1 hidden font-normal normal-case opacity-80 sm:inline">
                                · {label}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
