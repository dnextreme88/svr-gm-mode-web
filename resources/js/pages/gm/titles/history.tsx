import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { MouseEvent } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import type { Named } from '@/components/gm/wrestler-name';
import { WrestlerNames } from '@/components/gm/wrestler-name';
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
};

type RecordSet = {
    longest: (HolderRecord & { championship: string }) | null;
    shortest: (HolderRecord & { championship: string }) | null;
    most_reigns: HolderRecord | null;
    most_defenses: HolderRecord | null;
};

type Props = GamePageProps & {
    titles: TitleRow[];
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
function Holder({ name, parts }: { name: string; parts?: NamePart[] | null }) {
    return parts && parts.length > 0 ? <WrestlerNames names={parts} /> : name;
}

export default function TitleHistory() {
    const page = usePage<Props>();
    const { game, titles, hall, records, briefcases } = page.props;
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
            document
                .getElementById('lineage')
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
                            <ul className="mt-3 grid grid-cols-2 gap-3">
                                {(hall[brand] ?? []).map((h) => (
                                    <li key={h.name} className="min-w-0">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                h.id !== undefined &&
                                                select(h.id, true)
                                            }
                                            aria-label={`${h.name}: ${h.holder ?? 'Vacant'}. Show lineage`}
                                            className="gm-row-link block h-full w-full border-l-2 border-gold/60 bg-white/[0.03] px-3 py-2 text-left transition-colors hover:bg-white/[0.07]"
                                        >
                                            <span className="block font-condensed text-sm tracking-[0.04em] break-words text-gold uppercase sm:text-xl sm:tracking-[0.14em]">
                                                {h.name.replace(
                                                    ' Championship',
                                                    '',
                                                )}
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

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
                <nav
                    aria-label="Championships"
                    className="gm-panel gm-scroll flex min-w-0 items-center gap-1 self-start overflow-x-auto p-1 lg:block lg:overflow-visible"
                >
                    {(['raw', 'smackdown'] as const).map((brand, i) => (
                        <Fragment key={brand}>
                            {i > 0 && (
                                <hr
                                    className="mx-1 h-8 shrink-0 border-0 border-l border-white/15 lg:mx-0 lg:my-2 lg:h-0 lg:border-t lg:border-l-0"
                                    data-testid="brand-divider"
                                />
                            )}
                            <div
                                role="group"
                                aria-label={`${brand === 'raw' ? 'Raw' : 'Smackdown'} titles`}
                                data-brand-tint={brand}
                                className="flex shrink-0 items-center gap-1 lg:block"
                            >
                                <div className="px-2 lg:px-3 lg:pt-2 lg:pb-1.5">
                                    <BrandBug brand={brand} size="sm" />
                                </div>
                                {titles
                                    .filter((t) => t.brand === brand)
                                    .map((t) => (
                                        <button
                                            key={t.id}
                                            type="button"
                                            onClick={() => select(t.id)}
                                            aria-pressed={t.id === title?.id}
                                            className={cn(
                                                'gm-row-link block min-h-11 shrink-0 px-3 py-2 text-left text-sm whitespace-nowrap transition-colors lg:min-h-0 lg:w-full lg:whitespace-normal',
                                                t.id === title?.id
                                                    ? 'bg-brand/15 text-ink shadow-[inset_0_-3px_0_var(--gm-brand)] lg:shadow-[inset_3px_0_0_var(--gm-brand)]'
                                                    : 'text-ink-dim hover:text-ink',
                                            )}
                                        >
                                            {t.name}
                                        </button>
                                    ))}
                            </div>
                        </Fragment>
                    ))}
                </nav>

                {title && (
                    <div id="lineage" className="min-w-0 scroll-mt-4 space-y-6">
                        <Panel
                            title={`${title.name} lineage`}
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
                            />
                            <RecordsPanel
                                title="All titles"
                                records={records.overall}
                            />
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function WonCell({
    season,
    week,
    ppv,
}: {
    season: number;
    week: number;
    ppv: string | null;
}) {
    return (
        <>
            <span className="gm-num whitespace-nowrap">
                S{season} W{week}
            </span>
            {ppv && <div className="text-xs text-gold">{ppv}</div>}
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
                            <WonCell
                                season={r.won_season}
                                week={r.won_season_week}
                                ppv={r.won_ppv}
                            />
                        </Link>
                    ) : (
                        <WonCell
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
                        <WonCell
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

function RecordsPanel({
    title,
    records,
}: {
    title: string;
    records: RecordSet | undefined;
}) {
    const rows: Array<[string, HolderRecord | null | undefined, string]> = [
        ['Longest reign', records?.longest, 'week'],
        ['Most reigns', records?.most_reigns, 'reign'],
        ['Most defenses', records?.most_defenses, 'defense'],
        ['Shortest reign', records?.shortest, 'week'],
    ];

    return (
        <Panel title={title}>
            <dl className="space-y-2 text-sm">
                {rows.map(([label, record, unit]) => (
                    <div key={label} className="flex justify-between gap-3">
                        <dt className="text-ink-dim">{label}</dt>
                        <dd className="text-right font-semibold">
                            {record ? (
                                <>
                                    <Holder
                                        name={record.holder}
                                        parts={record.holder_parts}
                                    />
                                    <span className="ml-2 font-normal text-ink-dim">
                                        {plural(record.value, unit)}
                                    </span>
                                </>
                            ) : (
                                '—'
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </Panel>
    );
}
