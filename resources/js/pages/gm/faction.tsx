import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { TitleMark } from '@/components/gm/badges';
import { OutcomeTable } from '@/components/gm/outcome-table';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { brandName } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { FactionRow } from '@/pages/gm/factions';
import gm from '@/routes/gm';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type Props = GamePageProps & {
    faction: FactionRow;
    /** Every match where 2+ members teamed, newest show first. */
    shows: Array<CardShow & { outcomes: MatchOutcome[] }>;
};

/**
 * One faction (house rule, 2026-11-07): who's in it, its run and record,
 * and its match history in the Reports table.
 */
export default function Faction() {
    const { faction: f, shows, game } = usePage<Props>().props;
    const seeded = f.origin === 'seeded';

    return (
        <>
            <Head title={f.name} />
            <PageTitle
                kicker={f.type === 'stable' ? 'Stable' : 'Tag team'}
                title={f.name}
            >
                <Link
                    href={gm.factions.index.url(game.id)}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    ← All factions
                </Link>
            </PageTitle>

            <Panel className="mb-6">
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="gm-label">Members</dt>
                        <dd className="mt-1">
                            {f.members.map((m, i) => (
                                <Fragment key={m.id}>
                                    {i > 0 && ', '}
                                    <span className="whitespace-nowrap">
                                        <WrestlerName wrestler={m} />
                                        <span className="ml-1 text-xs text-ink-faint">
                                            {m.brand
                                                ? brandName(m.brand)
                                                : 'FA'}
                                        </span>
                                    </span>
                                </Fragment>
                            ))}
                        </dd>
                    </div>
                    <div>
                        <dt className="gm-label">In game</dt>
                        <dd className="mt-1 text-ink-dim">
                            {seeded ? 'Week 1' : (f.formed ?? '—')}
                            {f.disbanded ? ` – ${f.disbanded}` : ' – now'}
                            {!f.active && (
                                <span className="gm-label ml-2 text-ink-faint">
                                    Disbanded
                                </span>
                            )}
                        </dd>
                        {seeded && (
                            <dd className="text-xs text-ink-faint">
                                Real life: {f.formed ?? '?'} –{' '}
                                {f.real_disbanded ?? 'present'}
                            </dd>
                        )}
                    </div>
                    <div>
                        <dt className="gm-label">Record</dt>
                        <dd className="gm-num mt-1 text-lg font-semibold">
                            {f.wins}–{f.losses}
                        </dd>
                    </div>
                    <div>
                        <dt className="gm-label">Titles together</dt>
                        <dd className="mt-1">
                            {f.titles.length === 0 ? (
                                <span className="text-ink-faint">—</span>
                            ) : (
                                f.titles.map((t) => (
                                    <div
                                        key={t.id}
                                        className={cn(
                                            'flex items-center gap-1.5',
                                            t.current && 'text-gold-hot',
                                        )}
                                    >
                                        <TitleMark name={t.title} />
                                        {t.title}
                                        <span className="text-xs text-ink-faint">
                                            {t.current ? 'champions' : t.won}
                                        </span>
                                    </div>
                                ))
                            )}
                        </dd>
                    </div>
                </dl>
            </Panel>

            {shows.length === 0 ? (
                <EmptyState>No matches together yet.</EmptyState>
            ) : (
                <div className="space-y-6">
                    {shows.map((show) => (
                        <Panel
                            key={show.id}
                            title={`Week ${show.week_number} · ${show.name}`}
                            bodyClassName="p-0"
                        >
                            <OutcomeTable outcomes={show.outcomes} />
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}
