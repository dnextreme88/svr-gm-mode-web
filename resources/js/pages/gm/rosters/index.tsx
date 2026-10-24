import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    InjuryChip,
    MoraleBadge,
    SpecialtyBadge,
    TitleBelt,
} from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { StreakMeter } from '@/components/gm/streak-meter';
import { formatMoney } from '@/lib/gm';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
import { RosterTypeLabels } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    brand: Brand;
    wrestlers: WrestlerRow[];
    pending: number;
};

export default function Rosters() {
    const { game, brand, wrestlers, pending } = usePage<Props>().props;
    const own = brand === game.player_brand;
    const [search, setSearch] = useState('');
    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();

        return wrestlers.filter((w) => w.name.toLowerCase().includes(q));
    }, [wrestlers, search]);
    const open = (id: number) =>
        router.visit(gm.rosters.show.url({ save: game.id, saveWrestler: id }));

    return (
        <>
            <Head title="Rosters" />
            <div data-brand-tint={brand}>
                <PageTitle
                    kicker={own ? 'Your roster' : 'Scouting report'}
                    title={
                        <span className="flex items-center gap-4">
                            <BrandBug brand={brand} size="lg" />
                            <span className="gm-num text-3xl text-ink-dim">
                                {wrestlers.length}
                                {pending > 0 && `+${pending}`}/{game.roster_cap}
                            </span>
                        </span>
                    }
                >
                    {(['raw', 'smackdown'] as const).map((b) => (
                        <Link
                            key={b}
                            href={gm.rosters.index.url(game.id, {
                                query: { brand: b },
                            })}
                            className={cn(
                                'gm-btn gm-btn-sm',
                                b !== brand && 'gm-btn-ghost',
                            )}
                            data-brand-tint={b}
                        >
                            {b === 'raw' ? 'Raw' : 'Smackdown'}
                        </Link>
                    ))}
                </PageTitle>
            </div>

            {pending > 0 && own && (
                <p className="-mt-3 mb-4 text-sm text-ink-dim">
                    {pending} Free Agency signing(s) join the roster next week.
                </p>
            )}

            {wrestlers.length === 0 ? (
                <EmptyState>Nobody on this roster.</EmptyState>
            ) : (
                <Panel
                    bodyClassName="p-0"
                    title={`${visible.length} of ${wrestlers.length}`}
                    actions={
                        <input
                            type="search"
                            className="gm-input w-56 py-1 text-sm"
                            placeholder="Search superstars…"
                            aria-label="Search superstars"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    }
                >
                    <div className="gm-scroll overflow-x-auto">
                        <table className="gm-table">
                            <thead>
                                <tr>
                                    <th>Wrestler</th>
                                    <th>Fatigue</th>
                                    <th>Injury</th>
                                    <th>Morale</th>
                                    <th>Record (W-L)</th>
                                    <th>Streak</th>
                                    <th>Specialties</th>
                                    <th>Contract</th>
                                    <th>Popularity</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="text-center text-ink-faint"
                                        >
                                            No one matches “{search}”.
                                        </td>
                                    </tr>
                                )}
                                {visible.map((w) => (
                                    <tr
                                        key={w.id}
                                        tabIndex={0}
                                        aria-label={`Open ${w.name}`}
                                        data-testid="roster-row"
                                        className="group cursor-pointer transition-colors hover:bg-white/[0.04] focus-visible:bg-white/[0.06] focus-visible:outline-none"
                                        onClick={() => open(w.id)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                open(w.id);
                                            }
                                        }}
                                    >
                                        <td>
                                            <span className="font-semibold group-hover:text-brand-hot">
                                                <WrestlerName wrestler={w} />
                                            </span>
                                            <div className="mt-0.5 flex flex-wrap gap-1">
                                                {w.roster_type !==
                                                    'superstar' && (
                                                    <span className="font-condensed text-xs tracking-widest text-ink-faint uppercase">
                                                        {
                                                            RosterTypeLabels[
                                                                w.roster_type
                                                            ]
                                                        }
                                                    </span>
                                                )}
                                                {w.titles.map((t) => (
                                                    <TitleBelt
                                                        key={t}
                                                        name={t}
                                                    />
                                                ))}
                                                {w.sitting_out && (
                                                    <span className="font-condensed text-xs font-bold tracking-widest text-zone-minor uppercase">
                                                        Sitting out
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td>
                                            <FatigueMeter
                                                fatigue={w.fatigue}
                                                config={game.fatigue_config}
                                                showLabel
                                            />
                                        </td>
                                        <td>
                                            <InjuryChip injury={w.injury} />
                                        </td>
                                        <td>
                                            <MoraleBadge morale={w.morale} />
                                        </td>
                                        <td className="gm-num whitespace-nowrap">
                                            {w.wins}–{w.losses}
                                        </td>
                                        <td>
                                            <StreakMeter
                                                wins={w.win_streak}
                                                losses={w.loss_streak}
                                                size="sm"
                                            />
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
                                        <td className="whitespace-nowrap">
                                            {w.contract ? (
                                                <span
                                                    className={cn(
                                                        'gm-num',
                                                        w.contract
                                                            .weeks_remaining <=
                                                            4 &&
                                                            'text-zone-serious',
                                                    )}
                                                >
                                                    {w.contract.weeks_remaining}
                                                    w
                                                    <span className="ml-1.5 text-xs text-ink-faint">
                                                        {formatMoney(
                                                            w.contract
                                                                .weekly_cost,
                                                        )}
                                                        /wk
                                                    </span>
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td
                                            className="text-ink-faint"
                                            title="Popularity system is deferred"
                                        >
                                            {w.popularity ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}
        </>
    );
}
