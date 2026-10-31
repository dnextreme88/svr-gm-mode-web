import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { EmptyState, PageTitle, Panel, Stat } from '@/components/gm/panel';
import { formatMoney, formatSignedMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { GamePageProps } from '@/types/gm';

type Line = {
    category: string;
    label: string;
    memo: string;
    amount: number;
};

type Statement = {
    week: number;
    lines: Line[];
    /** The opening balance granted that week; not counted as income. */
    starting_budget?: number;
    income: number;
    expenses: number;
    net: number;
};

type HistoryRow = {
    week_number: number;
    season: number;
    income: number;
    expenses: number;
    net: number;
    balance: number;
};

type Props = GamePageProps & {
    statement: Statement;
    history: HistoryRow[];
    weekly_upkeep?: number;
    deficit_choices: string[];
};

/** Categories with more lines than this start collapsed to their subtotal. */
const COLLAPSE_OVER = 3;

/** Line items grouped by category, in the order each category first appears. */
function groupLines(lines: Line[]) {
    const groups: Array<{
        category: string;
        label: string;
        lines: Line[];
        total: number;
    }> = [];

    for (const line of lines) {
        let group = groups.find((g) => g.category === line.category);

        if (!group) {
            group = {
                category: line.category,
                label: line.label,
                lines: [],
                total: 0,
            };
            groups.push(group);
        }

        group.lines.push(line);
        group.total += line.amount;
    }

    return groups;
}

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
        body: 'Release roster members to lower weekly upkeep.',
    },
};

export default function Financials() {
    const { game, statement, history, weekly_upkeep, deficit_choices } =
        usePage<Props>().props;
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const groups = groupLines(statement.lines);
    const maxAbs = Math.max(
        1,
        ...history.map((h) => Math.max(h.income, Math.abs(h.expenses))),
    );

    return (
        <>
            <Head title="Financials" />
            <PageTitle kicker="Income statement" title="Financials" />

            {game.deficit.blocked && (
                <Panel title="The board wants a plan" className="mb-6">
                    <p className="mb-4 text-sm text-ink-dim">
                        You’ve run a deficit for {game.deficit.weeks} weeks.
                        Pick how you’ll recover before the next show airs.
                    </p>
                    <div className="grid gap-3 md:grid-cols-3">
                        {deficit_choices.map((choice) => (
                            <button
                                key={choice}
                                type="button"
                                className="gm-panel p-4 text-left transition-colors hover:bg-white/[0.04]"
                                onClick={() =>
                                    router.post(
                                        gm.financials.deficitChoice.url(
                                            game.id,
                                        ),
                                        { choice },
                                    )
                                }
                            >
                                <div className="gm-heading text-xl">
                                    {choiceCopy[choice]?.title ?? choice}
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
                        label="Balance"
                        value={formatMoney(game.budget)}
                        tone={game.budget < 0 ? 'bad' : undefined}
                    />
                </Panel>
                <Panel>
                    <Stat
                        label={`Week ${statement.week} income`}
                        value={formatSignedMoney(statement.income)}
                        tone="good"
                    />
                </Panel>
                <Panel>
                    <Stat
                        label={`Week ${statement.week} expenses`}
                        value={formatSignedMoney(statement.expenses)}
                        tone="bad"
                    />
                </Panel>
                <Panel>
                    <Stat
                        label="Net"
                        value={formatSignedMoney(statement.net)}
                        tone={statement.net >= 0 ? 'good' : 'bad'}
                    />
                </Panel>
                {weekly_upkeep !== undefined && (
                    <Panel>
                        <Stat
                            label="Upkeep per week"
                            value={formatMoney(weekly_upkeep)}
                        />
                    </Panel>
                )}
            </div>

            {(statement.starting_budget ?? 0) > 0 && (
                <p className="-mt-3 mb-6 text-sm text-ink-dim">
                    Week {statement.week} also opened your budget with{' '}
                    <span className="gm-num text-ink">
                        {formatMoney(statement.starting_budget ?? 0)}
                    </span>
                    . That’s your starting balance, so it isn’t counted as
                    income.
                </p>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
                <Panel
                    title={`Week ${statement.week} line items`}
                    bodyClassName="p-0"
                >
                    {statement.lines.length === 0 ? (
                        <div className="p-4">
                            <EmptyState>No transactions this week.</EmptyState>
                        </div>
                    ) : (
                        <table className="gm-table">
                            <thead>
                                <tr>
                                    <th colSpan={2}>Item</th>
                                    <th className="text-right">Amount</th>
                                </tr>
                            </thead>
                            {groups.map((g) => {
                                const collapsible =
                                    g.lines.length > COLLAPSE_OVER;
                                const open =
                                    !collapsible || expanded[g.category];

                                return (
                                    <tbody
                                        key={g.category}
                                        data-testid={`ledger-${g.category}`}
                                        className="border-b border-white/10"
                                    >
                                        <tr className="bg-white/[0.03]">
                                            <th
                                                scope="rowgroup"
                                                colSpan={2}
                                                className="!font-sans !text-sm !tracking-normal !text-ink !normal-case"
                                            >
                                                {collapsible ? (
                                                    <button
                                                        type="button"
                                                        aria-expanded={open}
                                                        onClick={() =>
                                                            setExpanded({
                                                                ...expanded,
                                                                [g.category]:
                                                                    !open,
                                                            })
                                                        }
                                                        className="gm-row-link inline-flex items-center gap-1 font-semibold"
                                                    >
                                                        <ChevronRight
                                                            aria-hidden
                                                            className={cn(
                                                                'size-4 transition-transform',
                                                                open &&
                                                                    'rotate-90',
                                                            )}
                                                        />
                                                        {g.label}
                                                        <span className="font-normal text-ink-dim">
                                                            ({g.lines.length})
                                                        </span>
                                                    </button>
                                                ) : (
                                                    <span className="font-semibold">
                                                        {g.label}
                                                    </span>
                                                )}
                                            </th>
                                            <td
                                                className={cn(
                                                    'gm-num text-right font-semibold',
                                                    g.total >= 0
                                                        ? 'text-zone-safe'
                                                        : 'text-zone-serious',
                                                )}
                                            >
                                                {formatSignedMoney(g.total)}
                                            </td>
                                        </tr>
                                        {open &&
                                            g.lines.map((line, i) => (
                                                <tr key={i}>
                                                    <td
                                                        colSpan={2}
                                                        className="pl-9 text-sm"
                                                    >
                                                        {line.memo}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'gm-num text-right text-sm',
                                                            line.amount >= 0
                                                                ? 'text-zone-safe'
                                                                : 'text-zone-serious',
                                                        )}
                                                    >
                                                        {formatSignedMoney(
                                                            line.amount,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                );
                            })}
                        </table>
                    )}
                </Panel>

                <Panel title="Week by week" bodyClassName="p-0">
                    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-white/6 px-4 py-2 text-xs text-ink-dim">
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="h-2.5 w-4 bg-zone-safe/70"
                            />
                            Income
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="h-2.5 w-4 bg-zone-serious/70"
                            />
                            Expenses
                        </span>
                        <span className="ml-auto">Net</span>
                    </p>
                    <ol className="gm-scroll max-h-[60vh] divide-y divide-white/5 overflow-y-auto">
                        {[...history].reverse().map((row) => (
                            <li key={row.week_number}>
                                <Link
                                    href={gm.financials.url(game.id, {
                                        query: { week: row.week_number },
                                    })}
                                    preserveScroll
                                    aria-label={`Week ${row.week_number}: income ${formatMoney(row.income)}, expenses ${formatMoney(Math.abs(row.expenses))}, net ${formatSignedMoney(row.net)}`}
                                    aria-current={
                                        row.week_number === statement.week
                                            ? 'page'
                                            : undefined
                                    }
                                    className={cn(
                                        'grid grid-cols-[3rem_1fr_6rem] items-center gap-3 px-4 py-2 text-sm hover:bg-white/[0.03]',
                                        row.week_number === statement.week &&
                                            'bg-brand/10',
                                    )}
                                >
                                    <span className="gm-num text-ink-faint">
                                        W{row.week_number}
                                    </span>
                                    <span
                                        aria-hidden
                                        className="flex h-3 items-center gap-px"
                                    >
                                        <span
                                            className="h-full bg-zone-safe/70"
                                            style={{
                                                width: `${(row.income / maxAbs) * 50}%`,
                                            }}
                                        />
                                        <span
                                            className="h-full bg-zone-serious/70"
                                            style={{
                                                width: `${(Math.abs(row.expenses) / maxAbs) * 50}%`,
                                            }}
                                        />
                                    </span>
                                    <span
                                        className={cn(
                                            'gm-num text-right',
                                            row.net >= 0
                                                ? 'text-zone-safe'
                                                : 'text-zone-serious',
                                        )}
                                    >
                                        {formatMoney(row.net)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ol>
                </Panel>
            </div>
        </>
    );
}
