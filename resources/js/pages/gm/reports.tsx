import { Head, usePage } from '@inertiajs/react';
import type { KeyboardEvent } from 'react';
import { AttendanceChart } from '@/components/gm/attendance-chart';
import type {
    AttendancePoint,
    SharedPpvMark,
} from '@/components/gm/attendance-chart';
import { BrandBug } from '@/components/gm/brand-bug';
import { OutcomeTable } from '@/components/gm/outcome-table';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { brandName } from '@/lib/gm';
import { useUrlParam } from '@/lib/use-url-param';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type ShowWithOutcomes = CardShow & { outcomes: MatchOutcome[] };

type LeaderRow = { name: string; brand: Brand | null; count: number };

/** The Records book (house rule, 2026-11-21): PPVs only, both brands. */
export type PpvRecordsData = {
    leaders: Array<{ key: string; label: string; rows: LeaderRow[] }>;
    rumble_winners: Array<{
        season: number;
        name: string;
        brand: Brand | null;
        eliminations: number;
    }>;
    survivor_series: Array<{ season: number; winner: Brand | null }>;
    interbrand_series: Array<{
        season: number;
        raw_wins: number;
        smackdown_wins: number;
        winner: Brand | null;
    }>;
    mitb: Array<{
        season: number;
        name: string;
        brand: Brand;
        status: string;
        cashed_in: boolean;
    }>;
    ppv_shows: ShowWithOutcomes[];
};

type Props = GamePageProps & {
    shows: ShowWithOutcomes[];
    /** Every aired night's crowd, both brands (house rule, 2026-11-01). */
    attendance: AttendancePoint[];
    /** The shared PPVs that mark the chart's week axis (house rule, 2026-11-11). */
    shared_ppvs?: SharedPpvMark[];
    records?: PpvRecordsData;
};

const tabs = [
    { key: 'results', label: 'Results' },
    { key: 'records', label: 'Records book' },
] as const;

type TabKey = (typeof tabs)[number]['key'];

export default function Reports() {
    const {
        shows,
        attendance,
        shared_ppvs = [],
        records,
    } = usePage<Props>().props;
    // The open tab lives in the URL, like the Docs tabs (2026-11-21).
    const [tabParam, setTabParam] = useUrlParam('tab', 'results');
    const tab: TabKey = tabParam === 'records' ? 'records' : 'results';

    const onTabKey = (e: KeyboardEvent<HTMLButtonElement>) => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            const next = tab === 'results' ? 'records' : 'results';
            setTabParam(next);
            document.getElementById(`reports-tab-${next}`)?.focus();
        }
    };

    return (
        <>
            <Head title="Reports" />
            <PageTitle kicker="Match by match" title="Reports" />

            <div
                role="tablist"
                aria-label="Reports sections"
                className="mb-6 flex gap-1 border-b border-white/8"
            >
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        role="tab"
                        id={`reports-tab-${t.key}`}
                        aria-controls="reports-panel"
                        aria-selected={tab === t.key}
                        tabIndex={tab === t.key ? 0 : -1}
                        onClick={() => setTabParam(t.key)}
                        onKeyDown={onTabKey}
                        className={cn(
                            'relative -mb-px border-b-2 px-3 py-2.5 font-condensed text-sm font-semibold tracking-[0.12em] whitespace-nowrap uppercase transition-colors',
                            tab === t.key
                                ? 'border-brand text-white'
                                : 'border-transparent text-ink-dim hover:text-ink',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            <div
                role="tabpanel"
                id="reports-panel"
                aria-labelledby={`reports-tab-${tab}`}
            >
                {tab === 'results' ? (
                    <>
                        <Panel title="Fan attendance" className="mb-6">
                            <AttendanceChart
                                points={attendance}
                                sharedPpvs={shared_ppvs}
                            />
                        </Panel>
                        <ShowList
                            shows={shows}
                            empty="No shows have aired yet."
                        />
                    </>
                ) : (
                    <RecordsBook records={records} />
                )}
            </div>
        </>
    );
}

function ShowList({
    shows,
    empty,
}: {
    shows: ShowWithOutcomes[];
    empty: string;
}) {
    if (shows.length === 0) {
        return <EmptyState>{empty}</EmptyState>;
    }

    return (
        <div className="space-y-6">
            {shows.map((show) => (
                <Panel
                    key={show.id}
                    title={`Week ${show.week_number} · ${show.name}`}
                    // Off-screen shows skip rendering (UI audit, 2026-11-20).
                    className="gm-cv-panel"
                    bodyClassName="p-0"
                >
                    {show.outcomes.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-ink-faint">
                            No matches.
                        </p>
                    ) : (
                        <OutcomeTable outcomes={show.outcomes} />
                    )}
                </Panel>
            ))}
        </div>
    );
}

/**
 * The Records book (house rule, 2026-11-21): PPV leaderboards, the
 * interbrand PPVs' history, then every PPV's results. Weekly shows stay on
 * the Results tab.
 */
export function RecordsBook({ records }: { records?: PpvRecordsData }) {
    if (!records) {
        return <EmptyState>No PPVs have aired yet.</EmptyState>;
    }

    return (
        <div className="space-y-6" data-testid="records-book">
            <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                {records.leaders.map((board) => (
                    <Panel
                        key={board.key}
                        title={board.label}
                        bodyClassName="p-0"
                    >
                        {board.rows.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-ink-faint">
                                Nobody yet.
                            </p>
                        ) : (
                            <ol
                                className="divide-y divide-white/5 text-sm"
                                aria-label={board.label}
                            >
                                {board.rows.map((row, i) => (
                                    <li
                                        key={row.name}
                                        className="flex items-center gap-3 px-4 py-2"
                                    >
                                        <span className="gm-num w-5 text-ink-faint">
                                            {i + 1}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-ink">
                                            {row.name}
                                        </span>
                                        {row.brand && (
                                            <span className="gm-label">
                                                {brandName(row.brand)}
                                            </span>
                                        )}
                                        <span className="gm-num text-gold-hot">
                                            {row.count}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                ))}
            </div>

            <div className="grid gap-6 md:grid-cols-2">
                <HistoryPanel
                    title="Royal Rumble winners"
                    rows={records.rumble_winners.map((r) => ({
                        season: r.season,
                        brand: r.brand,
                        text: `${r.name} · ${r.eliminations} eliminated`,
                    }))}
                />
                <HistoryPanel
                    title="Money in the Bank"
                    rows={records.mitb.map((m) => ({
                        season: m.season,
                        brand: m.brand,
                        text: `${m.name}${m.cashed_in ? ' · cashed in' : ''}`,
                    }))}
                />
                <HistoryPanel
                    title="Survivor Series 5 vs 5"
                    rows={records.survivor_series.map((s) => ({
                        season: s.season,
                        brand: s.winner,
                        text: s.winner ? `${brandName(s.winner)} won` : '—',
                    }))}
                />
                <HistoryPanel
                    title="WrestleMania interbrand series"
                    rows={records.interbrand_series.map((s) => ({
                        season: s.season,
                        brand: s.winner,
                        text: `Raw ${s.raw_wins} – ${s.smackdown_wins} Smackdown`,
                    }))}
                />
            </div>

            <h2 className="gm-heading text-2xl">PPV results</h2>
            <ShowList
                shows={records.ppv_shows}
                empty="No PPVs have aired yet."
            />
        </div>
    );
}

function HistoryPanel({
    title,
    rows,
}: {
    title: string;
    rows: Array<{ season: number; brand: Brand | null; text: string }>;
}) {
    return (
        <Panel title={title} bodyClassName="p-0">
            {rows.length === 0 ? (
                <p className="px-4 py-3 text-sm text-ink-faint">
                    Not yet held.
                </p>
            ) : (
                <ul className="divide-y divide-white/5 text-sm">
                    {rows.map((row, i) => (
                        <li
                            key={`${row.season}-${i}`}
                            className="flex items-center gap-3 px-4 py-2"
                        >
                            <span className="gm-label w-20 shrink-0">
                                Season {row.season}
                            </span>
                            {row.brand && (
                                <BrandBug brand={row.brand} size="sm" />
                            )}
                            <span className="min-w-0 flex-1 truncate text-ink">
                                {row.text}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
