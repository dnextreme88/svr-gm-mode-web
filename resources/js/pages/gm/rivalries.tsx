import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { DisclosureChevron, DisclosurePanel } from '@/components/gm/disclosure';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { WeekCell } from '@/components/gm/week-cell';
import { formatMoney, pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';
import type { GamePageProps, MatchOutcome } from '@/types/gm';

/** A match the rivals were in together (house rule, 2026-11-20). */
export type RivalryMatch = {
    id: number;
    season: number;
    season_week: number;
    ppv: string | null;
    /** That match on its week's results. */
    link: string;
    outcome: MatchOutcome;
};

type RivalryRow = {
    id: number;
    wrestler_a: string;
    wrestler_b: string;
    /** The brand the rivalry is on (house rule, 2026-11-15). */
    brand: Brand | null;
    brand_a: Brand | null;
    brand_b: Brand | null;
    heat: number;
    weeks_active: number;
    started_week: number;
    active: boolean;
    ended_week: number | null;
    /** Newest first; empty before they've met. */
    matches?: RivalryMatch[];
};

type RivalryRules = {
    meetings: number;
    window_weeks: number;
    start_heat: number;
    heat_per_meeting: number;
    heat_per_ppv_meeting: number;
    heat_per_interference: number;
    idle_decay: number;
    milestone_weeks: number;
    start_promo_bars: number;
    continue_promo_bars: number;
    continue_promo_cost: number;
    call_out_heat?: number;
    call_out_cost?: number;
    call_out_chance?: number;
};

type Props = GamePageProps & {
    rivalries: RivalryRow[];
    max_heat: number;
    heating_up: number;
    /** The heat bar's colour steps: orange from the first, red from the second. */
    heat_colors?: [number, number];
    rules?: RivalryRules;
};

const bars = (n: number) => `${n} fatigue ${n === 1 ? 'bar' : 'bars'}`;

/**
 * The heat bar's colour (house rule, 2026-11-20): yellow while it builds
 * (10–40), orange once it heats up (50–70), red when it boils over (80–100).
 */
export function heatColor(heat: number, [orange, red]: [number, number]) {
    return heat >= red
        ? 'bg-zone-serious'
        : heat >= orange
          ? 'bg-zone-warn'
          : 'bg-zone-minor';
}

/** "Edge def. Christian w/ Lita · Hardcore — Single". */
export function outcomeLine(o: MatchOutcome): string {
    const manager = (name: string | null | undefined) =>
        name ? ` w/ ${name}` : '';
    const losers = o.loser_sides
        .map(
            (side, i) =>
                `${side.join(' & ')}${manager(o.loser_side_managers?.[i])}`,
        )
        .join(', ');
    const method =
        o.method === 'dq' || o.method === 'countout'
            ? ` · ${o.method_label}`
            : '';

    return `${o.winners_label}${manager(o.winner_manager)} def. ${losers}${o.match_type ? ` · ${o.match_type}` : ''}${o.referee ? ` · Special Referee: ${o.referee}` : ''}${method}`;
}

export default function Rivalries() {
    const { rivalries, max_heat, heating_up, rules } = usePage<Props>().props;
    const colors = usePage<Props>().props.heat_colors ?? [50, 80];
    const [open, setOpen] = useState<number[]>([]);
    const toggle = (id: number) =>
        setOpen((ids) =>
            ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id],
        );

    return (
        <>
            <Head title="Rivalries" />
            <PageTitle kicker="Bad blood" title="Rivalries" />

            {rules && (
                <RivalryRulesPanel
                    rules={rules}
                    maxHeat={max_heat}
                    heatingUp={heating_up}
                    heatColors={colors}
                />
            )}

            {rivalries.length === 0 ? (
                <EmptyState>
                    No rivalries yet. Book the same opponents against each other
                    or use a Start a Rivalry promo.
                </EmptyState>
            ) : (
                <Panel bodyClassName="p-0">
                    <table className="gm-table">
                        <thead>
                            <tr>
                                <th>Brand</th>
                                <th>Rivalry</th>
                                <th className="w-1/3">Heat</th>
                                <th className="text-right">Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rivalries.map((r) => {
                                const matches = r.matches ?? [];
                                const expanded = open.includes(r.id);

                                return (
                                    <Fragment key={r.id}>
                                        <tr
                                            className={cn(
                                                !r.active && 'opacity-50',
                                            )}
                                        >
                                            <td>
                                                {r.brand ? (
                                                    <BrandBug
                                                        brand={r.brand}
                                                        size="sm"
                                                    />
                                                ) : (
                                                    <span className="text-ink-faint">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="font-semibold">
                                                <div className="flex items-center gap-2">
                                                    <span className="min-w-0">
                                                        <span
                                                            data-brand-tint={
                                                                r.brand_a ??
                                                                undefined
                                                            }
                                                            className="text-brand-hot"
                                                        >
                                                            {r.wrestler_a}
                                                        </span>
                                                        <span className="mx-2 font-condensed text-xs text-ink-faint uppercase italic">
                                                            vs
                                                        </span>
                                                        <span
                                                            data-brand-tint={
                                                                r.brand_b ??
                                                                undefined
                                                            }
                                                            className="text-brand-hot"
                                                        >
                                                            {r.wrestler_b}
                                                        </span>
                                                    </span>
                                                    {/* Their matches together (house
                                                rule, 2026-11-20). */}
                                                    {matches.length > 0 && (
                                                        <button
                                                            type="button"
                                                            aria-expanded={
                                                                expanded
                                                            }
                                                            aria-controls={`rivalry-matches-${r.id}`}
                                                            onClick={() =>
                                                                toggle(r.id)
                                                            }
                                                            className="gm-row-link ml-auto inline-flex min-h-11 shrink-0 items-center gap-1 px-1 font-condensed text-xs font-semibold tracking-wider whitespace-nowrap text-ink-dim uppercase hover:text-ink sm:min-h-0"
                                                        >
                                                            <DisclosureChevron
                                                                open={expanded}
                                                            />
                                                            {pluralize(
                                                                matches.length,
                                                                'match',
                                                                'matches',
                                                            )}
                                                            <span className="sr-only">
                                                                {' '}
                                                                between{' '}
                                                                {
                                                                    r.wrestler_a
                                                                }{' '}
                                                                and{' '}
                                                                {r.wrestler_b}
                                                            </span>
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                            <td>
                                                <div className="flex items-center gap-3">
                                                    <div className="h-2 flex-1 -skew-x-12 bg-white/8">
                                                        <div
                                                            data-testid={`heat-bar-${r.id}`}
                                                            className={cn(
                                                                'h-full',
                                                                heatColor(
                                                                    r.heat,
                                                                    colors,
                                                                ),
                                                            )}
                                                            style={{
                                                                width: `${(r.heat / max_heat) * 100}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="gm-num w-8 text-right">
                                                        {r.heat}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="gm-num text-right">
                                                {r.weeks_active} wk
                                            </td>
                                            <td className="text-ink-dim">
                                                {r.active
                                                    ? r.heat >= heating_up
                                                        ? 'Boiling over'
                                                        : 'Simmering'
                                                    : `Ended W${r.ended_week}`}
                                            </td>
                                        </tr>
                                        {matches.length > 0 && (
                                            <tr className="hover:!bg-transparent">
                                                <td
                                                    colSpan={5}
                                                    className={cn(
                                                        '!p-0',
                                                        !expanded &&
                                                            '!border-0',
                                                    )}
                                                >
                                                    <DisclosurePanel
                                                        open={expanded}
                                                        id={`rivalry-matches-${r.id}`}
                                                    >
                                                        <RivalryMatches
                                                            rivalry={r}
                                                            matches={matches}
                                                        />
                                                    </DisclosurePanel>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </Panel>
            )}
        </>
    );
}

/** The rivals' matches together, newest first, each linked to its results. */
function RivalryMatches({
    rivalry,
    matches,
}: {
    rivalry: RivalryRow;
    matches: RivalryMatch[];
}) {
    return (
        <div className="border-l-2 border-brand/50 bg-black/20 px-4 py-3">
            <table
                className="gm-table"
                aria-label={`Matches between ${rivalry.wrestler_a} and ${rivalry.wrestler_b}`}
                data-testid={`rivalry-matches-table-${rivalry.id}`}
            >
                <thead>
                    <tr>
                        <th scope="col">Week/PPV</th>
                        <th scope="col">Match outcome</th>
                    </tr>
                </thead>
                <tbody>
                    {matches.map((m) => (
                        <tr key={m.id}>
                            <td className="w-32 align-top">
                                <WeekCell
                                    season={m.season}
                                    week={m.season_week}
                                    ppv={m.ppv}
                                />
                            </td>
                            <td className="break-words">
                                <Link
                                    href={m.link}
                                    className="gm-row-link hover:text-brand-hot"
                                >
                                    {outcomeLine(m.outcome)}
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The rivalry rules as the game applies them (RivalryService, MatchResolver). */
function RivalryRulesPanel({
    rules: r,
    maxHeat,
    heatingUp,
    heatColors,
}: {
    rules: RivalryRules;
    maxHeat: number;
    heatingUp: number;
    heatColors: [number, number];
}) {
    return (
        <Panel title="How rivalries work" className="mb-6">
            <div className="grid gap-x-8 gap-y-4 text-sm md:grid-cols-2">
                <div data-testid="rivalry-rules-start">
                    <p className="mb-1.5 text-ink-dim">Starting one:</p>
                    <ul className="list-disc space-y-1.5 pl-5 marker:text-ink-faint">
                        <li>
                            Book the same two superstars against each other{' '}
                            {pluralize(r.meetings, 'time')} within{' '}
                            {pluralize(r.window_weeks, 'week')}, in matches
                            where every side is one superstar (Gauntlet bouts
                            count).
                        </li>
                        <li>
                            Or run a Start a Rivalry promo with two healthy
                            superstars who aren’t in a rivalry yet. It always
                            works, costs nothing and tires both{' '}
                            {bars(r.start_promo_bars)}.
                        </li>
                        <li>
                            A failed Interview Space for someone who isn’t
                            booked can get crashed; if neither of them is in a
                            rivalry, that starts one.
                        </li>
                        <li>
                            Rivalries are always one on one, named
                            alphabetically.
                        </li>
                    </ul>
                </div>
                <div data-testid="rivalry-rules-heat">
                    <p className="mb-1.5 text-ink-dim">
                        Heat (0 to {maxHeat}):
                    </p>
                    <ul className="list-disc space-y-1.5 pl-5 marker:text-ink-faint">
                        <li>
                            A new rivalry starts at {r.start_heat}. Heat is
                            settled once a week, after the shows.
                        </li>
                        <li>
                            A week the rivals share a match adds{' '}
                            {r.heat_per_meeting}, and a PPV they share a match
                            at adds {r.heat_per_ppv_meeting}. Opponents or
                            partners, winning or losing: being in the same match
                            is what counts.
                        </li>
                        <li>
                            A week without a shared match, but with a Continue
                            Rivalry promo for it, holds the heat where it is.
                            The promo always works, costs{' '}
                            {formatMoney(r.continue_promo_cost)} and tires both
                            rivals {bars(r.continue_promo_bars)}.
                        </li>
                        {r.call_out_heat !== undefined && (
                            <li>
                                A Call-out promo (one rival calls the other out,
                                as a face or a heel) adds {r.call_out_heat} heat
                                when it works ({r.call_out_chance}% of the time)
                                and keeps the week from going quiet. It costs{' '}
                                {formatMoney(r.call_out_cost ?? 0)} either way.
                            </li>
                        )}
                        <li>
                            A rival who manages a match the other is in, on
                            either side, or is its Special Referee, counts as
                            sharing it too.
                        </li>
                        <li>
                            Continue Rivalry never injures the rivals; it still
                            tires them.
                        </li>
                        <li>
                            Two rival Divas in a Diva Bikini Contest keep their
                            rivalry going, like Continue Rivalry.
                        </li>
                        <li>
                            Any other week loses {r.idle_decay} heat. At 0 or
                            below the rivalry is over.
                        </li>
                        <li>
                            At {heatingUp} it’s heating up and makes the news;
                            still that hot after{' '}
                            {pluralize(r.milestone_weeks, 'week')}, it makes a
                            headline.
                        </li>
                        <li>
                            A rivalry that reaches {maxHeat} has peaked: it ends
                            the following week, with a headline.
                        </li>
                        <li>
                            The heat bar is yellow while it builds, orange from{' '}
                            {heatColors[0]} and red from {heatColors[1]}.
                        </li>
                        <li>
                            In a match with ring outs, a superstar’s rival who
                            isn’t in it can run in and cost them the match,
                            which adds {r.heat_per_interference} heat.
                        </li>
                    </ul>
                </div>
            </div>
        </Panel>
    );
}
