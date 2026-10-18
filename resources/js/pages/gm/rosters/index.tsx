import { Head, Link, usePage } from '@inertiajs/react';
import {
    InjuryChip,
    MoraleBadge,
    SpecialtyBadge,
    TitleBelt,
} from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
import { RosterTypeLabels, WeightClassLabels } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    brand: Brand;
    wrestlers: WrestlerRow[];
    pending: number;
};

export default function Rosters() {
    const { game, brand, wrestlers, pending } = usePage<Props>().props;
    const own = brand === game.player_brand;

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
                            {b === 'raw' ? 'Raw' : 'SmackDown!'}
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
                <Panel bodyClassName="p-0">
                    <div className="gm-scroll overflow-x-auto">
                        <table className="gm-table">
                            <thead>
                                <tr>
                                    <th>Wrestler</th>
                                    <th>Class</th>
                                    <th>Fatigue</th>
                                    <th>Injury</th>
                                    <th>Morale</th>
                                    <th>Record</th>
                                    <th>Contract</th>
                                    <th>Popularity</th>
                                    <th>Specialties</th>
                                </tr>
                            </thead>
                            <tbody>
                                {wrestlers.map((w) => (
                                    <tr key={w.id}>
                                        <td>
                                            <Link
                                                href={gm.rosters.show.url({
                                                    save: game.id,
                                                    saveWrestler: w.id,
                                                })}
                                                className="font-semibold hover:text-brand-hot"
                                            >
                                                {w.name}
                                            </Link>
                                            <div className="mt-0.5 flex flex-wrap gap-1">
                                                {w.roster_type !==
                                                    'superstar' && (
                                                    <span className="font-condensed text-[0.62rem] tracking-widest text-ink-faint uppercase">
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
                                                    <span className="font-condensed text-[0.62rem] font-bold tracking-widest text-zone-minor uppercase">
                                                        Sitting out
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="text-ink-dim">
                                            {WeightClassLabels[w.weight_class]}
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
                                            {w.win_streak >= 3 && (
                                                <span className="ml-1.5 text-xs text-zone-safe">
                                                    W{w.win_streak}
                                                </span>
                                            )}
                                            {w.loss_streak >= 3 && (
                                                <span className="ml-1.5 text-xs text-zone-serious">
                                                    L{w.loss_streak}
                                                </span>
                                            )}
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
