import { Link, router, usePage } from '@inertiajs/react';
import { Menu as MenuIcon, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useLayoutEffect, useRef, useState } from 'react';
import { UnreadPill } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FactionPromptModal } from '@/components/gm/faction-prompt-modal';
import { FloppyIcon } from '@/components/gm/floppy-icon';
import { PopoverPanel, usePopover } from '@/components/gm/popover';
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
            label: 'Factions',
            href: gm.factions.index.url(id),
            match: '/factions',
        },
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
                className="flex items-center gap-1.5 font-condensed tracking-[0.2em] text-zone-safe uppercase"
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

/**
 * Season, week and date. Clicking it opens this week's card to book (the
 * PPV first, then the interbrand card, then the weekly show), or the live
 * results while the week airs.
 */
function WeekChip({ game }: { game: GameShell }) {
    // Container queries on the header (`@container` there): smaller on phones.
    const chip =
        'gm-chyron-both flex w-fit items-center gap-2 bg-white/6 py-1.5 font-condensed text-base tracking-wider whitespace-nowrap uppercase @md:gap-3 @md:text-xl';
    const content = (
        <>
            <span className="text-ink-dim">Season</span>
            <span className="gm-num text-ink">{game.season}</span>
            <span className="h-3 w-px bg-white/20" />
            <span className="text-ink-dim">Week</span>
            <span className="gm-num text-ink">{game.season_week}</span>
            <span className="h-3 w-px bg-white/20" />
            <span className="text-brand-hot">{game.month}</span>
            <span className="text-ink-faint">{game.date}</span>
        </>
    );

    if (!game.current_card_url) {
        return <div className={chip}>{content}</div>;
    }

    return (
        <Link
            href={game.current_card_url}
            className={cn(chip, 'transition-colors hover:bg-white/12')}
            title={
                game.airing
                    ? "Back to this week's results"
                    : "Go to this week's card"
            }
            data-testid="week-chip"
        >
            {content}
        </Link>
    );
}

/**
 * The broadcast header. Sized by container queries on the header itself:
 *
 * - 72rem and up: one row (brand, week chip, then Next PPV, Roster, Budget
 *   and Save on the right).
 * - 42–72rem: two rows. Brand, week chip and Save on top; the stats
 *   underneath, left-aligned.
 * - Below 42rem: the week chip gets its own row under brand and Save.
 */
function Header({ game }: { game: GameShell }) {
    const stat = 'text-left @6xl:text-right';

    return (
        <header
            className="@container relative z-10 border-b border-white/8 bg-arena/80 backdrop-blur"
            data-testid="gm-header"
        >
            <div className="mx-auto grid max-w-[1600px] grid-cols-[auto_1fr_auto] items-center gap-x-6 gap-y-3 px-3 py-3 md:px-6 @6xl:flex">
                <Link
                    href={gm.saves.index.url()}
                    className="col-start-1 row-start-1 flex items-center gap-3"
                    aria-label="All saves"
                >
                    <BrandBug brand={game.player_brand} />
                    <span className="hidden font-condensed text-lg tracking-[0.25em] text-ink-dim uppercase @3xl:inline">
                        GM Mode
                    </span>
                </Link>

                <div className="col-span-3 row-start-2 min-w-0 @2xl:col-span-1 @2xl:col-start-2 @2xl:row-start-1">
                    <WeekChip game={game} />
                </div>

                <div
                    className="col-span-3 row-start-3 flex flex-wrap items-center gap-x-8 gap-y-2 @2xl:row-start-2 @6xl:ml-auto @6xl:gap-5"
                    data-testid="gm-header-stats"
                >
                    {game.next_ppv && (
                        <div className={stat}>
                            <div className="gm-label text-xl">Next PPV</div>
                            <div className="font-condensed text-lg font-semibold text-ink">
                                {game.next_ppv.name}
                                <span className="ml-1.5 text-ink-dim">
                                    {game.next_ppv.weeks_away === 0
                                        ? 'this week'
                                        : `in ${game.next_ppv.weeks_away}w`}
                                </span>
                            </div>
                        </div>
                    )}
                    <div className={stat}>
                        <div className="gm-label text-xl">Roster</div>
                        <div className="gm-num text-lg">
                            {game.roster_count}
                            <span className="text-ink-faint">
                                /{game.roster_cap}
                            </span>
                        </div>
                    </div>
                    <div className={stat}>
                        <div className="gm-label text-xl">Budget</div>
                        <div
                            className={cn(
                                'gm-num text-lg',
                                game.budget < 0
                                    ? 'text-zone-serious'
                                    : 'text-ink',
                            )}
                        >
                            {formatMoney(game.budget)}
                        </div>
                    </div>
                </div>

                <div className="col-start-3 row-start-1 justify-self-end">
                    <SaveCareerButton game={game} />
                </div>
            </div>
        </header>
    );
}

const navLink =
    'relative px-3 py-2.5 font-condensed font-semibold tracking-[0.12em] whitespace-nowrap uppercase transition-colors';

function NavLabel({ item }: { item: NavItem }) {
    return (
        <>
            {item.label}
            {item.badge ? (
                <span className="ml-1.5">
                    <UnreadPill>{item.badge}</UnreadPill>
                </span>
            ) : null}
        </>
    );
}

/**
 * Whether the menu bar's links fit on one line: a hidden copy of them is
 * measured against the bar's width, again whenever either changes size.
 * Container queries can't tell when content overflows, so this measures.
 */
function useFits() {
    const bar = useRef<HTMLDivElement>(null);
    const links = useRef<HTMLDivElement>(null);
    const [fits, setFits] = useState(true);

    useLayoutEffect(() => {
        const outer = bar.current;
        const inner = links.current;

        if (!outer || !inner || typeof ResizeObserver === 'undefined') {
            return;
        }

        const check = () => {
            const style = getComputedStyle(outer);
            const room =
                outer.clientWidth -
                (parseFloat(style.paddingLeft) || 0) -
                (parseFloat(style.paddingRight) || 0);

            setFits(inner.scrollWidth <= room);
        };

        check();
        const observer = new ResizeObserver(check);
        observer.observe(outer);
        observer.observe(inner);
        // Belt and braces: the first measure can run before the condensed
        // webfont loads (the fallback is wider, so the bar folds), and a
        // window resize must always get a fresh look.
        window.addEventListener('resize', check);
        let live = true;
        void document.fonts?.ready.then(() => {
            if (live) {
                check();
            }
        });

        return () => {
            live = false;
            observer.disconnect();
            window.removeEventListener('resize', check);
        };
    }, []);

    return { bar, links, fits };
}

function Nav({ game, url }: { game: GameShell; url: string }) {
    if (game.stage !== 'in_season') {
        return null;
    }

    return <MenuBar items={navItems(game)} url={url} />;
}

/**
 * The GM menus. When the links don't all fit on one line they fold into a
 * Menu button that names the current page and opens the full list.
 */
function MenuBar({ items, url }: { items: NavItem[]; url: string }) {
    const { bar, links, fits } = useFits();
    const menu = usePopover();
    const current = items.find((item) => url.includes(item.match));
    const unread = items.reduce((sum, item) => sum + (item.badge ?? 0), 0);

    return (
        <nav
            aria-label="GM menus"
            className="relative z-20 border-b border-white/6 bg-arena-1/70"
        >
            <div
                ref={bar}
                className="relative mx-auto flex max-w-[1600px] gap-1 px-4 md:px-6"
                data-testid="gm-menu-bar"
            >
                {/* What the links need, measured to pick the layout. Clipped,
                    so the copy never widens the page. */}
                <div
                    aria-hidden
                    className="pointer-events-none invisible absolute inset-0 overflow-hidden"
                >
                    <div ref={links} className="flex w-max gap-1">
                        {items.map((item) => (
                            <span key={item.label} className={navLink}>
                                <NavLabel item={item} />
                            </span>
                        ))}
                    </div>
                </div>

                {fits ? (
                    items.map((item) => {
                        const active = item === current;

                        return (
                            <Link
                                key={item.label}
                                href={item.href}
                                prefetch
                                className={cn(
                                    navLink,
                                    active
                                        ? 'text-white'
                                        : 'text-ink-dim hover:text-ink',
                                )}
                            >
                                <NavLabel item={item} />
                                {active && (
                                    <span className="absolute inset-x-2 bottom-0 h-[3px] -skew-x-12 bg-brand shadow-[0_0_12px_var(--gm-brand)]" />
                                )}
                            </Link>
                        );
                    })
                ) : (
                    <div ref={menu.ref} className="relative">
                        <button
                            type="button"
                            aria-haspopup="menu"
                            aria-expanded={menu.open}
                            aria-label="Menu"
                            onClick={menu.toggle}
                            className={cn(
                                navLink,
                                'flex items-center gap-2 text-ink hover:text-white',
                            )}
                        >
                            {menu.open ? (
                                <X className="size-5" aria-hidden />
                            ) : (
                                <MenuIcon className="size-5" aria-hidden />
                            )}
                            Menu
                            {current && (
                                <span className="text-brand-hot">
                                    · {current.label}
                                </span>
                            )}
                            {unread > 0 && <UnreadPill>{unread}</UnreadPill>}
                        </button>
                        {menu.mounted && (
                            <PopoverPanel
                                open={menu.open}
                                role="menu"
                                aria-label="GM menus"
                                className="min-w-64 py-1"
                            >
                                {items.map((item) => {
                                    const active = item === current;

                                    return (
                                        <Link
                                            key={item.label}
                                            href={item.href}
                                            prefetch
                                            role="menuitem"
                                            onClick={menu.close}
                                            className={cn(
                                                navLink,
                                                'flex items-center border-l-[3px]',
                                                active
                                                    ? 'border-brand bg-white/5 text-white'
                                                    : 'border-transparent text-ink-dim hover:bg-white/5 hover:text-ink',
                                            )}
                                        >
                                            <NavLabel item={item} />
                                        </Link>
                                    );
                                })}
                            </PopoverPanel>
                        )}
                    </div>
                )}
            </div>
        </nav>
    );
}

export default function GmLayout({ children }: { children: ReactNode }) {
    const page = usePage<{ game?: GameShell }>();
    const game = page.props.game;

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
            <main className="relative z-10 mx-auto max-w-[1600px] px-3 py-8 md:px-6">
                {children}
            </main>
            {game?.faction_prompt &&
                game.stage === 'in_season' &&
                !game.airing && (
                    <FactionPromptModal
                        key={game.faction_prompt.id}
                        saveId={game.id}
                        prompt={game.faction_prompt}
                        brand={game.player_brand}
                    />
                )}
        </div>
    );
}
