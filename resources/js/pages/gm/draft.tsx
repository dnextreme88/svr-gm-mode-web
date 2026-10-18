import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { PageTitle, Panel, Stat } from '@/components/gm/panel';
import { brandName, formatCompactMoney, formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, RosterType } from '@/types/enums';
import { RosterTypeLabels, WeightClassLabels } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type PoolRow = WrestlerRow & {
    quote: { upfront: number; weekly: number; length_weeks: number };
};

type Pick = {
    pick_number: number;
    brand: Brand;
    name: string | null;
    roster_type: RosterType | null;
    pick_type: 'manual' | 'random';
};

type Props = GamePageProps & {
    pool: PoolRow[];
    picks: Pick[];
    next_brand: Brand | null;
    max_picks: number;
    picks_made: Record<Brand, number>;
    draft_order: Brand[];
    contract_length: number;
};

const filters: Array<{ value: RosterType | 'all'; label: string }> = [
    { value: 'all', label: 'All' },
    { value: 'superstar', label: 'Superstars' },
    { value: 'diva', label: 'Divas' },
    { value: 'legend', label: 'Legends' },
];

export default function Draft() {
    const {
        game,
        pool,
        picks,
        next_brand,
        max_picks,
        picks_made,
        draft_order,
    } = usePage<Props>().props;
    const [filter, setFilter] = useState<RosterType | 'all'>('all');
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const yourTurn = next_brand === game.player_brand;

    const visible = useMemo(
        () =>
            pool.filter(
                (w) =>
                    (filter === 'all' || w.roster_type === filter) &&
                    w.name.toLowerCase().includes(search.toLowerCase()),
            ),
        [pool, filter, search],
    );

    const yourPicks = picks.filter((p) => p.brand === game.player_brand);
    const rivalPicks = picks.filter((p) => p.brand === game.rival_brand);

    const pick = (id: number) => {
        setBusy(true);
        router.post(
            gm.draft.pick.url(game.id),
            { save_wrestler_id: id },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <>
            <Head title="Initial Draft" />
            <PageTitle kicker="Initial draft" title="Build your roster">
                <button
                    type="button"
                    className="gm-btn gm-btn-ghost"
                    disabled={busy}
                    onClick={() => {
                        if (
                            confirm(
                                'Stop drafting? You can fill the remaining spots through Free Agency.',
                            )
                        ) {
                            router.post(gm.draft.finish.url(game.id));
                        }
                    }}
                >
                    Stop drafting
                </button>
            </PageTitle>

            <div className="mb-6 grid gap-4 md:grid-cols-4">
                <Panel>
                    <div className="gm-label">On the clock</div>
                    <div className="mt-1 flex items-center gap-3">
                        {next_brand ? (
                            <>
                                <BrandBug brand={next_brand} size="sm" />
                                <span
                                    className={cn(
                                        'font-condensed text-sm tracking-wider uppercase',
                                        yourTurn
                                            ? 'animate-gm-pulse text-brand-hot'
                                            : 'text-ink-dim',
                                    )}
                                >
                                    {yourTurn ? 'Your pick' : 'Rival picking'}
                                </span>
                            </>
                        ) : (
                            <span className="text-ink-dim">Draft over</span>
                        )}
                    </div>
                    <div className="mt-2 text-xs text-ink-faint">
                        Order: {draft_order.map(brandName).join(' → ')}
                    </div>
                </Panel>
                <Panel>
                    <Stat
                        label="Your picks"
                        value={`${picks_made[game.player_brand]} / ${max_picks}`}
                        tone="brand"
                    />
                </Panel>
                <Panel>
                    <Stat
                        label="Budget"
                        value={formatCompactMoney(game.budget)}
                    />
                </Panel>
                <Panel>
                    <Stat
                        label={`${brandName(game.rival_brand)} picks`}
                        value={`${picks_made[game.rival_brand]} / ${max_picks}`}
                    />
                </Panel>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
                <Panel
                    title={`Free agent pool · ${pool.length}`}
                    bodyClassName="p-0"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {filters.map((f) => (
                                <button
                                    key={f.value}
                                    type="button"
                                    onClick={() => setFilter(f.value)}
                                    className={cn(
                                        'px-2 py-1 font-condensed text-xs font-semibold tracking-wider uppercase',
                                        filter === f.value
                                            ? 'bg-brand text-white'
                                            : 'text-ink-dim hover:text-ink',
                                    )}
                                >
                                    {f.label}
                                </button>
                            ))}
                            <input
                                className="gm-input w-40 py-1 text-sm"
                                placeholder="Search…"
                                aria-label="Search wrestlers"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    }
                >
                    <div className="gm-scroll max-h-[65vh] overflow-y-auto">
                        <table className="gm-table">
                            <thead className="sticky top-0 bg-arena-2">
                                <tr>
                                    <th>Wrestler</th>
                                    <th>Type</th>
                                    <th>Class</th>
                                    <th>Specialties</th>
                                    <th className="text-right">Signing</th>
                                    <th className="text-right">Weekly</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((w) => (
                                    <tr key={w.id}>
                                        <td className="font-semibold">
                                            {w.name}
                                            {w.is_default_manager && (
                                                <span className="ml-2 text-xs text-ink-faint">
                                                    (manager)
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-ink-dim">
                                            {RosterTypeLabels[w.roster_type]}
                                        </td>
                                        <td className="text-ink-dim">
                                            {WeightClassLabels[w.weight_class]}
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap gap-1">
                                                {w.specialties.map((s) => (
                                                    <SpecialtyBadge
                                                        key={s}
                                                        family={s}
                                                    />
                                                ))}
                                            </div>
                                        </td>
                                        <td className="gm-num text-right">
                                            {formatMoney(w.quote.upfront)}
                                        </td>
                                        <td className="gm-num text-right text-ink-dim">
                                            {formatMoney(w.quote.weekly)}
                                        </td>
                                        <td className="text-right">
                                            <button
                                                type="button"
                                                className="gm-btn gm-btn-sm"
                                                disabled={
                                                    !yourTurn ||
                                                    busy ||
                                                    game.budget <
                                                        w.quote.upfront
                                                }
                                                onClick={() => pick(w.id)}
                                            >
                                                Draft
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="space-y-6">
                    <DraftBoard
                        title="Your roster"
                        brand={game.player_brand}
                        picks={yourPicks}
                    />
                    <DraftBoard
                        title="Rival roster"
                        brand={game.rival_brand}
                        picks={rivalPicks}
                    />
                </div>
            </div>
        </>
    );
}

function DraftBoard({
    title,
    brand,
    picks,
}: {
    title: string;
    brand: Brand;
    picks: Pick[];
}) {
    return (
        <div data-brand-tint={brand}>
            <Panel title={`${title} · ${picks.length}`} bodyClassName="p-0">
                <ol className="gm-scroll max-h-[30vh] divide-y divide-white/5 overflow-y-auto">
                    {picks.length === 0 && (
                        <li className="px-4 py-3 text-sm text-ink-faint">
                            No picks yet.
                        </li>
                    )}
                    {[...picks].reverse().map((p) => (
                        <li
                            key={p.pick_number}
                            className="flex items-center gap-3 px-4 py-2 text-sm"
                        >
                            <span className="gm-num w-7 text-ink-faint">
                                #{p.pick_number}
                            </span>
                            <span className="flex-1 font-medium">{p.name}</span>
                            {p.roster_type && p.roster_type !== 'superstar' && (
                                <span className="font-condensed text-[0.65rem] tracking-widest text-ink-dim uppercase">
                                    {RosterTypeLabels[p.roster_type]}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </Panel>
        </div>
    );
}
