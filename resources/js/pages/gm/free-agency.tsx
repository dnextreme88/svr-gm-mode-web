import { Head, Link, router, usePage } from '@inertiajs/react';
import { memo, useCallback, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { InjuryChip, SpecialtyBadge } from '@/components/gm/badges';
import { BusyLabel } from '@/components/gm/busy-label';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { PoolFilters } from '@/components/gm/pool-filters';
import { ariaSort, nextSort, SortHeader } from '@/components/gm/sort-header';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { filterPool, formatMoney } from '@/lib/gm';
import { useBusy } from '@/lib/use-busy';
import { usePoolFilterUrl, useSortUrl } from '@/lib/use-url-param';
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

type SortKey = 'name' | 'type' | 'class' | 'total' | 'weekly';
const SORT_KEYS: readonly SortKey[] = [
    'name',
    'type',
    'class',
    'total',
    'weekly',
];

const DEFAULT_LENGTH = 52;

/** The quote for the contract length picked on a row (52 weeks by default). */
function quoteFor(w: FreeAgent, lengthById: Record<number, number>) {
    const length = lengthById[w.id] ?? DEFAULT_LENGTH;

    return w.quotes.find((q) => q.length_weeks === length);
}

export default function FreeAgency() {
    const { game, available, locked, lengths, has_room } =
        usePage<Props>().props;
    // Filters and sort live in the URL (UI audit, 2026-11-20).
    const [filter, setFilter] = usePoolFilterUrl();
    const [sort, setSort] = useSortUrl<SortKey>(SORT_KEYS);
    const [lengthById, setLengthById] = useState<Record<number, number>>({});
    const { busy, isBusy, track } = useBusy();
    // Stable handlers, so a row only redraws when its own data changes
    // (UI audit, 2026-11-20: memoised rows, like the draft pool).
    const setLength = useCallback(
        (id: number, weeks: number) =>
            setLengthById((current) => ({ ...current, [id]: weeks })),
        [],
    );
    const sign = (id: number, length: number) =>
        router.post(
            gm.freeAgency.sign.url({ save: game.id, saveWrestler: id }),
            { length_weeks: length },
            { preserveScroll: true, ...track(`sign:${id}`) },
        );
    const signRef = useRef(sign);
    signRef.current = sign;
    const onSign = useCallback(
        (id: number, length: number) => signRef.current(id, length),
        [],
    );

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
                case 'total':
                    return quoteFor(w, lengthById)?.total ?? 0;
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
                    <div className="gm-scroll overflow-auto md:max-h-[75vh]">
                        <table className="gm-table gm-table-sticky gm-table-action">
                            <thead>
                                <tr>
                                    <th aria-sort={ariaSort(sort, 'name')}>
                                        {header('name', 'Superstar')}
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
                                        aria-sort={ariaSort(sort, 'total')}
                                    >
                                        {header('total', 'Total', true)}
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
                                {visible.map((w) => (
                                    <FreeAgentRow
                                        key={w.id}
                                        wrestler={w}
                                        saveId={game.id}
                                        length={
                                            lengthById[w.id] ?? DEFAULT_LENGTH
                                        }
                                        lengths={lengths}
                                        hasRoom={has_room}
                                        busy={busy !== null}
                                        signing={isBusy(`sign:${w.id}`)}
                                        onLength={setLength}
                                        onSign={onSign}
                                    />
                                ))}
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

/** One free agent's row, memoised (UI audit, 2026-11-20). */
const FreeAgentRow = memo(function FreeAgentRow({
    wrestler: w,
    saveId,
    length,
    lengths,
    hasRoom,
    busy,
    signing,
    onLength,
    onSign,
}: {
    wrestler: FreeAgent;
    saveId: number;
    length: number;
    lengths: Array<{ value: number; label: string }>;
    hasRoom: boolean;
    busy: boolean;
    signing: boolean;
    onLength: (id: number, weeks: number) => void;
    onSign: (id: number, length: number) => void;
}) {
    const quote = w.quotes.find((q) => q.length_weeks === length);

    return (
        <tr data-testid={`free-agent-${w.id}`}>
            <td>
                <Link
                    href={gm.rosters.show.url({
                        save: saveId,
                        saveWrestler: w.id,
                    })}
                    className="gm-row-link font-semibold hover:text-brand-hot"
                >
                    <WrestlerName wrestler={w} />
                </Link>
            </td>
            <td className="text-ink-dim">{RosterTypeLabels[w.roster_type]}</td>
            <td className="text-ink-dim">
                {WeightClassLabels[w.weight_class]}
            </td>
            <td>
                <InjuryChip injury={w.injury} />
            </td>
            <td>
                <div className="flex flex-wrap gap-1">
                    {w.specialties.map((s) => (
                        <SpecialtyBadge key={s} family={s} />
                    ))}
                </div>
            </td>
            <td>
                <select
                    aria-label={`Contract length for ${w.name}`}
                    className="gm-input py-1 text-sm"
                    value={length}
                    name={`length_${w.id}`}
                    onChange={(e) => onLength(w.id, Number(e.target.value))}
                >
                    {lengths.map((l) => (
                        <option key={l.value} value={l.value}>
                            {l.label}
                        </option>
                    ))}
                </select>
            </td>
            <td className="gm-num text-right">
                {quote && formatMoney(quote.total)}
            </td>
            <td className="gm-num text-right text-ink-dim">
                {quote && formatMoney(quote.weekly)}
            </td>
            <td className="text-right whitespace-nowrap">
                {hasRoom ? (
                    <button
                        type="button"
                        className="gm-btn gm-btn-sm"
                        aria-label={`Sign ${w.name}`}
                        disabled={busy}
                        aria-busy={signing || undefined}
                        onClick={() => onSign(w.id, length)}
                    >
                        <BusyLabel busy={signing} busyText="Signing…">
                            Sign
                        </BusyLabel>
                    </button>
                ) : (
                    <span className="text-xs text-ink-dim">Roster full</span>
                )}
            </td>
        </tr>
    );
});
