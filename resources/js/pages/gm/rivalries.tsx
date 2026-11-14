import { Head, usePage } from '@inertiajs/react';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { formatMoney, pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type RivalryRow = {
    id: number;
    wrestler_a: string;
    wrestler_b: string;
    brand_a: Brand | null;
    brand_b: Brand | null;
    heat: number;
    weeks_active: number;
    started_week: number;
    active: boolean;
    ended_week: number | null;
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
};

type Props = GamePageProps & {
    rivalries: RivalryRow[];
    max_heat: number;
    heating_up: number;
    rules?: RivalryRules;
};

const bars = (n: number) => `${n} fatigue ${n === 1 ? 'bar' : 'bars'}`;

export default function Rivalries() {
    const { rivalries, max_heat, heating_up, rules } = usePage<Props>().props;

    return (
        <>
            <Head title="Rivalry" />
            <PageTitle kicker="Bad blood" title="Rivalries" />

            {rules && (
                <RivalryRulesPanel
                    rules={rules}
                    maxHeat={max_heat}
                    heatingUp={heating_up}
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
                                <th>Rivalry</th>
                                <th className="w-1/3">Heat</th>
                                <th className="text-right">Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rivalries.map((r) => (
                                <tr
                                    key={r.id}
                                    className={cn(!r.active && 'opacity-50')}
                                >
                                    <td className="font-semibold">
                                        <span
                                            data-brand-tint={
                                                r.brand_a ?? undefined
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
                                                r.brand_b ?? undefined
                                            }
                                            className="text-brand-hot"
                                        >
                                            {r.wrestler_b}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-3">
                                            <div className="h-2 flex-1 -skew-x-12 bg-white/8">
                                                <div
                                                    className={cn(
                                                        'h-full',
                                                        r.heat >= heating_up
                                                            ? 'bg-zone-serious'
                                                            : 'bg-zone-minor',
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
                            ))}
                        </tbody>
                    </table>
                </Panel>
            )}
        </>
    );
}

/** The rivalry rules as the game applies them (RivalryService, MatchResolver). */
function RivalryRulesPanel({
    rules: r,
    maxHeat,
    heatingUp,
}: {
    rules: RivalryRules;
    maxHeat: number;
    heatingUp: number;
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
