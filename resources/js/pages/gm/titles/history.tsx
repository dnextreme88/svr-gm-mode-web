import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type {
    Brand,
    ReignEndReason,
    TitleHolderType,
    WonVia,
} from '@/types/enums';
import { ReignEndReasonLabels, WonViaLabels } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type LineageRow = {
    id: number;
    reign_number: number | null;
    holder: string;
    holder_type: TitleHolderType;
    won_week: number;
    won_season: number;
    won_season_week: number;
    won_show: string | null;
    won_match_type: string | null;
    won_via: WonVia | null;
    lost_week: number | null;
    lost_season_week: number | null;
    weeks_held: number;
    defenses: number;
    end_reason: ReignEndReason;
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
                                aria-label={`${brand === 'raw' ? 'Raw' : 'SmackDown!'} titles`}
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
                                                <th>Event</th>
                                                <th>How</th>
                                                <th>Lost</th>
                                                <th>Weeks</th>
                                                <th>Def.</th>
                                                <th>Ended</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {title.lineage.map((r) => (
                                                <tr
                                                    key={r.id}
                                                    className={cn(
                                                        r.holder_type ===
                                                            'vacant' &&
                                                            'text-ink-faint italic',
                                                    )}
                                                >
                                                    <td className="gm-num">
                                                        {r.reign_number ?? '—'}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'font-semibold',
                                                            r.active &&
                                                                r.holder_type !==
                                                                    'vacant' &&
                                                                'text-gold-hot',
                                                        )}
                                                    >
                                                        {r.holder}
                                                    </td>
                                                    <td className="gm-num whitespace-nowrap">
                                                        S{r.won_season} W
                                                        {r.won_season_week}
                                                    </td>
                                                    <td className="text-ink-dim">
                                                        {r.won_show ?? '—'}
                                                    </td>
                                                    <td className="text-ink-dim">
                                                        {r.won_via
                                                            ? WonViaLabels[
                                                                  r.won_via
                                                              ]
                                                            : '—'}
                                                        {r.won_match_type && (
                                                            <div className="text-xs">
                                                                {
                                                                    r.won_match_type
                                                                }
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="gm-num">
                                                        {r.lost_season_week
                                                            ? `W${r.lost_season_week}`
                                                            : '—'}
                                                    </td>
                                                    <td className="gm-num">
                                                        {r.weeks_held}
                                                    </td>
                                                    <td className="gm-num">
                                                        {r.holder_type ===
                                                        'vacant'
                                                            ? '—'
                                                            : r.defenses}
                                                    </td>
                                                    <td className="text-ink-dim">
                                                        {r.active &&
                                                        r.holder_type !==
                                                            'vacant'
                                                            ? 'Current'
                                                            : ReignEndReasonLabels[
                                                                  r.end_reason
                                                              ]}
                                                    </td>
                                                </tr>
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
