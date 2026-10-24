import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, ReignEndReason, TitleHolderType } from '@/types/enums';
import { ReignEndReasonLabels } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type ReignMatch = {
    id: number;
    /** The match the title was won in, or a defense. */
    kind: 'won' | 'defense';
    /** Defenses: 1 for the first defense of the reign. */
    number: number | null;
    opponents: string;
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

type RecordSet = {
    longest: { holder: string; championship: string; value: number } | null;
    shortest: { holder: string; championship: string; value: number } | null;
    most_reigns: { holder: string; value: number } | null;
    most_defenses: { holder: string; value: number } | null;
};

type Props = GamePageProps & {
    titles: TitleRow[];
    hall: Record<
        Brand,
        Array<{
            name: string;
            holder: string | null;
            weeks_held: number | null;
            defenses: number | null;
        }>
    >;
    records: { overall: RecordSet; per_title: Record<string, RecordSet> };
    briefcases: Array<{ holder: string; brand: Brand; expires_week: number }>;
};

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
                                    <li
                                        key={h.name}
                                        className="border-l-2 border-gold/60 bg-white/[0.03] px-3 py-2"
                                    >
                                        <div className="font-condensed text-xs tracking-[0.14em] text-gold uppercase">
                                            {h.name.replace(
                                                ' Championship',
                                                '',
                                            )}
                                        </div>
                                        <div className="gm-heading mt-0.5 text-lg">
                                            {h.holder ?? (
                                                <span className="text-ink-faint">
                                                    Vacant
                                                </span>
                                            )}
                                        </div>
                                        {h.holder && (
                                            <div className="text-xs text-ink-dim">
                                                {h.weeks_held} wk · {h.defenses}{' '}
                                                def.
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
                {briefcases.length > 0 && (
                    <p className="mt-3 text-sm text-ink-dim">
                        Money in the Bank:{' '}
                        {briefcases
                            .map(
                                (b) => `${b.holder} (until W${b.expires_week})`,
                            )
                            .join(', ')}
                    </p>
                )}
            </section>

            <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
                <nav
                    aria-label="Championships"
                    className="gm-panel self-start p-1"
                >
                    {(['raw', 'smackdown'] as const).map((brand, i) => (
                        <Fragment key={brand}>
                            {i > 0 && (
                                <hr
                                    className="my-2 border-white/15"
                                    data-testid="brand-divider"
                                />
                            )}
                            <div
                                role="group"
                                aria-label={`${brand === 'raw' ? 'Raw' : 'Smackdown'} titles`}
                                data-brand-tint={brand}
                            >
                                <div className="px-3 pt-2 pb-1.5">
                                    <BrandBug brand={brand} size="sm" />
                                </div>
                                {titles
                                    .filter((t) => t.brand === brand)
                                    .map((t) => (
                                        <button
                                            key={t.id}
                                            type="button"
                                            onClick={() => setSelected(t.id)}
                                            aria-pressed={t.id === title?.id}
                                            className={cn(
                                                'block w-full px-3 py-2 text-left text-sm transition-colors',
                                                t.id === title?.id
                                                    ? 'bg-brand/15 text-ink shadow-[inset_3px_0_0_var(--gm-brand)]'
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
                    <div className="space-y-6">
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
                                                <th>#</th>
                                                <th>Holder</th>
                                                <th>Won</th>
                                                <th>Reign</th>
                                                <th>How</th>
                                                <th>Weeks</th>
                                                <th>Defenses</th>
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

    return (
        <>
            <tr
                data-testid={`reign-${r.id}`}
                className={cn(vacant && 'text-ink-faint italic')}
            >
                <td className="gm-num">{r.reign_number ?? '—'}</td>
                <td
                    className={cn(
                        'font-semibold',
                        r.active && !vacant && 'text-gold-hot',
                    )}
                >
                    {r.holder}
                </td>
                <td>
                    {r.won_link ? (
                        <Link
                            href={r.won_link}
                            className="hover:text-brand-hot"
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
                <td className="gm-num">{r.holder_reign ?? '—'}</td>
                <td className="text-ink-dim">
                    {vacant
                        ? r.vacated_reason
                            ? `Vacated: ${ReignEndReasonLabels[r.vacated_reason]}`
                            : 'Vacant'
                        : (r.how ?? '—')}
                </td>
                <td className="gm-num">{r.weeks_held}</td>
                <td className="gm-num">{vacant ? '—' : r.defenses}</td>
            </tr>
            {r.matches.map((d) => (
                <tr
                    key={d.id}
                    data-testid={`${d.kind}-${d.id}`}
                    tabIndex={0}
                    aria-label={
                        d.kind === 'won'
                            ? `Open the title win against ${d.opponents}`
                            : `Open defense ${d.number} against ${d.opponents}`
                    }
                    className="group cursor-pointer text-sm transition-colors hover:bg-white/[0.04] focus-visible:bg-white/[0.06] focus-visible:outline-none"
                    onClick={() => router.visit(d.link)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            router.visit(d.link);
                        }
                    }}
                >
                    <td />
                    <td className="pl-6 font-semibold group-hover:text-brand-hot">
                        vs. {d.opponents}
                    </td>
                    <td>
                        <WonCell
                            season={d.season}
                            week={d.season_week}
                            ppv={d.ppv}
                        />
                    </td>
                    <td className="text-ink-faint">—</td>
                    <td className="text-ink-dim">{d.match_type ?? '—'}</td>
                    <td className="text-ink-faint">—</td>
                    <td className="gm-num">
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
    const rows: Array<[string, string | null]> = [
        [
            'Longest reign',
            records?.longest
                ? `${records.longest.holder} · ${records.longest.value} wk`
                : null,
        ],
        [
            'Most reigns',
            records?.most_reigns
                ? `${records.most_reigns.holder} · ${records.most_reigns.value}`
                : null,
        ],
        [
            'Most defenses',
            records?.most_defenses
                ? `${records.most_defenses.holder} · ${records.most_defenses.value}`
                : null,
        ],
        [
            'Shortest reign',
            records?.shortest
                ? `${records.shortest.holder} · ${records.shortest.value} wk`
                : null,
        ],
    ];

    return (
        <Panel title={title}>
            <dl className="space-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-3">
                        <dt className="text-ink-dim">{label}</dt>
                        <dd className="text-right font-semibold">
                            {value ?? '—'}
                        </dd>
                    </div>
                ))}
            </dl>
        </Panel>
    );
}
