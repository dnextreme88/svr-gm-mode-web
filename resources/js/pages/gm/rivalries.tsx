import { Head, usePage } from '@inertiajs/react';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
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

type Props = GamePageProps & {
    rivalries: RivalryRow[];
    max_heat: number;
    heating_up: number;
};

export default function Rivalries() {
    const { rivalries, max_heat, heating_up } = usePage<Props>().props;

    return (
        <>
            <Head title="Rivalry" />
            <PageTitle kicker="Bad blood" title="Rivalries" />

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
