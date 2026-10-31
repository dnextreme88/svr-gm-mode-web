import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { InjuryChip, SpecialtyBadge } from '@/components/gm/badges';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { PoolFilters } from '@/components/gm/pool-filters';
import type { SortState } from '@/components/gm/sort-header';
import { ariaSort, nextSort, SortHeader } from '@/components/gm/sort-header';
import { WrestlerName } from '@/components/gm/wrestler-name';
import type { PoolFilter } from '@/lib/gm';
import { emptyPoolFilter, filterPool, formatMoney } from '@/lib/gm';
import gm from '@/routes/gm';
import { RosterTypeLabels, WeightClassLabels } from '@/types/enums';
import type { ContractQuote, GamePageProps, WrestlerRow } from '@/types/gm';

type FreeAgent = WrestlerRow & { quotes: ContractQuote[] };

type Props = GamePageProps & {
    available: FreeAgent[];
    locked: Array<{ id: number; name: string; until_week: number }>;
    lengths: Array<{ value: number; label: string }>;
    has_room: boolean;
};

type SortKey = 'name' | 'type' | 'class' | 'upfront' | 'weekly';

const DEFAULT_LENGTH = 52;

/** The quote for the contract length picked on a row (52 weeks by default). */
function quoteFor(w: FreeAgent, lengthById: Record<number, number>) {
    const length = lengthById[w.id] ?? DEFAULT_LENGTH;

    return w.quotes.find((q) => q.length_weeks === length);
}

export default function FreeAgency() {
    const { game, available, locked, lengths, has_room } =
        usePage<Props>().props;
    const [filter, setFilter] = useState<PoolFilter>(emptyPoolFilter);
    const [sort, setSort] = useState<SortState<SortKey>>(null);
    const [lengthById, setLengthById] = useState<Record<number, number>>({});

    const visible = useMemo(() => {
        const rows = filterPool(available, filter);

        if (sort === null) {
            return rows;
        }

        const value = (w: FreeAgent): string | number => {
            switch (sort.key) {
                case 'name':
                    return w.name;
                case 'type':
                    return RosterTypeLabels[w.roster_type];
                case 'class':
                    return WeightClassLabels[w.weight_class];
                case 'upfront':
                    return quoteFor(w, lengthById)?.upfront ?? 0;
                case 'weekly':
                    return quoteFor(w, lengthById)?.weekly ?? 0;
            }
        };
        const flip = sort.dir === 'asc' ? 1 : -1;

        return [...rows].sort((a, b) => {
            const x = value(a);
            const y = value(b);

            return (
                (typeof x === 'number' && typeof y === 'number'
                    ? x - y
                    : String(x).localeCompare(String(y))) * flip
            );
        });
    }, [available, filter, sort, lengthById]);

    const header = (key: SortKey, label: ReactNode, right = false) => (
        <SortHeader
            label={label}
            active={sort?.key === key ? sort.dir : null}
            onClick={() => setSort((s) => nextSort(s, key))}
            right={right}
        />
    );

    return (
        <>
            <Head title="Free Agency" />
            <PageTitle kicker="Signings start next week" title="Free Agency" />

            {!has_room && (
                <p
                    role="status"
                    className="mb-4 border-l-2 border-zone-serious bg-zone-serious/10 px-4 py-3 text-sm"
                >
                    Your roster is full ({game.roster_cap}/{game.roster_cap}).
                    Release someone from{' '}
                    <Link
                        href={gm.rosters.index.url(game.id)}
                        className="text-brand-hot underline underline-offset-4"
                    >
                        your roster
                    </Link>{' '}
                    to sign a free agent.
                </p>
            )}

            <Panel bodyClassName="p-0">
                <div className="border-b border-white/6 px-4 py-2">
                    <PoolFilters
                        value={filter}
                        onChange={setFilter}
                        types={['all', 'superstar', 'diva', 'legend']}
                    />
                </div>
                {visible.length === 0 ? (
                    <div className="p-4">
                        <EmptyState>No free agents match.</EmptyState>
                    </div>
                ) : (
                    <div className="gm-scroll max-h-[75vh] overflow-auto">
                        <table className="gm-table gm-table-sticky">
                            <thead>
                                <tr>
                                    <th aria-sort={ariaSort(sort, 'name')}>
                                        {header('name', 'Wrestler')}
                                    </th>
                                    <th aria-sort={ariaSort(sort, 'type')}>
                                        {header('type', 'Type')}
                                    </th>
                                    <th aria-sort={ariaSort(sort, 'class')}>
                                        {header('class', 'Class')}
                                    </th>
                                    <th>Injury</th>
                                    <th>Specialties</th>
                                    <th>Length</th>
                                    <th
                                        className="text-right"
                                        aria-sort={ariaSort(sort, 'upfront')}
                                    >
                                        {header('upfront', 'Up front', true)}
                                    </th>
                                    <th
                                        className="text-right"
                                        aria-sort={ariaSort(sort, 'weekly')}
                                    >
                                        {header('weekly', 'Weekly', true)}
                                    </th>
                                    <th>
                                        <span className="sr-only">Sign</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((w) => {
                                    const length =
                                        lengthById[w.id] ?? DEFAULT_LENGTH;
                                    const quote = quoteFor(w, lengthById);

                                    return (
                                        <tr
                                            key={w.id}
                                            data-testid={`free-agent-${w.id}`}
                                        >
                                            <td>
                                                <Link
                                                    href={gm.rosters.show.url({
                                                        save: game.id,
                                                        saveWrestler: w.id,
                                                    })}
                                                    className="gm-row-link font-semibold hover:text-brand-hot"
                                                >
                                                    <WrestlerName
                                                        wrestler={w}
                                                    />
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
                                            <td className="text-right whitespace-nowrap">
                                                {has_room ? (
                                                    <button
                                                        type="button"
                                                        className="gm-btn gm-btn-sm"
                                                        aria-label={`Sign ${w.name}`}
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
                                                ) : (
                                                    <span className="text-xs text-ink-dim">
                                                        Roster full
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

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
