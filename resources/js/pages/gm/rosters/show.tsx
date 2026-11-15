import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { MoraleBadge, SpecialtyBadge, TitleBelt } from '@/components/gm/badges';
import { BusyLabel } from '@/components/gm/busy-label';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { EmptyState, PageTitle, Panel, Stat } from '@/components/gm/panel';
import { ReleaseButton } from '@/components/gm/release-button';
import { StreakMeter } from '@/components/gm/streak-meter';
import { formatMoney, pluralize } from '@/lib/gm';
import { useBusy } from '@/lib/use-busy';
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
        championship_key: string;
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
        label: string;
        weekly_cost: number;
        upfront_fee: number;
        /** What releasing it early cost, if it was released. */
        release_fee: number | null;
        signed_week: number;
        weeks_remaining: number;
        status: ContractStatus;
        active: boolean;
    }>;
    quotes: ContractQuote[];
    /** A month's salary and the roster type it comes from (house rule, 2026-11-15). */
    salary?: { monthly: number; tier: 'superstar' | 'diva' | 'legend' };
    /** What releasing them now costs: the weeks left on the deal. */
    release_fee?: number | null;
    /** The player's budget, to check a signing fits. */
    budget?: number;
    injury_history?: InjuryRow[];
    special_info?: Moment[];
    is_own: boolean;
    is_free_agent: boolean;
    is_pending: boolean;
};

type InjuryRow = {
    id: number;
    week: string;
    ppv: string | null;
    severity: 'minor' | 'serious';
    escalated: boolean;
    name: string;
    ended: string;
    active: boolean;
};

type Moment = {
    kind: string;
    title: string;
    detail: string;
    week: string;
    outcome: string | null;
    url: string | null;
};

const salaryTier = {
    superstar: 'Superstar',
    diva: 'Diva',
    legend: 'Legend',
} as const;

/** Short length labels for the contract buttons. */
const shortLength = (label: string) => label.replace(/ months?/, ' mo');

type InjuryFilter = 'all' | 'minor' | 'serious';

/** Every injury the superstar has had, filterable by severity. */
function InjuryHistory({ injuries }: { injuries: InjuryRow[] }) {
    const [filter, setFilter] = useState<InjuryFilter>('all');
    const counts = {
        all: injuries.length,
        minor: injuries.filter((i) => i.severity === 'minor').length,
        serious: injuries.filter((i) => i.severity === 'serious').length,
    };
    const shown =
        filter === 'all'
            ? injuries
            : injuries.filter((i) => i.severity === filter);

    return (
        <Panel
            title="Injury history"
            className="lg:col-span-2"
            bodyClassName="p-0"
            actions={
                injuries.length > 0 && (
                    <div
                        role="group"
                        aria-label="Injury severity"
                        className="flex gap-1"
                    >
                        {(['all', 'minor', 'serious'] as const).map((f) => (
                            <button
                                key={f}
                                type="button"
                                aria-pressed={filter === f}
                                onClick={() => setFilter(f)}
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    filter !== f && 'gm-btn-ghost',
                                )}
                            >
                                {f === 'all'
                                    ? 'All'
                                    : f === 'minor'
                                      ? 'Minor'
                                      : 'Serious'}{' '}
                                <span className="gm-num opacity-70">
                                    {counts[f]}
                                </span>
                            </button>
                        ))}
                    </div>
                )
            }
        >
            {shown.length === 0 ? (
                <div className="p-4">
                    <EmptyState>
                        {injuries.length === 0
                            ? 'No injuries yet.'
                            : `No ${filter} injuries.`}
                    </EmptyState>
                </div>
            ) : (
                <table className="gm-table" data-testid="injury-history">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Ended</th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((i) => (
                            <tr key={i.id}>
                                <td className="gm-num whitespace-nowrap">
                                    {i.week}
                                    {i.ppv && (
                                        <span className="block text-xs text-gold-hot">
                                            {i.ppv}
                                        </span>
                                    )}
                                </td>
                                <td>
                                    <span
                                        className={cn(
                                            'inline-block px-1.5 py-0.5 font-condensed text-xs font-semibold tracking-wider uppercase',
                                            i.severity === 'serious'
                                                ? 'bg-zone-serious/20 text-zone-serious'
                                                : 'bg-zone-minor/20 text-zone-minor',
                                        )}
                                    >
                                        {i.severity === 'serious'
                                            ? 'Serious'
                                            : 'Minor'}
                                    </span>
                                    {i.escalated &&
                                        i.severity === 'serious' && (
                                            <span className="ml-1 text-xs text-ink-dim">
                                                (from minor)
                                            </span>
                                        )}
                                </td>
                                <td>{i.name}</td>
                                <td
                                    className={cn(
                                        'whitespace-nowrap',
                                        i.active
                                            ? 'text-zone-serious'
                                            : 'text-ink-dim',
                                    )}
                                >
                                    {i.ended}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Panel>
    );
}

/** Money in the Bank, Survivor Series, WrestleMania and re-draft moments. */
function SpecialInfo({ moments }: { moments: Moment[] }) {
    return (
        <Panel title="Special info" bodyClassName="p-0">
            {moments.length === 0 ? (
                <div className="p-4">
                    <EmptyState>Nothing special yet.</EmptyState>
                </div>
            ) : (
                <ul
                    className="divide-y divide-white/5 text-sm"
                    data-testid="special-info"
                >
                    {moments.map((m, i) => (
                        <li key={i} className="px-4 py-3">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="font-semibold text-gold-hot">
                                    {m.url ? (
                                        <Link
                                            href={m.url}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {m.title}
                                        </Link>
                                    ) : (
                                        m.title
                                    )}
                                </span>
                                <span className="gm-num shrink-0 text-xs text-ink-faint">
                                    {m.week}
                                </span>
                            </div>
                            <p className="mt-0.5 text-ink-dim">
                                {m.detail}
                                {m.outcome && (
                                    <span
                                        className={cn(
                                            'ml-1 font-semibold',
                                            m.outcome.startsWith('Won')
                                                ? 'text-zone-safe'
                                                : 'text-zone-serious',
                                        )}
                                    >
                                        {m.outcome}
                                    </span>
                                )}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** A labelled cell in the Status grid. */
function StatusField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <div className="gm-label mb-2">{label}</div>
            {children}
        </div>
    );
}

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
    const { busy, track } = useBusy();
    const props = usePage<Props>().props;
    const budget = props.budget ?? game.budget;
    // A signing needs the whole contract's value in the bank.
    const affordable = quote === undefined || budget >= quote.total;

    return (
        <>
            <Head title={wrestler.name} />
            <PageTitle
                kicker={RosterTypeLabels[wrestler.roster_type]}
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

            {wrestler.titles.length > 0 && (
                <div className="mb-6 flex flex-wrap gap-2">
                    {wrestler.titles.map((t) => (
                        <TitleBelt key={t} name={t} />
                    ))}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3 lg:items-start">
                <Panel title="Status" className="lg:col-span-2">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        <StatusField label="Morale">
                            <MoraleBadge morale={wrestler.morale} />
                        </StatusField>
                        <Stat
                            label="Record (W-L)"
                            value={`${wrestler.wins}–${wrestler.losses}`}
                        />
                        <StatusField label="Streak">
                            <StreakMeter
                                wins={wrestler.win_streak}
                                losses={wrestler.loss_streak}
                            />
                        </StatusField>
                        <StatusField label="Weight class">
                            <span className="gm-num text-lg">
                                {WeightClassLabels[wrestler.weight_class]}
                            </span>
                        </StatusField>
                        {props.salary && (
                            <StatusField label="Salary">
                                <span className="gm-num text-lg">
                                    {formatMoney(props.salary.monthly)}
                                </span>
                                <span className="block text-xs text-ink-dim">
                                    a month · {salaryTier[props.salary.tier]}{' '}
                                    rate
                                </span>
                            </StatusField>
                        )}
                        <StatusField label="Fatigue">
                            <FatigueMeter
                                fatigue={wrestler.fatigue}
                                config={game.fatigue_config}
                                size="md"
                                showLabel
                            />
                        </StatusField>
                        <StatusField label="Specialties">
                            {wrestler.specialties.length > 0 ? (
                                <div className="flex flex-wrap gap-1.5">
                                    {wrestler.specialties.map((s) => (
                                        <SpecialtyBadge key={s} family={s} />
                                    ))}
                                </div>
                            ) : (
                                <span className="text-ink-faint">—</span>
                            )}
                        </StatusField>
                        {/* Popularity shows once the system exists. */}
                        {wrestler.popularity != null && (
                            <Stat
                                label="Popularity"
                                value={wrestler.popularity}
                            />
                        )}
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
                            {is_own && props.release_fee != null && (
                                <div className="col-span-2 text-sm text-ink-dim">
                                    Releasing now costs{' '}
                                    <span className="gm-num text-ink">
                                        {formatMoney(props.release_fee)}
                                    </span>{' '}
                                    (
                                    {pluralize(
                                        wrestler.contract.weeks_remaining,
                                        'week',
                                    )}{' '}
                                    left).
                                </div>
                            )}
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
                                        {shortLength(q.label)}
                                    </button>
                                ))}
                            </div>
                            {quote && (
                                <dl className="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                                    <dt className="text-ink-dim">Per week</dt>
                                    <dd className="gm-num text-right">
                                        {formatMoney(quote.weekly)}
                                    </dd>
                                    <dt className="text-ink-dim">
                                        Contract total ({quote.label})
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
                                    disabled={busy !== null || !affordable}
                                    aria-busy={busy !== null || undefined}
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
                                            {
                                                preserveScroll: true,
                                                ...track('sign'),
                                            },
                                        )
                                    }
                                >
                                    <BusyLabel
                                        busy={busy !== null}
                                        busyText={
                                            is_own ? 'Re-signing…' : 'Signing…'
                                        }
                                    >
                                        {is_own ? 'Re-sign' : 'Sign'}
                                    </BusyLabel>
                                </button>
                                {is_own && (
                                    <ReleaseButton
                                        saveId={game.id}
                                        wrestlerId={wrestler.id}
                                        name={wrestler.name}
                                        fee={props.release_fee}
                                    />
                                )}
                            </div>
                            {!affordable && quote && (
                                <p className="mt-2 text-sm text-zone-serious">
                                    You need {formatMoney(quote.total)} in the
                                    bank to sign this deal.
                                </p>
                            )}
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
                                            <Link
                                                href={gm.titles.history.url(
                                                    game.id,
                                                    {
                                                        query: {
                                                            title: r.championship_key,
                                                        },
                                                    },
                                                )}
                                                className="underline-offset-4 hover:text-brand-hot hover:underline"
                                            >
                                                {r.championship}
                                            </Link>
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
                                    {c.label ??
                                        pluralize(c.length_weeks, 'week')}{' '}
                                    · signed W{c.signed_week}
                                    {c.upfront_fee > 0 && (
                                        <span className="block text-xs text-ink-faint">
                                            +{formatMoney(c.upfront_fee)} up
                                            front
                                        </span>
                                    )}
                                    {c.release_fee != null && (
                                        <span className="block text-xs text-zone-serious">
                                            Release fee{' '}
                                            {formatMoney(c.release_fee)}
                                        </span>
                                    )}
                                </span>
                                <span className="gm-num text-right">
                                    {formatMoney(c.weekly_cost)}/wk · {c.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <InjuryHistory injuries={props.injury_history ?? []} />

                <SpecialInfo moments={props.special_info ?? []} />
            </div>
        </>
    );
}
