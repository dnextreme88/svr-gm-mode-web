import { Head, Link, router, usePage } from '@inertiajs/react';
import { MoraleBadge } from '@/components/gm/badges';
import { EmptyState, PageTitle } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { EmailTemplate, Morale } from '@/types/enums';
import { EmailTemplateLabels } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type EmailSummary = {
    id: number;
    template: EmailTemplate;
    sender: string;
    subject: string;
    week_number: number;
    read: boolean;
};

type EmailDetail = Omit<EmailSummary, 'read'> & {
    body: string | null;
    payload: Record<string, unknown>;
};

type Props = GamePageProps & {
    emails: EmailSummary[];
    selected: EmailDetail | null;
};

export default function Inbox() {
    const { game, emails, selected } = usePage<Props>().props;

    return (
        <>
            <Head title="Email" />
            <PageTitle
                kicker={game.player_brand === 'raw' ? 'From JR' : 'From Tazz'}
                title="Email"
            />

            {emails.length === 0 ? (
                <EmptyState>Your inbox is empty.</EmptyState>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[380px_1fr]">
                    <ul className="gm-panel gm-scroll max-h-[70vh] divide-y divide-white/5 overflow-y-auto">
                        {emails.map((email) => (
                            <li key={email.id}>
                                <Link
                                    href={gm.email.url(game.id, {
                                        query: { email: email.id },
                                    })}
                                    preserveScroll
                                    className={cn(
                                        'block px-4 py-3 transition-colors hover:bg-white/[0.04]',
                                        selected?.id === email.id &&
                                            'bg-brand/12 shadow-[inset_3px_0_0_var(--color-brand)]',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="gm-label">
                                            {
                                                EmailTemplateLabels[
                                                    email.template
                                                ]
                                            }
                                        </span>
                                        <span className="gm-num text-xs text-ink-faint">
                                            W{email.week_number}
                                        </span>
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-0.5 truncate text-sm',
                                            email.read
                                                ? 'text-ink-dim'
                                                : 'font-semibold text-ink',
                                        )}
                                    >
                                        {!email.read && (
                                            <span className="mr-2 inline-block size-2 rounded-full bg-brand-hot" />
                                        )}
                                        {email.subject}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {selected && (
                        <article className="gm-panel p-6">
                            <div className="gm-label">
                                From {selected.sender} · Week{' '}
                                {selected.week_number}
                            </div>
                            <h2 className="gm-heading mt-2 text-3xl">
                                {selected.subject}
                            </h2>
                            {selected.body && (
                                <p className="mt-4 text-ink-dim">
                                    {selected.body}
                                </p>
                            )}
                            <div className="mt-6">
                                <EmailBody saveId={game.id} email={selected} />
                            </div>
                        </article>
                    )}
                </div>
            )}
        </>
    );
}

type ExpiringRow = {
    save_wrestler_id: number;
    name: string;
    weeks_left: number;
    weekly_cost: number;
    morale: Morale;
    champion: boolean;
    titles: string[];
    expires_this_week: boolean;
};

function EmailBody({ saveId, email }: { saveId: number; email: EmailDetail }) {
    if (email.template === 'expiring_contracts') {
        const rows = email.payload.rows as ExpiringRow[];

        return (
            <table className="gm-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Weeks left</th>
                        <th>Weekly cost</th>
                        <th>Morale</th>
                        <th>Champion?</th>
                        <th className="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.save_wrestler_id}
                            className={cn(
                                row.expires_this_week &&
                                    'bg-zone-serious/12 text-zone-serious',
                            )}
                        >
                            <td className="font-semibold">
                                <Link
                                    href={gm.rosters.show.url({
                                        save: saveId,
                                        saveWrestler: row.save_wrestler_id,
                                    })}
                                    className="hover:underline"
                                >
                                    {row.name}
                                </Link>
                            </td>
                            <td className="gm-num">
                                {row.weeks_left}
                                {row.expires_this_week && (
                                    <span className="ml-2 font-condensed text-xs tracking-wider uppercase">
                                        Expires after this week
                                    </span>
                                )}
                            </td>
                            <td className="gm-num">
                                {formatMoney(row.weekly_cost)}
                            </td>
                            <td>
                                <MoraleBadge morale={row.morale} />
                            </td>
                            <td>
                                {row.champion ? (
                                    <span
                                        className="font-condensed text-xs font-bold tracking-wider text-gold-hot uppercase"
                                        title={row.titles.join(', ')}
                                    >
                                        ◆ Champion
                                    </span>
                                ) : (
                                    <span className="text-ink-faint">—</span>
                                )}
                            </td>
                            <td className="text-right whitespace-nowrap">
                                <Link
                                    href={gm.rosters.show.url({
                                        save: saveId,
                                        saveWrestler: row.save_wrestler_id,
                                    })}
                                    className="gm-btn gm-btn-sm"
                                >
                                    Renegotiate
                                </Link>{' '}
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-ghost gm-btn-sm"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                `Release ${row.name} to Free Agency?`,
                                            )
                                        ) {
                                            router.post(
                                                gm.contracts.release.url({
                                                    save: saveId,
                                                    saveWrestler:
                                                        row.save_wrestler_id,
                                                }),
                                            );
                                        }
                                    }}
                                >
                                    Release
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        );
    }

    if (email.template === 'contract_expired') {
        const rows = email.payload.rows as Array<{
            save_wrestler_id: number;
            name: string;
            vacated: string[];
        }>;

        return (
            <ul className="space-y-2">
                {rows.map((row) => (
                    <li
                        key={row.save_wrestler_id}
                        className="flex items-center justify-between border-l-2 border-brand bg-white/[0.03] px-3 py-2"
                    >
                        <span className="font-semibold">{row.name}</span>
                        <span className="text-sm text-ink-dim">
                            Now in Free Agency
                            {row.vacated.length > 0 &&
                                ` · vacated ${row.vacated.join(', ')}`}
                        </span>
                    </li>
                ))}
            </ul>
        );
    }

    return null;
}
