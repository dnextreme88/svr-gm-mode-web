import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId, useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { BusyLabel } from '@/components/gm/busy-label';
import { DisclosureChevron, DisclosurePanel } from '@/components/gm/disclosure';
import { FinanceTrend } from '@/components/gm/finance-trend';
import type { TrendWeek } from '@/components/gm/finance-trend';
import { EmptyState, PageTitle, Panel, Stat } from '@/components/gm/panel';
import { brandName, formatMoney, formatSignedMoney, pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type Line = {
    category: string;
    /** Lines with the same group add up to one row ("promos" for every promo line). */
    group: string;
    label: string;
    memo: string;
    amount: number;
};

type Group = {
    key: string;
    name: string;
    ppv: boolean;
    crowd: number | null;
    lines: Line[];
    total: number;
};

type Statement = {
    week: number;
    season: number;
    season_week: number;
    /** The week's nights: "Backlash + Friday Night Smackdown". */
    title: string;
    groups: Group[];
    totals: Array<{ group: string; label: string; amount: number }>;
    /** The opening balance granted that week; not counted as income. */
    starting_budget?: number;
    income: number;
    expenses: number;
    net: number;
};

type HistoryRow = TrendWeek & { season: number };

type Props = GamePageProps & {
    /** Whose books these are (house rule, 2026-11-13); the rival's are read-only. */
    brand?: Brand;
    /** That brand's balance. */
    balance?: number;
    statement: Statement;
    history: HistoryRow[];
    deficit_choices: string[];
    /** The week just aired, reached from its results (2026-11-17). */
    wrap_up?: boolean;
};

const choiceCopy: Record<string, { title: string; body: string }> = {
    promos: {
        title: 'Run money promos',
        body: 'Lean on Advertising and Merchandise Sponsorship promos.',
    },
    cheap_matches: {
        title: 'Downgrade match types',
        body: 'Book only free match types until the budget recovers.',
    },
    release: {
        title: 'Cut payroll',
        body: 'Release roster members to lower weekly salaries.',
    },
};

/** A night's lines, one row per group (every promo line adds up to "Promos"). */
function rowsOf(lines: Line[]) {
    const rows: Array<{
        group: string;
        label: string;
        lines: Line[];
        total: number;
    }> = [];

    for (const line of lines) {
        let row = rows.find((r) => r.group === line.group);

        if (!row) {
            row = { group: line.group, label: line.label, lines: [], total: 0 };
            rows.push(row);
        }

        row.lines.push(line);
        row.total += line.amount;
    }

    return rows;
}

/** Shades for the income and cost bars, biggest share first. */
const INCOME_SHADES = [
    'bg-zone-safe',
    'bg-zone-safe/75',
    'bg-zone-safe/55',
    'bg-zone-safe/40',
    'bg-zone-safe/28',
];
const COST_SHADES = [
    'bg-zone-serious',
    'bg-zone-serious/75',
    'bg-zone-serious/55',
    'bg-zone-serious/40',
    'bg-zone-serious/28',
    'bg-zone-serious/20',
];

/** Where the week's money came from and went, as two stacked bars. */
function SplitBars({ statement }: { statement: Statement }) {
    const income = statement.totals
        .filter((t) => t.amount > 0)
        .sort((a, b) => b.amount - a.amount);
    const costs = statement.totals
        .filter((t) => t.amount < 0)
        .sort((a, b) => a.amount - b.amount);
    const scale = Math.max(1, statement.income, Math.abs(statement.expenses));

    const bar = (
        label: string,
        items: typeof income,
        total: number,
        shades: string[],
    ) => (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between text-sm">
                <span className="gm-label">{label}</span>
                <span className="gm-num">{formatMoney(Math.abs(total))}</span>
            </div>
            <div
                className="flex h-4 gap-0.5"
                style={{ width: `${(Math.abs(total) / scale) * 100}%` }}
                aria-hidden
            >
                {items.map((t, i) => (
                    <span
                        key={t.group}
                        className={cn(
                            'h-full min-w-1',
                            shades[Math.min(i, shades.length - 1)],
                        )}
                        style={{ flexGrow: Math.abs(t.amount) }}
                        title={`${t.label}: ${formatMoney(Math.abs(t.amount))}`}
                    />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-dim">
                {items.map((t, i) => (
                    <li
                        key={t.group}
                        className="inline-flex items-center gap-1.5"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'size-2.5',
                                shades[Math.min(i, shades.length - 1)],
                            )}
                        />
                        {t.label}{' '}
                        <span className="gm-num text-ink">
                            {formatMoney(Math.abs(t.amount))}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );

    return (
        <div className="space-y-4" data-testid="split-bars">
            {bar('Income', income, statement.income, INCOME_SHADES)}
            {bar('Costs', costs, statement.expenses, COST_SHADES)}
        </div>
    );
}

/** One row of a night's statement; several lines fold open beneath it. */
function StatementRow({ row }: { row: ReturnType<typeof rowsOf>[number] }) {
    const [open, setOpen] = useState(false);
    const id = useId();
    const many = row.lines.length > 1;
    const amount = (n: number, extra?: string) => (
        <span
            className={cn(
                'gm-num text-right',
                n >= 0 ? 'text-zone-safe' : 'text-zone-serious',
                extra,
            )}
        >
            {formatSignedMoney(n)}
        </span>
    );

    if (!many) {
        return (
            <li className="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                <span>
                    <span className="font-semibold">{row.label}</span>
                    <span className="block text-xs text-ink-dim">
                        {row.lines[0].memo}
                    </span>
                </span>
                {amount(row.total)}
            </li>
        );
    }

    return (
        <li className="text-sm" data-testid={`statement-${row.group}`}>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen(!open)}
                className="flex min-h-11 w-full items-center justify-between gap-3 px-4 py-2 text-left hover:bg-white/[0.03] sm:min-h-0"
            >
                <span className="inline-flex items-center gap-1.5 font-semibold">
                    <DisclosureChevron open={open} />
                    {row.label}
                    <span className="font-normal text-ink-dim">
                        ({row.lines.length})
                    </span>
                </span>
                {amount(row.total, 'font-semibold')}
            </button>
            <DisclosurePanel open={open} id={id}>
                <ul className="pb-2">
                    {row.lines.map((line, i) => (
                        <li
                            key={i}
                            className="flex items-baseline justify-between gap-3 py-1 pr-4 pl-10 text-ink-dim"
                        >
                            <span>{line.memo}</span>
                            {amount(line.amount, 'text-sm')}
                        </li>
                    ))}
                </ul>
            </DisclosurePanel>
        </li>
    );
}

export default function Financials() {
    const { game, statement, history, deficit_choices } =
        usePage<Props>().props;
    const props = usePage<Props>().props;
    const brand = props.brand ?? game.player_brand;
    const mine = brand === game.player_brand;
    const balance = props.balance ?? game.budget;
    const [choosing, setChoosing] = useState<string | null>(null);

    const seasons = [...new Set(history.map((h) => h.season))].sort(
        (a, b) => b - a,
    );
    const season = statement.season;
    const seasonWeeks = history.filter((h) => h.season === season);
    const index = history.findIndex((h) => h.week_number === statement.week);
    const previous = index > 0 ? history[index - 1] : null;
    const next =
        index >= 0 && index < history.length - 1 ? history[index + 1] : null;
    const latest = history[history.length - 1];
    const closing = history[index]?.balance ?? balance;
    const wrapUp = props.wrap_up === true;

    /** The page for a week of a brand; switching brand keeps the week. */
    const href = (query: { brand?: Brand; week?: number; season?: number }) =>
        gm.financials.url(game.id, {
            query: {
                ...((query.brand ?? brand) !== game.player_brand
                    ? { brand: query.brand ?? brand }
                    : {}),
                ...(query.week !== undefined ? { week: query.week } : {}),
                ...(query.season !== undefined ? { season: query.season } : {}),
            },
        });
    const go = (week: number) =>
        router.get(
            href({ week }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title="Financials" />
            <PageTitle kicker="Income statement" title="Financials">
                {props.wrap_up ? (
                    <Link
                        href={gm.news.url(game.id, { query: { wrap: 1 } })}
                        className="gm-btn"
                    >
                        Continue to News
                    </Link>
                ) : (
                    <Link href={gm.schedule.url(game.id)} className="gm-btn">
                        Back to Schedule
                    </Link>
                )}
            </PageTitle>

            {/* Either brand's books, any week; the rival's are read-only. */}
            <div className="-mt-2 mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
                <div role="group" aria-label="Brand" className="flex gap-1">
                    {(['raw', 'smackdown'] as const).map((b) => (
                        <Link
                            key={b}
                            href={href({ brand: b, week: statement.week })}
                            preserveScroll
                            aria-current={brand === b ? 'page' : undefined}
                            aria-label={`${brandName(b)} ${b === game.player_brand ? '(you)' : '(rival)'}`}
                            className={cn(
                                'inline-flex min-h-11 items-center gap-2 px-3 py-1 font-condensed text-xs font-semibold tracking-wider uppercase',
                                brand === b
                                    ? 'bg-white/[0.08] text-ink shadow-[inset_0_-2px_0_var(--color-brand)]'
                                    : 'text-ink-dim opacity-70 hover:opacity-100',
                            )}
                            data-brand-tint={b}
                        >
                            <BrandBug brand={b} size="sm" />
                            {b === game.player_brand ? 'You' : 'Rival'}
                        </Link>
                    ))}
                </div>

                {seasons.length > 1 && (
                    <div
                        role="group"
                        aria-label="Season"
                        className="flex gap-1"
                    >
                        {seasons.map((s) => (
                            <Link
                                key={s}
                                href={href({ season: s })}
                                preserveScroll
                                aria-current={s === season ? 'true' : undefined}
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    s !== season && 'gm-btn-ghost',
                                )}
                            >
                                Season {s}
                            </Link>
                        ))}
                    </div>
                )}

                <nav
                    aria-label="Week"
                    className="flex items-center gap-1 sm:ml-auto"
                >
                    {/* House rule (2026-11-20): no week buttons while the week
                        just aired wraps up, and none with nowhere to go. */}
                    {!wrapUp && previous && (
                        <Link
                            href={href({ week: previous.week_number })}
                            preserveScroll
                            aria-label="Previous week"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                        >
                            <ChevronLeft aria-hidden className="size-4" />
                        </Link>
                    )}
                    <span className="min-w-0 px-2 text-center text-sm">
                        <span className="gm-num text-ink">
                            S{season} · Week {statement.season_week}
                        </span>
                        <span className="block text-xs text-ink-dim">
                            {statement.title}
                        </span>
                    </span>
                    {!wrapUp && next && (
                        <Link
                            href={href({ week: next.week_number })}
                            preserveScroll
                            aria-label="Next week"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                        >
                            <ChevronRight aria-hidden className="size-4" />
                        </Link>
                    )}
                    {!wrapUp &&
                        latest &&
                        latest.week_number !== statement.week && (
                            <Link
                                href={href({})}
                                preserveScroll
                                className="gm-btn gm-btn-ghost gm-btn-sm"
                            >
                                Latest
                            </Link>
                        )}
                </nav>
            </div>

            {!mine && (
                <p className="-mt-3 mb-6 text-sm text-ink-dim">
                    You’re looking at the rival GM’s books. They’re read-only.
                </p>
            )}

            {mine && game.deficit.blocked && (
                <Panel title="The board wants a plan" className="mb-6">
                    <p className="mb-4 text-sm text-ink-dim">
                        You’ve run a deficit for{' '}
                        {pluralize(game.deficit.weeks, 'week')}. Pick how you’ll
                        recover before the next show airs.
                    </p>
                    <div className="grid gap-3 md:grid-cols-3">
                        {deficit_choices.map((choice) => (
                            <button
                                key={choice}
                                type="button"
                                className="gm-panel p-4 text-left transition-colors hover:bg-white/[0.04] disabled:cursor-wait disabled:opacity-60"
                                disabled={choosing !== null}
                                aria-busy={choosing === choice || undefined}
                                onClick={() =>
                                    router.post(
                                        gm.financials.deficitChoice.url(
                                            game.id,
                                        ),
                                        { choice },
                                        {
                                            onStart: () => setChoosing(choice),
                                            onFinish: () => setChoosing(null),
                                        },
                                    )
                                }
                            >
                                <div className="gm-heading flex items-center gap-2 text-xl">
                                    <BusyLabel
                                        busy={choosing === choice}
                                        busyText="Saving…"
                                    >
                                        {choiceCopy[choice]?.title ?? choice}
                                    </BusyLabel>
                                </div>
                                <div className="mt-1 text-sm text-ink-dim">
                                    {choiceCopy[choice]?.body}
                                </div>
                            </button>
                        ))}
                    </div>
                </Panel>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                <Panel>
                    <Stat
                        label="Balance now"
                        value={formatMoney(balance)}
                        tone={balance < 0 ? 'bad' : undefined}
                    />
                </Panel>
                <Panel>
                    <Stat
                        label={`Net, week ${statement.season_week}`}
                        value={formatSignedMoney(statement.net)}
                        tone={statement.net >= 0 ? 'good' : 'bad'}
                    />
                </Panel>
                <Panel>
                    <Stat
                        label="Income"
                        value={formatMoney(statement.income)}
                    />
                </Panel>
                <Panel>
                    <Stat
                        label="Costs"
                        value={formatMoney(Math.abs(statement.expenses))}
                    />
                </Panel>
            </div>

            {(statement.starting_budget ?? 0) > 0 && (
                <p className="-mt-3 mb-6 text-sm text-ink-dim">
                    Week {statement.season_week} also opened{' '}
                    {mine ? 'your' : 'the rival’s'} budget with{' '}
                    <span className="gm-num text-ink">
                        {formatMoney(statement.starting_budget ?? 0)}
                    </span>
                    . That’s {mine ? 'your' : 'its'} starting balance, so it
                    isn’t counted as income.
                </p>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start">
                <Panel
                    title={`Week ${statement.season_week} statement`}
                    bodyClassName="p-0"
                >
                    {statement.groups.length === 0 ? (
                        <div className="p-4">
                            <EmptyState>No transactions this week.</EmptyState>
                        </div>
                    ) : (
                        <>
                            <div className="border-b border-white/6 p-4">
                                <SplitBars statement={statement} />
                            </div>
                            {statement.groups.map((g) => (
                                <section
                                    key={g.key}
                                    data-testid={`night-${g.key}`}
                                    aria-label={g.name}
                                    className="border-b border-white/6 last:border-b-0"
                                >
                                    <header className="flex items-baseline justify-between gap-3 bg-white/[0.03] px-4 py-2">
                                        <h3
                                            className={cn(
                                                'font-condensed text-sm font-semibold tracking-wider uppercase',
                                                g.ppv
                                                    ? 'text-gold-hot'
                                                    : 'text-ink',
                                            )}
                                        >
                                            {g.name}
                                            {g.crowd !== null && (
                                                <span className="ml-2 font-sans text-xs font-normal tracking-normal text-ink-dim normal-case">
                                                    {g.crowd.toLocaleString(
                                                        'en-US',
                                                    )}{' '}
                                                    fans
                                                </span>
                                            )}
                                        </h3>
                                        <span
                                            className={cn(
                                                'gm-num text-sm font-semibold',
                                                g.total >= 0
                                                    ? 'text-zone-safe'
                                                    : 'text-zone-serious',
                                            )}
                                        >
                                            {formatSignedMoney(g.total)}
                                        </span>
                                    </header>
                                    <ul className="divide-y divide-white/5">
                                        {rowsOf(g.lines).map((row) => (
                                            <StatementRow
                                                key={row.group}
                                                row={row}
                                            />
                                        ))}
                                    </ul>
                                </section>
                            ))}
                            <div className="flex items-baseline justify-between px-4 py-3 text-sm">
                                <span className="gm-label">
                                    Balance after week {statement.season_week}
                                </span>
                                <span
                                    className={cn(
                                        'gm-num text-lg',
                                        closing < 0 && 'text-zone-serious',
                                    )}
                                >
                                    {formatMoney(closing)}
                                </span>
                            </div>
                        </>
                    )}
                </Panel>

                <Panel title={`Season ${season}, week by week`}>
                    {seasonWeeks.length === 0 ? (
                        <EmptyState>No weeks on the books yet.</EmptyState>
                    ) : (
                        <FinanceTrend
                            weeks={seasonWeeks}
                            selected={statement.week}
                            onSelect={go}
                            season={season}
                        />
                    )}
                </Panel>
            </div>
        </>
    );
}
