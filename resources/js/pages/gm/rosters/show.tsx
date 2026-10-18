import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    InjuryChip,
    MoraleBadge,
    SpecialtyBadge,
    TitleBelt,
} from '@/components/gm/badges';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { EmptyState, PageTitle, Panel, Stat } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type {
    ComplaintLevel,
    ContractStatus,
    MoraleComplaintType,
    ReignEndReason,
} from '@/types/enums';
import {
    ComplaintLevelLabels,
    MoraleComplaintTypeLabels,
    ReignEndReasonLabels,
    RosterTypeLabels,
    WeightClassLabels,
} from '@/types/enums';
import type { ContractQuote, GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    wrestler: WrestlerRow;
    complaints: Array<{
        type: MoraleComplaintType;
        level: ComplaintLevel;
        opened_week: number;
    }>;
    title_history: Array<{
        championship: string;
        reign_number: number | null;
        won_week: number;
        lost_week: number | null;
        weeks_held: number;
        defenses: number;
        end_reason: ReignEndReason;
        holders: string;
    }>;
    contracts: Array<{
        length_weeks: number;
        weekly_cost: number;
        upfront_fee: number;
        signed_week: number;
        weeks_remaining: number;
        status: ContractStatus;
        active: boolean;
    }>;
    quotes: ContractQuote[];
    is_own: boolean;
    is_free_agent: boolean;
    is_pending: boolean;
};

export default function WrestlerDetail() {
    const {
        game,
        wrestler,
        complaints,
        title_history,
        contracts,
        quotes,
        is_own,
        is_free_agent,
        is_pending,
    } = usePage<Props>().props;
    const [length, setLength] = useState<number>(
        wrestler.contract?.length_weeks ?? 52,
    );
    const quote = quotes.find((q) => q.length_weeks === length);

    return (
        <>
            <Head title={wrestler.name} />
            <PageTitle
                kicker={`${RosterTypeLabels[wrestler.roster_type]} · ${WeightClassLabels[wrestler.weight_class]}`}
                title={wrestler.name}
            >
                <Link
                    href={gm.rosters.index.url(game.id, {
                        query: { brand: wrestler.brand ?? game.player_brand },
                    })}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    Back to roster
                </Link>
            </PageTitle>

            <div className="mb-6 flex flex-wrap gap-2">
                {wrestler.titles.map((t) => (
                    <TitleBelt key={t} name={t} />
                ))}
                {wrestler.specialties.map((s) => (
                    <SpecialtyBadge key={s} family={s} />
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <Panel title="Status" className="lg:col-span-2">
                    <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                        <Stat
                            label="Record"
                            value={`${wrestler.wins}–${wrestler.losses}`}
                        />
                        <Stat
                            label="Streak"
                            value={
                                wrestler.win_streak > 0
                                    ? `W${wrestler.win_streak}`
                                    : wrestler.loss_streak > 0
                                      ? `L${wrestler.loss_streak}`
                                      : '—'
                            }
                            tone={
                                wrestler.win_streak > 0
                                    ? 'good'
                                    : wrestler.loss_streak > 0
                                      ? 'bad'
                                      : undefined
                            }
                        />
                        <div>
                            <div className="gm-label">Morale</div>
                            <div className="mt-1.5">
                                <MoraleBadge morale={wrestler.morale} />
                            </div>
                        </div>
                        <Stat
                            label="Popularity"
                            value={wrestler.popularity ?? '—'}
                        />
                    </div>
                    <div className="mt-6 grid gap-6 md:grid-cols-2">
                        <div>
                            <div className="gm-label mb-2">Fatigue</div>
                            <FatigueMeter
                                fatigue={wrestler.fatigue}
                                config={game.fatigue_config}
                                size="md"
                                showLabel
                            />
                        </div>
                        <div>
                            <div className="gm-label mb-2">Injury</div>
                            <InjuryChip injury={wrestler.injury} />
                        </div>
                    </div>
                    {complaints.length > 0 && (
                        <div className="mt-6">
                            <div className="gm-label mb-2">
                                Active complaints
                            </div>
                            <ul className="space-y-1.5 text-sm">
                                {complaints.map((c) => (
                                    <li
                                        key={c.type}
                                        className="flex items-center justify-between bg-white/[0.03] px-3 py-1.5"
                                    >
                                        <span>
                                            {MoraleComplaintTypeLabels[c.type]}
                                        </span>
                                        <span className="font-condensed text-xs tracking-wider text-ink-dim uppercase">
                                            {ComplaintLevelLabels[c.level]} ·
                                            since W{c.opened_week}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Panel>

                <Panel title="Contract">
                    {wrestler.contract ? (
                        <div className="mb-4 grid grid-cols-2 gap-4">
                            <Stat
                                label="Weeks left"
                                value={wrestler.contract.weeks_remaining}
                                tone={
                                    wrestler.contract.weeks_remaining <= 4
                                        ? 'bad'
                                        : undefined
                                }
                            />
                            <Stat
                                label="Weekly"
                                value={formatMoney(
                                    wrestler.contract.weekly_cost,
                                )}
                            />
                        </div>
                    ) : (
                        <p className="mb-4 text-sm text-ink-dim">
                            {is_pending
                                ? 'Signed. Joins the roster next week.'
                                : is_free_agent
                                  ? 'Free agent.'
                                  : 'No active contract.'}
                        </p>
                    )}

                    {(is_own || (is_free_agent && !is_pending)) && (
                        <>
                            <div className="gm-label mb-2">
                                {is_own ? 'Renegotiate' : 'Offer a contract'}
                            </div>
                            <div className="grid grid-cols-5 gap-1">
                                {quotes.map((q) => (
                                    <button
                                        key={q.length_weeks}
                                        type="button"
                                        aria-pressed={length === q.length_weeks}
                                        onClick={() =>
                                            setLength(q.length_weeks)
                                        }
                                        className={cn(
                                            'px-1 py-2 font-condensed text-xs font-semibold tracking-wide uppercase',
                                            length === q.length_weeks
                                                ? 'bg-brand text-white'
                                                : 'bg-white/[0.05] text-ink-dim hover:text-ink',
                                        )}
                                    >
                                        {q.length_weeks}w
                                    </button>
                                ))}
                            </div>
                            {quote && (
                                <dl className="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                                    <dt className="text-ink-dim">Up front</dt>
                                    <dd className="gm-num text-right">
                                        {formatMoney(quote.upfront)}
                                    </dd>
                                    <dt className="text-ink-dim">Per week</dt>
                                    <dd className="gm-num text-right">
                                        {formatMoney(quote.weekly)}
                                    </dd>
                                    <dt className="text-ink-dim">
                                        Total over {quote.label}
                                    </dt>
                                    <dd className="gm-num text-right">
                                        {formatMoney(quote.total)}
                                    </dd>
                                </dl>
                            )}
                            <div className="mt-4 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-sm"
                                    onClick={() =>
                                        router.post(
                                            is_own
                                                ? gm.contracts.renegotiate.url({
                                                      save: game.id,
                                                      saveWrestler: wrestler.id,
                                                  })
                                                : gm.freeAgency.sign.url({
                                                      save: game.id,
                                                      saveWrestler: wrestler.id,
                                                  }),
                                            { length_weeks: length },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {is_own ? 'Re-sign' : 'Sign'}
                                </button>
                                {is_own && (
                                    <button
                                        type="button"
                                        className="gm-btn gm-btn-ghost gm-btn-sm"
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Release ${wrestler.name}? Any title they hold will be vacated.`,
                                                )
                                            ) {
                                                router.post(
                                                    gm.contracts.release.url({
                                                        save: game.id,
                                                        saveWrestler:
                                                            wrestler.id,
                                                    }),
                                                );
                                            }
                                        }}
                                    >
                                        Release
                                    </button>
                                )}
                            </div>
                        </>
                    )}
                </Panel>

                <Panel
                    title="Title history"
                    className="lg:col-span-2"
                    bodyClassName="p-0"
                >
                    {title_history.length === 0 ? (
                        <div className="p-4">
                            <EmptyState>No title reigns yet.</EmptyState>
                        </div>
                    ) : (
                        <table className="gm-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Reign</th>
                                    <th>Won</th>
                                    <th>Lost</th>
                                    <th>Weeks</th>
                                    <th>Defenses</th>
                                    <th>Ended</th>
                                </tr>
                            </thead>
                            <tbody>
                                {title_history.map((r, i) => (
                                    <tr key={i}>
                                        <td className="font-semibold">
                                            {r.championship}
                                            {r.holders !== wrestler.name && (
                                                <div className="text-xs text-ink-faint">
                                                    {r.holders}
                                                </div>
                                            )}
                                        </td>
                                        <td className="gm-num">
                                            #{r.reign_number}
                                        </td>
                                        <td className="gm-num">
                                            W{r.won_week}
                                        </td>
                                        <td className="gm-num">
                                            {r.lost_week
                                                ? `W${r.lost_week}`
                                                : '—'}
                                        </td>
                                        <td className="gm-num">
                                            {r.weeks_held}
                                        </td>
                                        <td className="gm-num">{r.defenses}</td>
                                        <td className="text-ink-dim">
                                            {ReignEndReasonLabels[r.end_reason]}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Panel>

                <Panel title="Contract history" bodyClassName="p-0">
                    <ul className="divide-y divide-white/5 text-sm">
                        {contracts.map((c, i) => (
                            <li
                                key={i}
                                className={cn(
                                    'flex justify-between px-4 py-2',
                                    !c.active && 'text-ink-dim',
                                )}
                            >
                                <span>
                                    {c.length_weeks}w · W{c.signed_week}
                                </span>
                                <span className="gm-num">
                                    {formatMoney(c.weekly_cost)}/wk · {c.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>
        </>
    );
}
