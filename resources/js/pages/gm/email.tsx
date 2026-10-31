import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Mail, MailOpen, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { MoraleBadge, UnreadPill } from '@/components/gm/badges';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FilterGroup } from '@/components/gm/filter-group';
import { MentionText } from '@/components/gm/mention-text';
import { EmptyState, PageTitle } from '@/components/gm/panel';
import {
    PickerButton,
    PickerClearRow,
    PickerList,
    PickerRow,
} from '@/components/gm/picker';
import { usePopover } from '@/components/gm/popover';
import { brandName, formatMoney, pluralize } from '@/lib/gm';
import type { MentionMatcher, NamedWrestler } from '@/lib/mentions';
import { buildMentionMatcher } from '@/lib/mentions';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { EmailSenderKind, EmailTemplate, Morale } from '@/types/enums';
import { EmailSenderKindLabels, EmailTemplateLabels } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type EmailSummary = {
    id: number;
    template: EmailTemplate;
    sender: string;
    sender_kind: EmailSenderKind;
    subject: string;
    season: number;
    week_number: number;
    read: boolean;
};

type EmailDetail = Omit<EmailSummary, 'read'> & {
    sender_wrestler_id: number | null;
    body: string | null;
    payload: Record<string, unknown>;
};

type Category = { template: EmailTemplate; total: number; unread: number };

type View = 'all' | 'unread';

type Filters = { category: EmailTemplate | null; view: View };

type Props = GamePageProps & {
    emails: EmailSummary[];
    selected: EmailDetail | null;
    categories: Category[];
    filters: Filters;
    wrestlers: NamedWrestler[];
};

/** The filters as query params, leaving out the defaults. */
function filterQuery(filters: Filters): Record<string, string> {
    return {
        ...(filters.category && { category: filters.category }),
        ...(filters.view === 'unread' && { view: 'unread' }),
    };
}

/**
 * The GM inbox: a mail-client list on the left (filter by category and
 * unread, Mark all read, Delete all) and the open email on the right. Below
 * `lg` it's one pane at a time: the list, or the email with a way back.
 */
export default function Inbox() {
    const { game, emails, selected, categories, filters, wrestlers } =
        usePage<Props>().props;
    const url = usePage().url;
    const matcher = useMemo(() => buildMentionMatcher(wrestlers), [wrestlers]);
    const [confirming, setConfirming] = useState<'all' | 'one' | null>(null);

    const total = categories.reduce((sum, c) => sum + c.total, 0);
    const unread = categories.reduce((sum, c) => sum + c.unread, 0);
    const query = filterQuery(filters);
    const inboxUrl = (extra: Record<string, string | number> = {}) =>
        gm.email.url(game.id, { query: { ...query, ...extra } });
    const setFilters = (next: Filters) =>
        router.get(
            gm.email.url(game.id, { query: filterQuery(next) }),
            {},
            { preserveScroll: true },
        );

    // On small screens the email only shows once one was picked.
    const opened = new URLSearchParams(url.split('?')[1] ?? '').has('email');

    // From lg the first email is on screen, so open it for real: that marks
    // it read and makes the URL point at it.
    useEffect(() => {
        if (
            !opened &&
            selected &&
            window.matchMedia?.('(min-width: 1024px)').matches
        ) {
            router.get(
                gm.email.url(game.id, {
                    query: { ...filterQuery(filters), email: selected.id },
                }),
                {},
                { replace: true, preserveState: true, preserveScroll: true },
            );
        }
    }, [opened, selected, filters, game.id]);

    // What Mark all read and Delete all act on: the current view.
    const inCategory = filters.category
        ? (categories.find((c) => c.template === filters.category) ?? {
              total: 0,
              unread: 0,
          })
        : { total, unread };
    const viewUnread = inCategory.unread;
    const viewTotal =
        filters.view === 'unread' ? inCategory.unread : inCategory.total;
    const scope = [
        filters.view === 'unread' && 'unread',
        filters.category && EmailTemplateLabels[filters.category],
    ]
        .filter(Boolean)
        .join(' ');
    const scopeText = scope
        ? `${viewTotal} ${scope} ${viewTotal === 1 ? 'email' : 'emails'}`
        : `all ${pluralize(viewTotal, 'email')}`;

    const index = selected ? emails.findIndex((e) => e.id === selected.id) : -1;
    const next = index >= 0 ? (emails[index + 1] ?? emails[index - 1]) : null;

    const markAllRead = () =>
        router.post(
            gm.email.readAll.url(game.id, { query }),
            {},
            { preserveScroll: true },
        );
    const deleteAll = () =>
        router.delete(gm.email.destroyAll.url(game.id, { query }), {
            onFinish: () => setConfirming(null),
        });
    const markUnread = (email: EmailDetail) =>
        router.patch(
            gm.email.update.url({ save: game.id, email: email.id }, { query }),
            { read: false },
            { preserveScroll: true },
        );
    const deleteOne = (email: EmailDetail) =>
        router.delete(
            gm.email.destroy.url(
                { save: game.id, email: email.id },
                { query: { ...query, ...(next && { next: next.id }) } },
            ),
            { preserveScroll: true, onFinish: () => setConfirming(null) },
        );

    return (
        <>
            <Head title="Email" />
            <PageTitle
                kicker={game.player_brand === 'raw' ? 'From JR' : 'From Tazz'}
                title={
                    <span className="flex items-center gap-3">
                        Email
                        {game.unread_emails > 0 && (
                            <span
                                className="text-base"
                                data-testid="unread-count"
                            >
                                <UnreadPill>
                                    {game.unread_emails} unread
                                </UnreadPill>
                            </span>
                        )}
                    </span>
                }
            />

            {total === 0 ? (
                <EmptyState>Your inbox is empty.</EmptyState>
            ) : (
                <div className="grid gap-6 lg:h-[calc(100dvh-16rem)] lg:min-h-[30rem] lg:grid-cols-[380px_minmax(0,1fr)]">
                    <section
                        aria-label="Inbox"
                        className={cn(
                            'gm-panel min-h-0 flex-col',
                            opened ? 'hidden lg:flex' : 'flex',
                        )}
                    >
                        <div className="space-y-3 border-b border-white/8 p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <FilterGroup
                                    label="Show"
                                    hideLabel
                                    value={filters.view}
                                    onChange={(view) =>
                                        setFilters({ ...filters, view })
                                    }
                                    options={[
                                        { value: 'all', label: 'All' },
                                        {
                                            value: 'unread',
                                            label: `Unread (${unread})`,
                                        },
                                    ]}
                                />
                                <div className="min-w-44 flex-1">
                                    <CategoryPicker
                                        categories={categories}
                                        total={total}
                                        value={filters.category}
                                        onChange={(category) =>
                                            setFilters({ ...filters, category })
                                        }
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-ghost gm-btn-sm whitespace-nowrap"
                                    disabled={viewUnread === 0}
                                    onClick={markAllRead}
                                >
                                    <MailOpen
                                        aria-hidden
                                        className="hidden size-4 sm:block"
                                    />
                                    Mark all read
                                </button>
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-danger gm-btn-sm whitespace-nowrap"
                                    disabled={viewTotal === 0}
                                    onClick={() => setConfirming('all')}
                                >
                                    <Trash2
                                        aria-hidden
                                        className="hidden size-4 sm:block"
                                    />
                                    Delete all
                                </button>
                            </div>
                        </div>

                        {emails.length === 0 ? (
                            <div className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-ink-dim">
                                No {scope} emails.
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-ghost gm-btn-sm"
                                    onClick={() =>
                                        setFilters({
                                            category: null,
                                            view: 'all',
                                        })
                                    }
                                >
                                    Clear filters
                                </button>
                            </div>
                        ) : (
                            <ul className="gm-scroll max-h-[70vh] min-h-0 flex-1 divide-y divide-white/5 overflow-x-hidden overflow-y-auto lg:max-h-none">
                                {emails.map((email) => (
                                    <li key={email.id}>
                                        <EmailRow
                                            email={email}
                                            href={inboxUrl({ email: email.id })}
                                            selected={selected?.id === email.id}
                                            opened={opened}
                                            matcher={matcher}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-label="Email"
                        className={cn(
                            'gm-panel gm-scroll min-h-0 overflow-y-auto',
                            opened ? 'block' : 'hidden lg:block',
                        )}
                    >
                        {selected ? (
                            <article className="p-6">
                                <Link
                                    href={inboxUrl()}
                                    preserveScroll
                                    className="gm-btn gm-btn-ghost gm-btn-sm mb-4 lg:hidden"
                                >
                                    <ArrowLeft aria-hidden className="size-4" />
                                    Inbox
                                </Link>
                                <div className="flex items-start justify-between gap-3">
                                    <span className="gm-label border border-white/10 bg-white/[0.04] px-2 py-0.5">
                                        {EmailTemplateLabels[selected.template]}
                                    </span>
                                    <div className="flex shrink-0 gap-1">
                                        <button
                                            type="button"
                                            className="gm-btn gm-btn-ghost gm-btn-sm size-10 p-0"
                                            aria-label="Mark as unread"
                                            title="Mark as unread"
                                            onClick={() => markUnread(selected)}
                                        >
                                            <Mail
                                                aria-hidden
                                                className="size-4"
                                            />
                                        </button>
                                        <button
                                            type="button"
                                            className="gm-btn gm-btn-ghost gm-btn-sm size-10 p-0 text-zone-serious"
                                            aria-label="Delete"
                                            title="Delete"
                                            onClick={() => setConfirming('one')}
                                        >
                                            <Trash2
                                                aria-hidden
                                                className="size-4"
                                            />
                                        </button>
                                    </div>
                                </div>
                                <h2 className="gm-heading mt-3 text-3xl">
                                    <MentionText
                                        text={selected.subject}
                                        matcher={matcher}
                                    />
                                </h2>
                                <SenderBlock
                                    saveId={game.id}
                                    email={selected}
                                    brand={brandName(game.player_brand)}
                                    matcher={matcher}
                                />
                                {selected.body && (
                                    <p className="mt-5 whitespace-pre-line text-ink-dim">
                                        <MentionText
                                            text={selected.body}
                                            matcher={matcher}
                                        />
                                    </p>
                                )}
                                <div className="mt-6">
                                    <EmailBody
                                        saveId={game.id}
                                        email={selected}
                                        matcher={matcher}
                                    />
                                </div>
                            </article>
                        ) : (
                            <div className="flex h-full min-h-60 flex-col items-center justify-center gap-2 text-ink-faint">
                                <Mail aria-hidden className="size-6" />
                                Select an email to read it.
                            </div>
                        )}
                    </section>
                </div>
            )}

            <ConfirmDialog
                open={confirming === 'all'}
                danger
                title="Delete emails"
                message={`Delete ${scopeText}? This can't be undone.`}
                confirmLabel="Delete"
                onConfirm={deleteAll}
                onCancel={() => setConfirming(null)}
            />
            <ConfirmDialog
                open={confirming === 'one' && selected !== null}
                danger
                title="Delete email"
                message={`Delete “${selected?.subject ?? ''}”? This can't be undone.`}
                confirmLabel="Delete"
                onConfirm={() => selected && deleteOne(selected)}
                onCancel={() => setConfirming(null)}
            />
        </>
    );
}

/** Initials avatar, tinted by who wrote in. */
const AVATAR_TINT: Record<EmailSenderKind, string> = {
    commentator: 'bg-brand/20 text-brand-hot',
    chairman: 'bg-gold/15 text-gold-hot',
    trainer: 'bg-zone-safe/15 text-zone-safe',
    rival_gm: 'bg-rival/20 text-rival',
    superstar: 'bg-white/8 text-ink-dim',
};

function SenderAvatar({
    sender,
    kind,
    className,
}: {
    sender: string;
    kind: EmailSenderKind;
    className?: string;
}) {
    const initials = sender
        .split(/\s+/)
        .map((word) => word[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <span
            aria-hidden
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-full font-condensed text-sm font-bold tracking-wide',
                AVATAR_TINT[kind],
                className,
            )}
        >
            {initials}
        </span>
    );
}

function EmailRow({
    email,
    href,
    selected,
    opened,
    matcher,
}: {
    email: EmailSummary;
    href: string;
    selected: boolean;
    /** Below lg the list only shows while no email is open. */
    opened: boolean;
    matcher: MentionMatcher;
}) {
    return (
        <Link
            href={href}
            preserveScroll
            preserveState
            aria-current={selected ? 'true' : undefined}
            className={cn(
                'flex gap-3 px-4 py-3 transition-colors hover:bg-white/[0.04]',
                selected &&
                    (opened
                        ? 'bg-brand/12 shadow-[inset_3px_0_0_var(--gm-brand)]'
                        : 'lg:bg-brand/12 lg:shadow-[inset_3px_0_0_var(--gm-brand)]'),
            )}
        >
            <SenderAvatar sender={email.sender} kind={email.sender_kind} />
            <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                    <span
                        className={cn(
                            'truncate text-sm',
                            email.read
                                ? 'text-ink-dim'
                                : 'font-semibold text-ink',
                        )}
                    >
                        {email.sender}
                    </span>
                    <span className="flex shrink-0 items-center gap-2">
                        {!email.read && (
                            <span
                                role="img"
                                aria-label="Unread"
                                className="size-2 rounded-full bg-brand-hot"
                            />
                        )}
                        <span className="gm-num text-xs text-ink-faint">
                            W{email.week_number}
                        </span>
                    </span>
                </div>
                <div className="gm-label mt-0.5">
                    {EmailTemplateLabels[email.template]}
                </div>
                <div
                    className={cn(
                        'mt-0.5 truncate text-sm',
                        email.read ? 'text-ink-dim' : 'text-ink',
                    )}
                >
                    <MentionText text={email.subject} matcher={matcher} />
                </div>
            </div>
        </Link>
    );
}

function SenderBlock({
    saveId,
    email,
    brand,
    matcher,
}: {
    saveId: number;
    email: EmailDetail;
    brand: string;
    matcher: MentionMatcher;
}) {
    const role =
        email.sender_kind === 'commentator'
            ? `${brand} commentator`
            : EmailSenderKindLabels[email.sender_kind];
    const name = <MentionText text={email.sender} matcher={matcher} />;

    return (
        <div className="mt-4 flex items-center gap-3 border-y border-white/8 py-3">
            <SenderAvatar
                sender={email.sender}
                kind={email.sender_kind}
                className="size-11 text-base"
            />
            <div className="min-w-0 flex-1">
                <div className="truncate font-semibold text-ink">
                    <span className="sr-only">From </span>
                    {email.sender_wrestler_id ? (
                        <Link
                            href={gm.rosters.show.url({
                                save: saveId,
                                saveWrestler: email.sender_wrestler_id,
                            })}
                            className="hover:underline"
                        >
                            {name}
                        </Link>
                    ) : (
                        name
                    )}
                </div>
                <div className="gm-label">{role} · to you</div>
            </div>
            <div className="gm-num shrink-0 text-right text-xs text-ink-faint">
                Season {email.season}
                <br />
                Week {email.week_number}
            </div>
        </div>
    );
}

/** The category dropdown: only categories in the inbox, with counts. */
function CategoryPicker({
    categories,
    total,
    value,
    onChange,
}: {
    categories: Category[];
    total: number;
    value: EmailTemplate | null;
    onChange: (value: EmailTemplate | null) => void;
}) {
    const popover = usePopover();
    const sorted = [...categories].sort((a, b) =>
        EmailTemplateLabels[a.template].localeCompare(
            EmailTemplateLabels[b.template],
        ),
    );
    const pick = (next: EmailTemplate | null) => {
        popover.close();

        if (next !== value) {
            onChange(next);
        }
    };

    return (
        <div ref={popover.ref} className="relative">
            <PickerButton
                open={popover.open}
                aria-label="Category"
                onClick={popover.toggle}
            >
                <span className="truncate">
                    {value ? EmailTemplateLabels[value] : 'All categories'}
                </span>
            </PickerButton>
            {popover.mounted && (
                <PickerList open={popover.open} label="Category">
                    <PickerClearRow
                        selected={value === null}
                        onClick={() => pick(null)}
                    >
                        All categories ({total})
                    </PickerClearRow>
                    {sorted.map((c) => (
                        <PickerRow
                            key={c.template}
                            selected={value === c.template}
                            onClick={() => pick(c.template)}
                            aside={
                                <>
                                    {c.unread > 0 && (
                                        <UnreadPill>{c.unread}</UnreadPill>
                                    )}
                                    <span className="gm-num text-ink-faint">
                                        {c.total}
                                    </span>
                                </>
                            }
                        >
                            <span className="truncate text-sm">
                                {EmailTemplateLabels[c.template]}
                            </span>
                        </PickerRow>
                    ))}
                </PickerList>
            )}
        </div>
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

function EmailBody({
    saveId,
    email,
    matcher,
}: {
    saveId: number;
    email: EmailDetail;
    matcher: MentionMatcher;
}) {
    if (email.template === 'expiring_contracts') {
        const rows = email.payload.rows as ExpiringRow[];

        return (
            <div className="overflow-x-auto">
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
                                        <MentionText
                                            text={row.name}
                                            matcher={matcher}
                                        />
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
                                        <span className="text-ink-faint">
                                            —
                                        </span>
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
            </div>
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
                        <span className="font-semibold">
                            <MentionText text={row.name} matcher={matcher} />
                        </span>
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
