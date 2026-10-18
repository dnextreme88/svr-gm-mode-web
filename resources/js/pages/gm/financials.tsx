import { Head, Link, router, usePage } from '@inertiajs/react';
import { EmptyState, PageTitle, Panel, Stat } from '@/components/gm/panel';
import { formatCompactMoney, formatSignedMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { GamePageProps } from '@/types/gm';

type Statement = {
    week: number;
    lines: Array<{
        category: string;
        label: string;
        memo: string;
        amount: number;
    }>;
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
    deficit_choices: string[];
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
        body: 'Release roster members to lower weekly upkeep.',
    },
};

export default function Financials() {
    const { game, statement, history, deficit_choices } =
        usePage<Props>().props;
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

            <div className="mb-6 grid gap-4 md:grid-cols-4">
                <Panel>
                    <Stat
                        label="Balance"
                        value={formatCompactMoney(game.budget)}
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
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_420px]">
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
                                    <th>Category</th>
                                    <th>Detail</th>
                                    <th className="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {statement.lines.map((line, i) => (
                                    <tr key={i}>
                                        <td className="font-condensed text-xs font-semibold tracking-wider text-ink-dim uppercase">
                                            {line.label}
                                        </td>
                                        <td>{line.memo}</td>
                                        <td
                                            className={cn(
                                                'gm-num text-right',
                                                line.amount >= 0
                                                    ? 'text-zone-safe'
                                                    : 'text-zone-serious',
                                            )}
                                        >
                                            {formatSignedMoney(line.amount)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Panel>

                <Panel title="Week by week" bodyClassName="p-0">
                    <ol className="gm-scroll max-h-[60vh] divide-y divide-white/5 overflow-y-auto">
                        {[...history].reverse().map((row) => (
                            <li key={row.week_number}>
                                <Link
                                    href={gm.financials.url(game.id, {
                                        query: { week: row.week_number },
                                    })}
                                    preserveScroll
                                    className={cn(
                                        'grid grid-cols-[3rem_1fr_6rem] items-center gap-3 px-4 py-2 text-sm hover:bg-white/[0.03]',
                                        row.week_number === statement.week &&
                                            'bg-brand/10',
                                    )}
                                >
                                    <span className="gm-num text-ink-faint">
                                        W{row.week_number}
                                    </span>
                                    <span className="flex h-3 items-center gap-px">
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
                                        {formatCompactMoney(row.net)}
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
