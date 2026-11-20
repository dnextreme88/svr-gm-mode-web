import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { TitleBeltImage } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { ReignChart } from '@/components/gm/reign-chart';
import { WeekCell } from '@/components/gm/week-cell';
import type { Named } from '@/components/gm/wrestler-name';
import { WrestlerNames } from '@/components/gm/wrestler-name';
import { prefersReducedMotion } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, ReignEndReason, TitleHolderType } from '@/types/enums';
import { ReignEndReasonLabels } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

/** A name split for colour coding (a faction's name is one plain part). */
type NamePart = Named & { name: string };

type ReignMatch = {
    id: number;
    /** The match the title was won in, or a defense. */
    kind: 'won' | 'defense';
    /** Defenses: 1 for the first defense of the reign. */
    number: number | null;
    opponents: string;
    /** Each opposing side, split for colour coding. */
    opponent_sides?: NamePart[][];
    /** The absolute week it aired. */
    week?: number;
    season: number;
    season_week: number;
    ppv: string | null;
    match_type: string | null;
    /** That match on its week's results. */
    link: string;
};

type LineageRow = {
    id: number;
    /** Position in the title's lineage. */
    reign_number: number | null;
    holder: string;
    holder_parts?: NamePart[];
    holder_type: TitleHolderType;
    /** The holder's Nth reign with this title. */
    holder_reign: number | null;
    won_week: number;
    won_season: number;
    won_season_week: number;
    won_ppv: string | null;
    won_link: string | null;
    /** "Awarded", or the match the title was won in. */
    how: string | null;
    /** Vacant rows: why the title was vacated. */
    vacated_reason: ReignEndReason | null;
    weeks_held: number;
    defenses: number;
    /** Newest first: the defenses, then the match the title was won in. */
    matches: ReignMatch[];
    active: boolean;
};

type TitleRow = {
    id: number;
    key: string;
    name: string;
    brand: Brand;
    is_tag: boolean;
    current: LineageRow | null;
    vacant: boolean;
    lineage: LineageRow[];
};

type HolderRecord = {
    holder: string;
    holder_parts?: NamePart[];
    value: number;
    /** Several superstars share it: listed with commas, not as a team. */
    tied?: boolean;
};

type RecordSet = {
    longest: (HolderRecord & { championship: string }) | null;
    shortest: (HolderRecord & { championship: string }) | null;
    most_reigns: HolderRecord | null;
    most_defenses: HolderRecord | null;
};

/** One title's stats (house rule, 2026-11-21). */
type TypeCount = { types: string[]; value: number };

export type TitleStatSet = {
    title_matches: number;
    ppv_title_matches: number;
    vacated: number;
    vacated_reasons: Partial<Record<ReignEndReason, number>>;
    most_type: TypeCount | null;
    least_type: TypeCount | null;
    /** Different superstars who held it (a tag title counts each partner). */
    champions: number;
    /** Tag titles: different pairs of partners. */
    teams: number | null;
    defenses: number;
    most_reigns_superstar: HolderRecord | null;
    most_reigns_team: HolderRecord | null;
};

type SlamTitle = {
    group: 'world' | 'secondary' | 'tag';
    championship: string;
    season: number;
    season_week: number;
    won_ppv: string | null;
};

export type GrandSlam = HolderRecord & {
    /** The first title they won from each group. */
    titles: SlamTitle[];
    /** When the last of the three was won. */
    attained: { season: number; season_week: number; ppv: string | null };
};

type OverallStats = {
    most_titles: (HolderRecord & { titles: string[] }) | null;
    grand_slams: GrandSlam[];
};

type Props = GamePageProps & {
    titles: TitleRow[];
    stats?: {
        per_title: Record<string, TitleStatSet>;
        overall: OverallStats;
    };
    hall: Record<
        Brand,
        Array<{
            id?: number;
            name: string;
            holder: string | null;
            holder_parts?: NamePart[] | null;
            weeks_held: number | null;
            defenses: number | null;
        }>
    >;
    records: { overall: RecordSet; per_title: Record<string, RecordSet> };
    briefcases: Array<{
        holder: string;
        holder_parts?: NamePart[];
        brand: Brand;
        expires_week: number;
        expires_season?: number;
        expires_season_week?: number;
    }>;
};

const plural = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

/** A holder or side, colour-coded when the parts are there. */
function Holder({
    name,
    parts,
    separator,
}: {
    name: string;
    parts?: NamePart[] | null;
    separator?: string;
}) {
    return parts && parts.length > 0 ? (
        <WrestlerNames names={parts} portrait="sm" separator={separator} />
    ) : (
        name
    );
}

export default function TitleHistory() {
    const page = usePage<Props>();
    const { game, titles, hall, records, briefcases, stats } = page.props;
    // `?title={key}` opens that title's lineage (linked from wrestler pages).
    const [selected, setSelected] = useState<number>(() => {
        const key = new URLSearchParams(page.url.split('?')[1] ?? '').get(
            'title',
        );

        return (titles.find((t) => t.key === key) ?? titles[0])?.id ?? 0;
    });
    const title = titles.find((t) => t.id === selected) ?? titles[0];
    const ownVacant = titles.some(
        (t) => t.vacant && t.brand === game.player_brand,
    );

    // Keep the selection in the URL so refresh, Back and links keep it.
    const select = (id: number, scroll = false) => {
        setSelected(id);
        const key = titles.find((t) => t.id === id)?.key;

        if (key) {
            router.replace({
                url: gm.titles.history.url(game.id, { query: { title: key } }),
                preserveState: true,
                preserveScroll: true,
            });
        }

        if (scroll) {
            // Smooth only when the player hasn't asked for less motion (UI
            // audit, 2026-11-20): the CSS rule doesn't reach a scroll from JS.
            document.getElementById('lineage')?.scrollIntoView({
                behavior: prefersReducedMotion() ? 'auto' : 'smooth',
                block: 'start',
            });
        }
    };

    return (
        <>
            <Head title="Title History" />
            <PageTitle kicker="Every reign, every season" title="Title History">
                {ownVacant && (
                    <Link
                        href={gm.titles.assign.url(game.id)}
                        className="gm-btn gm-btn-gold gm-btn-sm"
                    >
                        Award a vacant title
                    </Link>
                )}
            </PageTitle>

            <section aria-labelledby="hall-heading" className="mb-8">
                <h2 id="hall-heading" className="gm-label mb-3">
                    Current Champions
                </h2>
                <div className="grid gap-4 md:grid-cols-2">
                    {(['raw', 'smackdown'] as const).map((brand) => (
                        <div
                            key={brand}
                            data-brand-tint={brand}
                            className="gm-panel p-4"
                        >
                            <BrandBug brand={brand} size="sm" />
                            <ul className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {(hall[brand] ?? []).map((h) => (
                                    <li key={h.name} className="min-w-0">
                                        <button
                                            type="button"
                                            // No lineage to open yet: not a dead button.
                                            disabled={h.id === undefined}
                                            onClick={() =>
                                                h.id !== undefined &&
                                                select(h.id, true)
                                            }
                                            aria-label={`${h.name}: ${h.holder ?? 'Vacant'}${h.id !== undefined ? '. Show lineage' : ''}`}
                                            className="gm-row-link block h-full w-full border-l-2 border-gold/60 bg-white/[0.03] px-3 py-2 text-left transition-colors hover:bg-white/[0.07]"
                                        >
                                            <span className="flex min-w-0 flex-col items-start gap-1 font-condensed text-sm tracking-[0.04em] [overflow-wrap:anywhere] text-gold uppercase sm:text-base sm:tracking-[0.08em] xl:text-xl xl:tracking-[0.14em]">
                                                <TitleBeltImage
                                                    name={h.name}
                                                    size="md"
                                                    decorative
                                                    className="h-8 sm:h-10"
                                                />
                                                <span className="min-w-0">
                                                    {h.name.replace(
                                                        ' Championship',
                                                        '',
                                                    )}
                                                </span>
                                            </span>
                                            <span className="gm-heading mt-0.5 block text-base sm:text-lg">
                                                {h.holder ? (
                                                    <Holder
                                                        name={h.holder}
                                                        parts={h.holder_parts}
                                                    />
                                                ) : (
                                                    <span className="text-ink-faint">
                                                        Vacant
                                                    </span>
                                                )}
                                            </span>
                                            {h.holder && (
                                                <span className="block text-sm text-ink-dim">
                                                    {plural(
                                                        h.weeks_held ?? 0,
                                                        'week',
                                                    )}{' '}
                                                    ·{' '}
                                                    {plural(
                                                        h.defenses ?? 0,
                                                        'defense',
                                                    )}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
                {briefcases.length > 0 && (
                    <div className="mt-4">
                        <h3 className="gm-label mb-2">Money in the Bank</h3>
                        <ul className="flex flex-wrap gap-3">
                            {briefcases.map((b) => (
                                <li
                                    key={`${b.brand}-${b.holder}`}
                                    data-brand-tint={b.brand}
                                    className="gm-panel flex items-center gap-3 px-3 py-2"
                                >
                                    <BrandBug brand={b.brand} size="sm" />
                                    <span className="gm-heading">
                                        <Holder
                                            name={b.holder}
                                            parts={b.holder_parts}
                                        />
                                    </span>
                                    <span className="text-sm text-ink-dim">
                                        until{' '}
                                        {b.expires_season !== undefined
                                            ? `S${b.expires_season} W${b.expires_season_week}`
                                            : `W${b.expires_week}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </section>

            <section aria-labelledby="reigns-heading" className="mb-8">
                <Panel title={<span id="reigns-heading">Current reigns</span>}>
                    <ReignChart
                        titles={titles}
                        defaultBrand={game.player_brand}
                    />
                </Panel>
            </section>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
                <nav
                    aria-label="Championships"
                    className="gm-panel min-w-0 self-start p-1"
                >
                    {/* Below lg each brand is its logo beside a grid of its
                        titles (2 columns from sm), so nothing scrolls
                        sideways; from lg it's the sidebar list. */}
                    {(['raw', 'smackdown'] as const).map((brand, i) => (
                        <Fragment key={brand}>
                            {i > 0 && (
                                <hr
                                    className="my-1 border-0 border-t border-white/15 lg:my-2"
                                    data-testid="brand-divider"
                                />
                            )}
                            <div
                                role="group"
                                aria-label={`${brand === 'raw' ? 'Raw' : 'Smackdown'} titles`}
                                data-brand-tint={brand}
                                className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-1 lg:block"
                            >
                                <div className="w-[6.5rem] px-2 lg:w-auto lg:px-3 lg:pt-2 lg:pb-1.5">
                                    <BrandBug brand={brand} size="sm" />
                                </div>
                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:block">
                                    {titles
                                        .filter((t) => t.brand === brand)
                                        .map((t) => (
                                            <button
                                                key={t.id}
                                                type="button"
                                                onClick={() => select(t.id)}
                                                aria-pressed={
                                                    t.id === title?.id
                                                }
                                                className={cn(
                                                    'gm-row-link flex min-h-11 w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors lg:min-h-0',
                                                    t.id === title?.id
                                                        ? 'bg-brand/15 text-ink shadow-[inset_3px_0_0_var(--gm-brand)]'
                                                        : 'text-ink-dim hover:text-ink',
                                                )}
                                            >
                                                <TitleBeltImage
                                                    name={t.name}
                                                    decorative
                                                />
                                                {t.name}
                                            </button>
                                        ))}
                                </div>
                            </div>
                        </Fragment>
                    ))}
                </nav>

                {title && (
                    <div id="lineage" className="min-w-0 scroll-mt-4 space-y-6">
                        <Panel
                            title={
                                <span className="flex items-center gap-2">
                                    <TitleBeltImage
                                        name={title.name}
                                        size="md"
                                        decorative
                                    />
                                    {title.name} lineage
                                </span>
                            }
                            bodyClassName="p-0"
                        >
                            {title.lineage.length === 0 ? (
                                <div className="p-4">
                                    <EmptyState>No history yet.</EmptyState>
                                </div>
                            ) : (
                                <div className="gm-scroll overflow-x-auto">
                                    <table className="gm-table">
                                        <thead>
                                            <tr>
                                                <th className="text-right">
                                                    #
                                                </th>
                                                <th>Holder</th>
                                                <th>Won</th>
                                                <th className="text-right">
                                                    Reign
                                                </th>
                                                <th>How</th>
                                                <th className="text-right">
                                                    Weeks
                                                </th>
                                                <th className="text-right">
                                                    Defenses
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {title.lineage.map((r) => (
                                                <ReignRows key={r.id} r={r} />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Panel>

                        <div className="grid gap-6 md:grid-cols-2">
                            <RecordsPanel
                                title={`${title.name.replace(' Championship', '')} records`}
                                records={records.per_title[title.id]}
                                tag={title.is_tag}
                            >
                                {stats?.per_title[title.id] && (
                                    <TitleStatRows
                                        stats={stats.per_title[title.id]}
                                        tag={title.is_tag}
                                    />
                                )}
                            </RecordsPanel>
                            <RecordsPanel
                                title="All titles"
                                records={records.overall}
                            >
                                {stats && (
                                    <OverallStatRows stats={stats.overall} />
                                )}
                            </RecordsPanel>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * One reign, then a row for each match it was defended in and the match it
 * was won in (newest first), each linked to that match's results.
 */
function ReignRows({ r }: { r: LineageRow }) {
    const vacant = r.holder_type === 'vacant';
    // The opponent cell is the real link; a click anywhere else on the row
    // opens the same results.
    const openRow = (e: MouseEvent, link: string) => {
        if ((e.target as HTMLElement).closest('a')) {
            return;
        }

        // Ctrl / Cmd / middle-click open a new tab, like the link.
        if (e.ctrlKey || e.metaKey || e.button === 1) {
            window.open(link, '_blank', 'noopener');

            return;
        }

        router.visit(link);
    };

    return (
        <>
            <tr
                data-testid={`reign-${r.id}`}
                className={cn(vacant && 'text-ink-dim italic')}
            >
                <td className="gm-num text-right">{r.reign_number ?? '—'}</td>
                <td
                    className={cn(
                        'font-semibold',
                        r.active && !vacant && 'text-gold-hot',
                    )}
                >
                    {vacant ? (
                        r.holder
                    ) : (
                        <Holder name={r.holder} parts={r.holder_parts} />
                    )}
                </td>
                <td>
                    {r.won_link ? (
                        <Link
                            href={r.won_link}
                            className="gm-row-link hover:text-brand-hot"
                        >
                            <WeekCell
                                season={r.won_season}
                                week={r.won_season_week}
                                ppv={r.won_ppv}
                            />
                        </Link>
                    ) : (
                        <WeekCell
                            season={r.won_season}
                            week={r.won_season_week}
                            ppv={r.won_ppv}
                        />
                    )}
                </td>
                <td className="gm-num text-right">{r.holder_reign ?? '—'}</td>
                <td className="text-ink-dim">
                    {vacant
                        ? r.vacated_reason
                            ? `Vacated: ${ReignEndReasonLabels[r.vacated_reason]}`
                            : 'Vacant'
                        : (r.how ?? '—')}
                </td>
                <td className="gm-num text-right">{r.weeks_held}</td>
                <td className="gm-num text-right">
                    {vacant ? '—' : r.defenses}
                </td>
            </tr>
            {r.matches.map((d) => (
                <tr
                    key={d.id}
                    data-testid={`${d.kind}-${d.id}`}
                    className="group cursor-pointer text-sm transition-colors hover:bg-white/[0.04]"
                    onClick={(e) => openRow(e, d.link)}
                    onAuxClick={(e) => e.button === 1 && openRow(e, d.link)}
                >
                    <td />
                    <td className="pl-6 font-semibold">
                        <Link
                            href={d.link}
                            className="gm-row-link group-hover:text-brand-hot"
                            aria-label={
                                d.kind === 'won'
                                    ? `Title win against ${d.opponents}`
                                    : `Defense ${d.number} against ${d.opponents}`
                            }
                        >
                            vs.{' '}
                            {d.opponent_sides && d.opponent_sides.length > 0
                                ? d.opponent_sides.map((side, i) => (
                                    <Fragment key={i}>
                                        {i > 0 && ', '}
                                        <WrestlerNames names={side} />
                                    </Fragment>
                                ))
                                : d.opponents}
                        </Link>
                    </td>
                    <td>
                        <WeekCell
                            season={d.season}
                            week={d.season_week}
                            ppv={d.ppv}
                        />
                    </td>
                    <td />
                    <td className="text-ink-dim">{d.match_type ?? '—'}</td>
                    <td />
                    <td className="gm-num text-right">
                        {d.kind === 'won' ? (
                            <span className="gm-label text-gold">Won</span>
                        ) : (
                            d.number
                        )}
                    </td>
                </tr>
            ))}
        </>
    );
}

type RecordLine = [string, HolderRecord | null | undefined, string];

function RecordsPanel({
    title,
    records,
    tag = false,
    children,
}: {
    title: string;
    records: RecordSet | undefined;
    /** A tag title: its most reigns are split by superstar and by team below. */
    tag?: boolean;
    children?: ReactNode;
}) {
    const rows: RecordLine[] = [
        ['Longest reign', records?.longest, 'week'],
        ...(tag
            ? []
            : [['Most reigns', records?.most_reigns, 'reign'] as RecordLine]),
        ['Most defenses', records?.most_defenses, 'defense'],
        ['Shortest reign', records?.shortest, 'week'],
    ];

    return (
        <Panel title={title}>
            <dl className="space-y-2 text-sm">
                {rows.map(([label, record, unit]) => (
                    <StatRow key={label} label={label}>
                        <HolderValue record={record} unit={unit} />
                    </StatRow>
                ))}
                {children}
            </dl>
        </Panel>
    );
}

/** One line of a records card: the label left, the value right. */
function StatRow({
    label,
    children,
    testId,
}: {
    label: string;
    children: ReactNode;
    testId?: string;
}) {
    return (
        <div className="flex justify-between gap-3" data-testid={testId}>
            <dt className="text-ink-dim">{label}</dt>
            <dd className="min-w-0 text-right font-semibold">{children}</dd>
        </div>
    );
}

function HolderValue({
    record,
    unit,
}: {
    record: HolderRecord | null | undefined;
    unit: string;
}) {
    return record ? (
        <>
            <Holder
                name={record.holder}
                parts={record.holder_parts}
                separator={record.tied ? ', ' : undefined}
            />
            <span className="ml-2 font-normal text-ink-dim">
                {plural(record.value, unit)}
            </span>
        </>
    ) : (
        '—'
    );
}

/** "Hardcore — Single · 4 matches", ties listed together. */
function TypeValue({ count }: { count: TypeCount | null }) {
    return count ? (
        <>
            {count.types.join(', ')}
            <span className="ml-2 font-normal text-ink-dim">
                {plural(count.value, 'match', 'matches')}
            </span>
        </>
    ) : (
        '—'
    );
}

/**
 * A title's stats under its records (house rule, 2026-11-21). Tag titles
 * add their most reigns by superstar and by team, both named by the
 * superstars themselves, never a faction.
 */
function TitleStatRows({ stats, tag }: { stats: TitleStatSet; tag: boolean }) {
    const reasons = Object.entries(stats.vacated_reasons) as Array<
        [ReignEndReason, number]
    >;

    return (
        <>
            {tag && (
                <>
                    <StatRow
                        label="Most reigns (superstar)"
                        testId="stat-most-reigns-superstar"
                    >
                        <HolderValue
                            record={stats.most_reigns_superstar}
                            unit="reign"
                        />
                    </StatRow>
                    <StatRow
                        label="Most reigns (team)"
                        testId="stat-most-reigns-team"
                    >
                        <HolderValue
                            record={stats.most_reigns_team}
                            unit="reign"
                        />
                    </StatRow>
                </>
            )}
            <hr className="border-0 border-t border-white/10" />
            <StatRow label="Title matches held on PPV" testId="stat-ppv">
                <span className="gm-num">{stats.ppv_title_matches}</span>
                <span className="ml-2 font-normal text-ink-dim">
                    of{' '}
                    {plural(
                        stats.title_matches,
                        'title match',
                        'title matches',
                    )}
                </span>
            </StatRow>
            <StatRow label="Times vacated" testId="stat-vacated">
                <span className="gm-num">{stats.vacated}</span>
                {reasons.length > 0 && (
                    <span className="block text-xs font-normal text-ink-dim">
                        {reasons
                            .map(
                                ([reason, n]) =>
                                    `${ReignEndReasonLabels[reason]} ${n}`,
                            )
                            .join(' · ')}
                    </span>
                )}
            </StatRow>
            <StatRow label="Most contested in" testId="stat-most-type">
                <TypeValue count={stats.most_type} />
            </StatRow>
            <StatRow label="Least contested in" testId="stat-least-type">
                <TypeValue count={stats.least_type} />
            </StatRow>
            <StatRow label="Different champions" testId="stat-champions">
                {plural(stats.champions, 'superstar')}
                {stats.teams !== null && (
                    <span className="ml-2 font-normal text-ink-dim">
                        {plural(stats.teams, 'team')}
                    </span>
                )}
            </StatRow>
            <StatRow label="Total defenses" testId="stat-defenses">
                <span className="gm-num">{stats.defenses}</span>
            </StatRow>
        </>
    );
}

/**
 * Across every title, both brands (house rule, 2026-11-21): the superstar
 * who has won the most different titles, and the Grand Slam champions.
 */
function OverallStatRows({ stats }: { stats: OverallStats }) {
    const [open, setOpen] = useState(false);
    const slams = stats.grand_slams;

    return (
        <>
            <hr className="border-0 border-t border-white/10" />
            <StatRow label="Most titles won" testId="stat-most-titles">
                <HolderValue record={stats.most_titles} unit="title" />
                {stats.most_titles && (
                    <span className="block text-xs font-normal text-ink-dim">
                        {stats.most_titles.titles
                            .map((t) => t.replace(' Championship', ''))
                            .join(' · ')}
                    </span>
                )}
            </StatRow>
            <StatRow label="Grand Slam champions" testId="stat-grand-slams">
                <span className="inline-flex items-center gap-3">
                    <span className="gm-num">{slams.length}</span>
                    {slams.length > 0 && (
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            onClick={() => setOpen(true)}
                            aria-haspopup="dialog"
                        >
                            View
                        </button>
                    )}
                </span>
            </StatRow>
            <ConfirmDialog
                open={open}
                wide
                title="Grand Slam champions"
                cancelLabel="Close"
                onCancel={() => setOpen(false)}
                message={<GrandSlamTable slams={slams} />}
            />
        </>
    );
}

const SLAM_GROUPS: Record<SlamTitle['group'], string> = {
    world: 'World title',
    secondary: 'Secondary title',
    tag: 'Tag team title',
};

/**
 * Who has won a world title (WWE or World Heavyweight), a secondary title
 * (Intercontinental or United States) and a tag title (WWE or World Tag
 * Team), on either brand: the first of each they won, and when the last
 * one completed the set.
 */
export function GrandSlamTable({ slams }: { slams: GrandSlam[] }) {
    return (
        <div className="space-y-3">
            <p>
                A Grand Slam champion has won a world title (WWE or World
                Heavyweight), a secondary title (Intercontinental or United
                States) and a tag team title (WWE or World Tag Team), on either
                brand. The Cruiserweight and Women’s titles don’t count.
            </p>
            <div className="gm-scroll max-h-[60vh] overflow-auto">
                <table className="gm-table" data-testid="grand-slam-table">
                    <thead>
                        <tr>
                            <th>Superstar</th>
                            <th>Title</th>
                            <th>First won</th>
                            <th>Date attained</th>
                        </tr>
                    </thead>
                    <tbody>
                        {slams.map((slam) =>
                            slam.titles.map((t, i) => (
                                <tr
                                    key={`${slam.holder}-${t.group}`}
                                    className={cn(
                                        i === 0 && 'border-t border-white/15',
                                    )}
                                >
                                    {i === 0 && (
                                        <th
                                            scope="row"
                                            rowSpan={slam.titles.length}
                                            className="align-top font-semibold text-ink"
                                        >
                                            <Holder
                                                name={slam.holder}
                                                parts={slam.holder_parts}
                                            />
                                        </th>
                                    )}
                                    <td>
                                        <span className="flex items-center gap-2">
                                            <TitleBeltImage
                                                name={t.championship}
                                                decorative
                                            />
                                            <span>
                                                {t.championship}
                                                <span className="block text-xs text-ink-faint">
                                                    {SLAM_GROUPS[t.group]}
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        <WeekCell
                                            season={t.season}
                                            week={t.season_week}
                                            ppv={t.won_ppv}
                                        />
                                    </td>
                                    {i === 0 && (
                                        <td
                                            rowSpan={slam.titles.length}
                                            className="align-top"
                                        >
                                            <WeekCell
                                                season={slam.attained.season}
                                                week={slam.attained.season_week}
                                                ppv={slam.attained.ppv}
                                            />
                                        </td>
                                    )}
                                </tr>
                            )),
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
