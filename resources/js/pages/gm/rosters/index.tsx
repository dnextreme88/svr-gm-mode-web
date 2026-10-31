import { Head, Link, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import {
    InjuryChip,
    MoraleBadge,
    SpecialtyBadge,
    TitleBelt,
} from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { PoolFilters } from '@/components/gm/pool-filters';
import { ariaSort, nextSort, SortHeader } from '@/components/gm/sort-header';
import { StreakMeter } from '@/components/gm/streak-meter';
import type { PoolFilter, RosterSort, RosterSortKey } from '@/lib/gm';
import { emptyPoolFilter, filterPool, formatMoney, sortRoster } from '@/lib/gm';
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

/** Contracts with this many weeks or fewer left are flagged as expiring. */
const EXPIRING_WEEKS = 4;

export default function Rosters() {
    const { game, brand, wrestlers, pending } = usePage<Props>().props;
    const own = brand === game.player_brand;
    const [filter, setFilter] = useState<PoolFilter>(emptyPoolFilter);
    const [sort, setSort] = useState<RosterSort>(null);
    const visible = useMemo(
        () => sortRoster(filterPool(wrestlers, filter), sort),
        [wrestlers, filter, sort],
    );
    const url = (id: number) =>
        gm.rosters.show.url({ save: game.id, saveWrestler: id });
    // The name is the real link; a click anywhere else on the row opens the
    // same page.
    const openRow = (e: MouseEvent, id: number) => {
        if ((e.target as HTMLElement).closest('a')) {
            return;
        }

        router.visit(url(id));
    };
    const toggleSort = (key: RosterSortKey) => setSort((s) => nextSort(s, key));
    const sortHeader = (
        key: RosterSortKey,
        label: ReactNode,
        right = false,
    ) => (
        <SortHeader
            label={label}
            active={sort?.key === key ? sort.dir : null}
            onClick={() => toggleSort(key)}
            right={right}
        />
    );

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
                            aria-current={b === brand ? 'page' : undefined}
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
                >
                    <div className="border-b border-white/6 px-4 py-2">
                        <PoolFilters
                            value={filter}
                            onChange={setFilter}
                            types={['all', 'superstar', 'diva', 'legend']}
                        />
                    </div>
                    <div className="gm-scroll max-h-[75vh] overflow-auto">
                        <table className="gm-table gm-table-sticky">
                            <thead>
                                <tr>
                                    <th aria-sort={ariaSort(sort, 'name')}>
                                        {sortHeader('name', 'Superstar')}
                                    </th>
                                    <th aria-sort={ariaSort(sort, 'fatigue')}>
                                        {sortHeader('fatigue', 'Fatigue')}
                                    </th>
                                    <th>Injury</th>
                                    <th aria-sort={ariaSort(sort, 'morale')}>
                                        {sortHeader('morale', 'Morale')}
                                    </th>
                                    <th
                                        className="text-right"
                                        aria-sort={ariaSort(sort, 'record')}
                                    >
                                        {sortHeader(
                                            'record',
                                            <abbr
                                                title="Wins–losses"
                                                className="no-underline"
                                            >
                                                W–L
                                            </abbr>,
                                            true,
                                        )}
                                    </th>
                                    <th>Streak</th>
                                    <th>Specialties</th>
                                    <th
                                        className="text-right"
                                        aria-sort={ariaSort(sort, 'contract')}
                                    >
                                        {sortHeader(
                                            'contract',
                                            'Contract',
                                            true,
                                        )}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="text-center text-ink-faint"
                                        >
                                            {filter.search.trim()
                                                ? `No one matches “${filter.search}”.`
                                                : 'No one matches these filters.'}
                                        </td>
                                    </tr>
                                )}
                                {visible.map((w) => (
                                    <tr
                                        key={w.id}
                                        data-testid="roster-row"
                                        className="group cursor-pointer transition-colors hover:bg-white/[0.04]"
                                        onClick={(e) => openRow(e, w.id)}
                                    >
                                        <td className="max-w-36 sm:max-w-none">
                                            <Link
                                                href={url(w.id)}
                                                className="gm-row-link font-semibold group-hover:text-brand-hot sm:whitespace-nowrap"
                                            >
                                                <WrestlerName wrestler={w} />
                                            </Link>
                                            {(w.roster_type !== 'superstar' ||
                                                w.titles.length > 0 ||
                                                w.sitting_out) && (
                                                <div className="mt-0.5 flex flex-wrap gap-1 sm:flex-nowrap sm:whitespace-nowrap">
                                                    {w.roster_type !==
                                                        'superstar' && (
                                                        <span className="font-condensed text-xs tracking-widest text-ink-faint uppercase">
                                                            {
                                                                RosterTypeLabels[
                                                                    w
                                                                        .roster_type
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
                                            )}
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
                                        <td className="gm-num text-right whitespace-nowrap">
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
                                        <td className="text-right whitespace-nowrap">
                                            {w.contract ? (
                                                <ContractCell
                                                    weeks={
                                                        w.contract
                                                            .weeks_remaining
                                                    }
                                                    weekly={
                                                        w.contract.weekly_cost
                                                    }
                                                />
                                            ) : (
                                                '—'
                                            )}
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

function ContractCell({ weeks, weekly }: { weeks: number; weekly: number }) {
    const expiring = weeks <= EXPIRING_WEEKS;

    return (
        <span className={cn('gm-num', expiring && 'text-zone-serious')}>
            {expiring && (
                <>
                    <TriangleAlert
                        aria-hidden
                        className="mr-1 inline size-3.5 align-[-2px]"
                    />
                    <span className="sr-only">Expiring: </span>
                </>
            )}
            {weeks}w
            <span className="ml-1.5 text-xs text-ink-faint">
                {formatMoney(weekly)}/wk
            </span>
        </span>
    );
}
