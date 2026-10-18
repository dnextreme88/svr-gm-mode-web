import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { InjuryChip, SpecialtyBadge } from '@/components/gm/badges';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { RosterType } from '@/types/enums';
import { RosterTypeLabels, WeightClassLabels } from '@/types/enums';
import type { ContractQuote, GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    available: Array<WrestlerRow & { quotes: ContractQuote[] }>;
    locked: Array<{ id: number; name: string; until_week: number }>;
    lengths: Array<{ value: number; label: string }>;
    has_room: boolean;
};

export default function FreeAgency() {
    const { game, available, locked, lengths, has_room } =
        usePage<Props>().props;
    const [filter, setFilter] = useState<RosterType | 'all'>('all');
    const [lengthById, setLengthById] = useState<Record<number, number>>({});

    const visible = useMemo(
        () =>
            available.filter(
                (w) => filter === 'all' || w.roster_type === filter,
            ),
        [available, filter],
    );

    return (
        <>
            <Head title="Free Agency" />
            <PageTitle kicker="Signings start next week" title="Free Agency">
                {(['all', 'superstar', 'diva', 'legend'] as const).map((f) => (
                    <button
                        key={f}
                        type="button"
                        onClick={() => setFilter(f)}
                        className={cn(
                            'gm-btn gm-btn-sm',
                            filter !== f && 'gm-btn-ghost',
                        )}
                    >
                        {f === 'all' ? 'All' : RosterTypeLabels[f]}
                    </button>
                ))}
            </PageTitle>

            {!has_room && (
                <p className="-mt-3 mb-4 text-sm text-zone-serious">
                    Your roster is full ({game.roster_cap}). Release someone
                    before signing.
                </p>
            )}

            {visible.length === 0 ? (
                <EmptyState>No free agents match.</EmptyState>
            ) : (
                <Panel bodyClassName="p-0">
                    <div className="gm-scroll overflow-x-auto">
                        <table className="gm-table">
                            <thead>
                                <tr>
                                    <th>Wrestler</th>
                                    <th>Type</th>
                                    <th>Class</th>
                                    <th>Injury</th>
                                    <th>Specialties</th>
                                    <th>Length</th>
                                    <th className="text-right">Up front</th>
                                    <th className="text-right">Weekly</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((w) => {
                                    const length = lengthById[w.id] ?? 52;
                                    const quote = w.quotes.find(
                                        (q) => q.length_weeks === length,
                                    );

                                    return (
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
                                            </td>
                                            <td className="text-ink-dim">
                                                {
                                                    RosterTypeLabels[
                                                        w.roster_type
                                                    ]
                                                }
                                            </td>
                                            <td className="text-ink-dim">
                                                {
                                                    WeightClassLabels[
                                                        w.weight_class
                                                    ]
                                                }
                                            </td>
                                            <td>
                                                <InjuryChip injury={w.injury} />
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
                                            <td>
                                                <select
                                                    aria-label={`Contract length for ${w.name}`}
                                                    className="gm-input py-1 text-sm"
                                                    value={length}
                                                    onChange={(e) =>
                                                        setLengthById({
                                                            ...lengthById,
                                                            [w.id]: Number(
                                                                e.target.value,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    {lengths.map((l) => (
                                                        <option
                                                            key={l.value}
                                                            value={l.value}
                                                        >
                                                            {l.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="gm-num text-right">
                                                {quote &&
                                                    formatMoney(quote.upfront)}
                                            </td>
                                            <td className="gm-num text-right text-ink-dim">
                                                {quote &&
                                                    formatMoney(quote.weekly)}
                                            </td>
                                            <td className="text-right">
                                                <button
                                                    type="button"
                                                    className="gm-btn gm-btn-sm"
                                                    disabled={!has_room}
                                                    onClick={() =>
                                                        router.post(
                                                            gm.freeAgency.sign.url(
                                                                {
                                                                    save: game.id,
                                                                    saveWrestler:
                                                                        w.id,
                                                                },
                                                            ),
                                                            {
                                                                length_weeks:
                                                                    length,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Sign
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}

            {locked.length > 0 && (
                <Panel title="Re-sign lockouts" className="mt-6">
                    <ul className="flex flex-wrap gap-3 text-sm text-ink-dim">
                        {locked.map((l) => (
                            <li key={l.id}>
                                {l.name} · until week {l.until_week}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}
