import { Link, router, usePage } from '@inertiajs/react';
import { Menu as MenuIcon, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { UnreadPill } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FactionPromptModal } from '@/components/gm/faction-prompt-modal';
import { FloppyIcon } from '@/components/gm/floppy-icon';
import { NoticeModal } from '@/components/gm/notice-modal';
import { PopoverPanel, usePopover } from '@/components/gm/popover';
import { TitleVacancyModal } from '@/components/gm/title-vacancy-modal';
import { formatMoney, pluralize } from '@/lib/gm';
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

/** How long the button says "Saved" after a save, in ms. */
export const SAVED_FLASH_MS = 2000;

/**
 * Save career (house rule, 2026-11-17): a checkpoint of the whole career.
 * Loading the career from Saved careers goes back to it; anything done
 * since is lost. The button is always there: idle, "Saving…" while the
 * checkpoint is written, then "Saved" with a check for a moment. Beside it,
 * when the career was last saved.
 */
function SaveCareerButton({ game }: { game: GameShell }) {
    const [status, setStatus] = useState<'saving' | 'saved' | null>(null);
    const timer = useRef<number | null>(null);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const save = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        router.post(
            gm.saves.persist.url(game.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setStatus('saving'),
                onSuccess: () => {
                    setStatus('saved');
                    timer.current = window.setTimeout(
                        () => setStatus(null),
                        SAVED_FLASH_MS,
                    );
                },
                onError: () => setStatus(null),
                onCancel: () => setStatus(null),
            },
        );
    };

    const last = game.last_saved;

    return (
        <div className="flex items-center gap-3">
            <span
                className="hidden font-condensed text-xs tracking-wider text-ink-dim uppercase @2xl:inline"
                aria-live="polite"
                data-testid="last-saved"
            >
                {last
                    ? `Last saved: S${last.season} · wk ${last.season_week}`
                    : 'Not saved yet'}
            </span>
            <button
                type="button"
                className={cn(
                    'gm-btn gm-btn-sm gm-btn-ghost min-w-[9.5rem] justify-center',
                    status === 'saved' && 'text-zone-safe',
                )}
                disabled={status === 'saving'}
                aria-busy={status === 'saving' || undefined}
                title={
                    last
                        ? `Save over your last save (Season ${last.season}, week ${last.season_week})`
                        : 'Save this career so you can load it later'
                }
                onClick={save}
                data-testid="save-career"
            >
                <FloppyIcon status={status} />
                {status === 'saving'
                    ? 'Saving…'
                    : status === 'saved'
                      ? 'Saved'
                      : 'Save career'}
            </button>
        </div>
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
        'gm-chyron-both flex w-fit items-center gap-2 py-1.5 [--gm-chyron-bg:rgb(255_255_255/0.06)] font-condensed text-base tracking-wider whitespace-nowrap uppercase @md:gap-3 @md:text-xl';
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
                    ? 'Back to this week’s results'
                    : 'Go to this week’s card'
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
    // Leaving for the save list (2026-11-21): with progress that isn't saved
    // (a career never saved, or changed since its last save), ask first, so
    // a stray click on the logo doesn't lose it. Ctrl / Cmd / middle-click
    // still open the save list in a new tab without asking.
    const [leaving, setLeaving] = useState(false);
    const leave = async (e: React.MouseEvent) => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
            return;
        }

        e.preventDefault();

        try {
            const response = await fetch(gm.saves.unsaved.url(game.id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const { unsaved } = (await response.json()) as {
                unsaved: boolean;
            };

            if (unsaved) {
                setLeaving(true);

                return;
            }
        } catch {
            // Can't tell: ask rather than risk it.
            setLeaving(true);

            return;
        }

        router.visit(gm.saves.index.url());
    };
    const stat = 'min-w-0 text-left @6xl:text-right';
    // On a phone the three stats share one row in small type, so the page
    // starts higher up (UI audit, 2026-11-12).
    const statLabel = 'gm-label text-xs @2xl:text-xl';
    const statValue = 'text-sm @2xl:text-lg';

    return (
        <>
            <header
                className="@container relative z-10 border-b border-white/8 bg-arena/80 backdrop-blur"
                data-testid="gm-header"
            >
                <div className="mx-auto grid max-w-[1600px] grid-cols-[auto_1fr_auto] items-center gap-x-6 gap-y-2 px-4 py-2 md:px-6 @2xl:gap-y-3 @2xl:py-3 @6xl:flex">
                    <Link
                        href={gm.saves.index.url()}
                        className="col-start-1 row-start-1 flex items-center gap-3"
                        aria-label="GM Mode: all saves"
                        onClick={leave}
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
                        className="col-span-3 row-start-3 grid grid-cols-3 items-start gap-x-3 @2xl:row-start-2 @2xl:flex @2xl:flex-wrap @2xl:items-center @2xl:gap-x-8 @2xl:gap-y-2 @6xl:ml-auto @6xl:gap-5"
                        data-testid="gm-header-stats"
                    >
                        {game.next_ppv && (
                            <div className={stat}>
                                <div className={statLabel}>Next PPV</div>
                                <div
                                    className={cn(
                                        'font-condensed font-semibold text-ink',
                                        statValue,
                                    )}
                                >
                                    {game.next_ppv.name}
                                    <span className="ml-1.5 text-ink-dim">
                                        {game.next_ppv.weeks_away === 0
                                            ? 'this week'
                                            : `in ${pluralize(game.next_ppv.weeks_away, 'week')}`}
                                    </span>
                                </div>
                            </div>
                        )}
                        <div className={stat}>
                            <div className={statLabel}>Attendance</div>
                            <div className={cn('gm-num', statValue)}>
                                {game.last_attendance ? (
                                    <>
                                        {game.last_attendance.crowd.toLocaleString(
                                            'en-US',
                                        )}
                                        {/* Which show, in words rather than a
                                        hover-only tooltip. */}
                                        <span className="ml-1.5 font-normal text-ink-dim">
                                            <span className="sr-only">
                                                at {game.last_attendance.show}
                                                ,{' '}
                                            </span>
                                            wk {game.last_attendance.week}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-ink-dim">
                                        <span aria-hidden>—</span>
                                        <span className="sr-only">
                                            No show has aired yet
                                        </span>
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className={stat}>
                            <div className={statLabel}>Budget</div>
                            <div
                                className={cn(
                                    'gm-num',
                                    statValue,
                                    game.budget < 0
                                        ? 'text-zone-serious'
                                        : 'text-zone-safe',
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
            <ConfirmDialog
                open={leaving}
                danger
                title="Leave without saving?"
                message="You have recently made progress on this career but you haven't saved yet. Are you sure you want to leave? You will lose any unsaved progress. Press the Save Career button on top of the header to save your progress"
                confirmLabel="Leave without saving"
                cancelLabel="Stay"
                onConfirm={() => {
                    setLeaving(false);
                    router.visit(gm.saves.index.url());
                }}
                onCancel={() => setLeaving(false)}
            />
        </>
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
                                aria-current={active ? 'page' : undefined}
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
                                role="group"
                                aria-label="All GM menus"
                                className="min-w-64 py-1"
                            >
                                {items.map((item) => {
                                    const active = item === current;

                                    return (
                                        <Link
                                            key={item.label}
                                            href={item.href}
                                            prefetch
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
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

/**
 * A quiet runway under every page: a tall, mostly empty band with a small
 * sign-off. A long dropdown near the bottom of a page (a superstar picker on
 * the last promo slot) lands on it instead of running past the page.
 */
function Footer() {
    return (
        <footer
            data-testid="gm-footer"
            className="relative z-0 mt-8 flex min-h-56 flex-col items-center justify-end gap-2 border-t border-white/6 px-4 pb-6 text-center"
        >
            <span
                aria-hidden
                className="font-condensed text-xl font-semibold tracking-[0.12em] text-ink-faint/50 uppercase"
            >
                GM Mode
            </span>
            <p className="text-xs text-ink-faint">
                Smackdown vs Raw 2006 – a fan-made GM mode web version
            </p>
        </footer>
    );
}

export default function GmLayout({ children }: { children: ReactNode }) {
    const page = usePage<{
        game?: GameShell;
        airing?: boolean;
        continue_to?: unknown;
        wrapping_up?: boolean;
        wrap_up?: boolean;
        filters?: { wrap?: boolean };
        initial?: boolean;
    }>();
    const game = page.props.game;
    // House rule (2026-11-12): while a night airs on the live results (its
    // matches being decided, or a PPV night aired with the weekly show still
    // to book, or the week just finished with the News still to open), the
    // header and menu bar are hidden so the player stays on the show.
    // Results of past weeks keep them. The re-draft (2026-11-16) hides them
    // too: it only opens while the re-draft runs, and nothing there should
    // be clicked away from mid-pick. They come back on the News.
    // House rule (2026-11-17): after an aired week the player goes through
    // its Financials, the News and the inbox before the Schedule, with the
    // header still hidden (`wrap_up`; the inbox carries it in `filters`).
    const wrappingUp =
        ['gm/financials', 'gm/news'].includes(page.component) &&
        page.props.wrap_up === true
            ? true
            : page.component === 'gm/email' &&
              page.props.filters?.wrap === true;
    const onAir =
        wrappingUp ||
        page.component === 'gm/redraft' ||
        (page.component === 'gm/results' &&
            (page.props.airing === true ||
                Boolean(page.props.continue_to) ||
                page.props.wrapping_up === true));

    // Setting up a career (2026-11-21): the initial draft and crowning the
    // first champions hide the menu bar, so nothing leads away before the
    // season starts; the setup steps say where the player is instead.
    const settingUp =
        page.component === 'gm/draft' ||
        (page.component === 'gm/titles/assign' && page.props.initial === true);

    return (
        <div
            className="gm-root gm-stage min-h-screen"
            data-brand={game?.player_brand ?? 'raw'}
        >
            {/* The first Tab stop on every page (UI audit, 2026-11-20). */}
            <a
                href="#main"
                className="sr-only z-50 bg-arena-2 px-4 py-2 font-condensed font-semibold tracking-wider text-ink uppercase focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:outline-2 focus:outline-gold"
            >
                Skip to main content
            </a>
            {game && !onAir && <Header game={game} />}
            {game && !onAir && !settingUp && <Nav game={game} url={page.url} />}
            {game?.deficit.blocked && (
                <div className="relative z-10 border-b border-zone-serious/40 bg-zone-serious/15 px-6 py-2 text-center font-condensed text-sm tracking-wide text-zone-serious uppercase">
                    {pluralize(game.deficit.weeks, 'week')} in the red. Choose a
                    recovery plan on{' '}
                    <Link
                        href={gm.financials.url(game.id)}
                        className="underline underline-offset-4"
                    >
                        Financials
                    </Link>{' '}
                    before the next show can air.
                </div>
            )}
            <main
                id="main"
                tabIndex={-1}
                className="relative z-10 mx-auto max-w-[1600px] scroll-mt-4 px-4 py-6 outline-none md:px-6 md:py-8"
            >
                {children}
            </main>
            <Footer />
            {/* One modal at a time: a vacated title first, then notices, then a new faction. */}
            {game?.title_vacancy_prompt && !game.airing && (
                <TitleVacancyModal
                    key={game.title_vacancy_prompt.id}
                    saveId={game.id}
                    prompt={game.title_vacancy_prompt}
                />
            )}
            {game &&
                !game.title_vacancy_prompt &&
                !game.airing &&
                (game.notices ?? []).length > 0 && (
                    <NoticeModal
                        saveId={game.id}
                        notices={game.notices ?? []}
                    />
                )}
            {game?.faction_prompt &&
                !game.title_vacancy_prompt &&
                (game.notices ?? []).length === 0 &&
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
