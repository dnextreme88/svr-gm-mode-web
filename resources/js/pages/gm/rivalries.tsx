import { Head, usePage } from '@inertiajs/react';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
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
    heat_per_promo: number;
    idle_decay: number;
    blowoff_min_heat: number;
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
                                <th>Duration</th>
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
                                    <td className="gm-num">
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
                            Book the same two wrestlers against each other{' '}
                            {r.meetings} times within {r.window_weeks} weeks, in
                            matches where every side is one wrestler (Gauntlet
                            bouts count).
                        </li>
                        <li>
                            Or run a Start a Rivalry promo with two healthy
                            wrestlers who aren’t in a rivalry yet. It always
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
                            A new rivalry starts at {r.start_heat}. Each meeting
                            in the ring adds {r.heat_per_meeting}, and a
                            Continue Rivalry promo adds {r.heat_per_promo} (it
                            always works, costs{' '}
                            {formatMoney(r.continue_promo_cost)} and tires both
                            rivals {bars(r.continue_promo_bars)}).
                        </li>
                        <li>
                            A week with neither loses {r.idle_decay} heat. At 0
                            the rivalry is over.
                        </li>
                        <li>
                            At {heatingUp} it’s heating up and makes the news;
                            still that hot after {r.milestone_weeks} weeks, it
                            makes a headline.
                        </li>
                        <li>
                            Rivals meeting at a PPV with {r.blowoff_min_heat}+
                            heat settle it there: the blow-off ends the rivalry
                            with a headline.
                        </li>
                        <li>
                            In a match with ring outs, a wrestler’s rival who
                            isn’t in it can run in and cost them the match.
                        </li>
                    </ul>
                </div>
            </div>
        </Panel>
    );
}
