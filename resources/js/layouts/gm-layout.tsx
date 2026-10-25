import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { UnreadPill } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FloppyIcon } from '@/components/gm/floppy-icon';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

type NavItem = {
    label: string;
    href: string;
    match: string;
    badge?: number;
};

function navItems(game: GameShell): NavItem[] {
    const id = game.id;

    return [
        { label: 'Schedule', href: gm.schedule.url(id), match: '/schedule' },
        {
            label: 'Email',
            href: gm.email.url(id),
            match: '/email',
            badge: game.unread_emails,
        },
        { label: 'Rosters', href: gm.rosters.index.url(id), match: '/rosters' },
        { label: 'Reports', href: gm.reports.url(id), match: '/reports' },
        {
            label: 'Title History',
            href: gm.titles.history.url(id),
            match: '/titles',
        },
        { label: 'Rivalry', href: gm.rivalries.url(id), match: '/rivalries' },
        {
            label: 'Propose Trade',
            href: gm.trade.show.url(id),
            match: '/trade',
        },
        {
            label: 'Free Agency',
            href: gm.freeAgency.url(id),
            match: '/free-agency',
        },
        {
            label: 'Financials',
            href: gm.financials.url(id),
            match: '/financials',
        },
        { label: 'News', href: gm.news.url(id), match: '/news' },
        { label: 'Docs', href: gm.saves.docs.url(id), match: '/docs' },
    ];
}

/**
 * A career only shows under Saved careers once it's saved. After that,
 * progress is kept as it happens.
 */
function SaveCareerButton({ game }: { game: GameShell }) {
    const [saving, setSaving] = useState(false);

    if (game.saved) {
        return (
            <span
                className="flex items-center gap-1.5 font-condensed text-xs tracking-[0.2em] text-zone-safe uppercase"
                title="Progress is saved automatically from here on"
                data-testid="career-saved"
            >
                <FloppyIcon status="saved" />
                Saved
            </span>
        );
    }

    return (
        <button
            type="button"
            className="gm-btn gm-btn-sm gm-btn-ghost"
            disabled={saving}
            title="Keep this career under Saved careers"
            onClick={() => {
                setSaving(true);
                router.post(
                    gm.saves.persist.url(game.id),
                    {},
                    { preserveScroll: true, onFinish: () => setSaving(false) },
                );
            }}
        >
            <FloppyIcon status={saving ? 'saving' : null} />
            {saving ? 'Saving…' : 'Save career'}
        </button>
    );
}

function Header({ game }: { game: GameShell }) {
    return (
        <header className="relative z-10 border-b border-white/8 bg-arena/80 backdrop-blur">
            <div className="mx-auto flex max-w-[1500px] flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 md:px-6">
                <Link
                    href={gm.saves.index.url()}
                    className="flex items-center gap-3"
                    aria-label="All saves"
                >
                    <BrandBug brand={game.player_brand} />
                    <span className="hidden font-condensed text-xs tracking-[0.25em] text-ink-dim uppercase sm:inline">
                        GM Mode
                    </span>
                </Link>

                <div className="gm-chyron-both flex items-center gap-3 bg-white/[0.06] py-1.5 font-condensed text-sm tracking-wider uppercase">
                    <span className="text-ink-dim">Season</span>
                    <span className="gm-num text-ink">{game.season}</span>
                    <span className="h-3 w-px bg-white/20" />
                    <span className="text-ink-dim">Week</span>
                    <span className="gm-num text-ink">{game.season_week}</span>
                    <span className="h-3 w-px bg-white/20" />
                    <span className="text-brand-hot">{game.month}</span>
                    <span className="text-ink-faint">{game.date}</span>
                </div>

                <div className="ml-auto flex flex-wrap items-center gap-5">
                    {game.next_ppv && (
                        <div className="text-right">
                            <div className="gm-label">Next PPV</div>
                            <div className="font-condensed text-sm font-semibold text-ink">
                                {game.next_ppv.name}
                                <span className="ml-1.5 text-ink-dim">
                                    {game.next_ppv.weeks_away === 0
                                        ? 'this week'
                                        : `in ${game.next_ppv.weeks_away}w`}
                                </span>
                            </div>
                        </div>
                    )}
                    <div className="text-right">
                        <div className="gm-label">Roster</div>
                        <div className="gm-num text-sm">
                            {game.roster_count}
                            <span className="text-ink-faint">
                                /{game.roster_cap}
                            </span>
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="gm-label">Budget</div>
                        <div
                            className={cn(
                                'gm-num text-lg leading-none',
                                game.budget < 0
                                    ? 'text-zone-serious'
                                    : 'text-ink',
                            )}
                        >
                            {formatMoney(game.budget)}
                        </div>
                    </div>
                    <SaveCareerButton game={game} />
                </div>
            </div>
        </header>
    );
}

function Nav({ game, url }: { game: GameShell; url: string }) {
    if (game.stage !== 'in_season') {
        return null;
    }

    return (
        <nav
            aria-label="GM menus"
            className="relative z-10 border-b border-white/6 bg-arena-1/70"
        >
            <div className="gm-scroll mx-auto flex max-w-[1500px] gap-1 overflow-x-auto px-4 md:px-6">
                {navItems(game).map((item) => {
                    const active = url.includes(item.match);

                    return (
                        <Link
                            key={item.label}
                            href={item.href}
                            prefetch
                            className={cn(
                                'relative px-3 py-2.5 font-condensed text-sm font-semibold tracking-[0.12em] whitespace-nowrap uppercase transition-colors',
                                active
                                    ? 'text-white'
                                    : 'text-ink-dim hover:text-ink',
                            )}
                        >
                            {item.label}
                            {item.badge ? (
                                <span className="ml-1.5">
                                    <UnreadPill>{item.badge}</UnreadPill>
                                </span>
                            ) : null}
                            {active && (
                                <span className="absolute inset-x-2 bottom-0 h-[3px] -skew-x-12 bg-brand shadow-[0_0_12px_var(--gm-brand)]" />
                            )}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}

export default function GmLayout({ children }: { children: ReactNode }) {
    const page = usePage<{ game?: GameShell }>();
    const game = page.props.game;
    useFlashToast();

    return (
        <div
            className="gm-root gm-stage min-h-screen"
            data-brand={game?.player_brand ?? 'raw'}
        >
            {game && <Header game={game} />}
            {game && <Nav game={game} url={page.url} />}
            {game?.deficit.blocked && (
                <div className="relative z-10 border-b border-zone-serious/40 bg-zone-serious/15 px-6 py-2 text-center font-condensed text-sm tracking-wide text-zone-serious uppercase">
                    {game.deficit.weeks} weeks in the red. Choose a recovery
                    plan on{' '}
                    <Link
                        href={gm.financials.url(game.id)}
                        className="underline underline-offset-4"
                    >
                        Financials
                    </Link>{' '}
                    before the next show can air.
                </div>
            )}
            <main className="relative z-10 mx-auto max-w-[1500px] px-4 py-8 md:px-6">
                {children}
            </main>
        </div>
    );
}
