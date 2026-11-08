import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import {
    LOADING_OVERLAY_DELAY_MS,
    LoadingOverlay,
} from '@/components/gm/loading-overlay';
import { PageTitle, Panel, Stat } from '@/components/gm/panel';
import { PoolFilters } from '@/components/gm/pool-filters';
import { WrestlerName } from '@/components/gm/wrestler-name';
import type { PoolFilter } from '@/lib/gm';
import {
    brandName,
    emptyPoolFilter,
    filterPool,
    formatMoney,
    prefersReducedMotion,
    readPreference,
    writePreference,
} from '@/lib/gm';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, RosterType, WeightClass } from '@/types/enums';
import { RosterTypeLabels, WeightClassLabels } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type PoolRow = WrestlerRow & {
    quote: { upfront: number; weekly: number; length_weeks: number };
};

type Pick = {
    pick_number: number;
    brand: Brand;
    save_wrestler_id: number | null;
    name: string | null;
    roster_type: RosterType | null;
    weight_class: WeightClass | null;
    pick_type: 'manual' | 'random';
};

type Props = GamePageProps & {
    pool: PoolRow[];
    picks: Pick[];
    next_brand: Brand | null;
    max_picks: number;
    picks_made: Record<Brand, number>;
    draft_order: Brand[];
    contract_length: number;
    player_done: boolean;
    /** The draft just ended: the overlay shows, then the champions open. */
    concluded?: boolean;
};

type AiStep = {
    pick: Pick | null;
    ai_turn: boolean;
    next_brand: Brand | null;
    draft_over: boolean;
    budgets?: Record<Brand, number>;
};

const poolTypes = ['all', 'superstar', 'diva'] as const;

const SKIP_ANIMATION_KEY = 'gm.draft.skip-animation';
const BOARD_VIEW_KEY = 'gm.draft.board-view';
type BoardView = 'all' | 'by-show';

const sleep = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms));

export default function Draft() {
    const props = usePage<Props>().props;
    const { game, max_picks, draft_order } = props;

    // Local copies so AI picks can be played out one at a time.
    const [pool, setPool] = useState(props.pool);
    const [picks, setPicks] = useState(props.picks);
    const [nextBrand, setNextBrand] = useState(props.next_brand);
    const [picksMade, setPicksMade] = useState(props.picks_made);
    const [budgets, setBudgets] = useState<Record<Brand, number> | null>(null);
    const [poolFilter, setPoolFilter] = useState<PoolFilter>(emptyPoolFilter);
    const [skipAnimation, setSkipAnimation] = useState(
        () => readPreference(SKIP_ANIMATION_KEY, 'off', ['on', 'off']) === 'on',
    );
    const [boardView, setBoardView] = useState<BoardView>(() =>
        readPreference<BoardView>(BOARD_VIEW_KEY, 'all', ['all', 'by-show']),
    );
    const [confirmStop, setConfirmStop] = useState(false);
    const [skippingDraft, setSkippingDraft] = useState(false);
    const [busy, setBusy] = useState(false);
    const [animating, setAnimating] = useState(false);
    const [highlight, setHighlight] = useState<{
        id: number;
        brand: Brand;
    } | null>(null);
    const [flashPick, setFlashPick] = useState<number | null>(null);
    // The draft is over on both shows: the overlay says so for a moment,
    // then the champions open.
    const [draftOver, setDraftOver] = useState(false);
    const concluded = draftOver || props.concluded === true;

    useEffect(() => {
        if (!concluded) {
            return;
        }

        const timer = window.setTimeout(
            () => router.visit(gm.saves.show.url(props.game.id)),
            LOADING_OVERLAY_DELAY_MS,
        );

        return () => window.clearTimeout(timer);
    }, [concluded, props.game.id]);
    const running = useRef(false);
    // Read before every AI pick, so toggling mid-draft takes effect at once.
    const skip = useRef(skipAnimation);
    // Set by "Skip rest of draft": the loop stops after the pick in flight.
    const abort = useRef(false);
    const rows = useRef(new Map<number, HTMLTableRowElement>());

    // Pick up server state after each Inertia visit (unless an animation is mid-flight).
    useEffect(() => {
        if (running.current) {
            return;
        }

        setPool(props.pool);
        setPicks(props.picks);
        setNextBrand(props.next_brand);
        setPicksMade(props.picks_made);
        setBudgets(null);
    }, [props.pool, props.picks, props.next_brand, props.picks_made]);

    useEffect(() => {
        skip.current = skipAnimation;
        writePreference(SKIP_ANIMATION_KEY, skipAnimation ? 'on' : 'off');
    }, [skipAnimation]);

    useEffect(() => writePreference(BOARD_VIEW_KEY, boardView), [boardView]);

    const yourTurn = nextBrand === game.player_brand;
    const aiTurn = nextBrand !== null && nextBrand !== game.player_brand;
    const yourBudget = budgets?.[game.player_brand] ?? game.budget;

    /**
     * Play out one AI pick: scroll the pool to it, highlight it in the rival's
     * colour, add it to their board, then take it out of the pool.
     */
    const animatePick = useCallback(async (pick: Pick) => {
        const fast = skip.current || prefersReducedMotion();
        const id = pick.save_wrestler_id;
        const row = id === null ? undefined : rows.current.get(id);

        if (!fast && row) {
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            await sleep(600);
        }

        if (id !== null) {
            setHighlight({ id, brand: pick.brand });
        }

        await sleep(fast ? 0 : 950);
        setPicks((current) => [...current, pick]);
        setFlashPick(pick.pick_number);
        await sleep(fast ? 0 : 350);
        setPool((current) => current.filter((w) => w.id !== id));
        setHighlight(null);
        await sleep(fast ? 0 : 200);
    }, []);

    const runAi = useCallback(async () => {
        if (running.current) {
            return;
        }

        running.current = true;
        setAnimating(true);
        setPoolFilter(emptyPoolFilter);

        let over = false;

        try {
            for (;;) {
                const step = await requestJson<AiStep>(
                    'POST',
                    gm.draft.aiStep.url(game.id),
                );

                if (step.pick) {
                    await animatePick(step.pick);
                    setPicksMade((current) => ({
                        ...current,
                        [step.pick!.brand]: current[step.pick!.brand] + 1,
                    }));
                }

                if (step.budgets) {
                    setBudgets(step.budgets);
                }

                setNextBrand(step.next_brand);
                over = step.draft_over;

                if (!step.ai_turn || over || abort.current) {
                    break;
                }
            }
        } finally {
            running.current = false;
            setAnimating(false);
            setFlashPick(null);
        }

        if (over) {
            setDraftOver(true);
        } else if (abort.current) {
            router.post(gm.draft.aiFinish.url(game.id));
        } else {
            router.reload();
        }
    }, [animatePick, game.id]);

    // Whenever it's the rival's turn, let them draft in front of you.
    useEffect(() => {
        if (aiTurn && !running.current) {
            void runAi();
        }
    }, [aiTurn, runAi]);

    const visible = useMemo(
        () => filterPool(pool, poolFilter),
        [pool, poolFilter],
    );

    const skipRestOfDraft = () => {
        setSkippingDraft(true);

        if (running.current) {
            abort.current = true;
        } else {
            router.post(gm.draft.aiFinish.url(game.id));
        }
    };

    const yourPicks = picks.filter((p) => p.brand === game.player_brand);
    const rivalPicks = picks.filter((p) => p.brand === game.rival_brand);

    const pick = (id: number) => {
        setBusy(true);
        router.post(
            gm.draft.pick.url(game.id),
            { save_wrestler_id: id },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <>
            <Head title="Initial Draft" />
            {concluded && (
                <LoadingOverlay
                    testId="draft-concluded"
                    text="Draft picks are finished. You may now assign your brand's champions."
                />
            )}
            <PageTitle kicker="Initial draft" title="Build your roster">
                <button
                    type="button"
                    aria-pressed={skipAnimation}
                    className={cn(
                        'gm-btn gm-btn-sm',
                        !skipAnimation && 'gm-btn-ghost',
                    )}
                    onClick={() => setSkipAnimation((on) => !on)}
                >
                    Skip animation: {skipAnimation ? 'On' : 'Off'}
                </button>
                {props.player_done ? (
                    aiTurn && (
                        <button
                            type="button"
                            className="gm-btn gm-btn-sm"
                            disabled={skippingDraft}
                            onClick={skipRestOfDraft}
                        >
                            {skippingDraft
                                ? 'Finishing draft…'
                                : 'Skip rest of draft'}
                        </button>
                    )
                ) : (
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        disabled={busy || animating || !yourTurn}
                        onClick={() => setConfirmStop(true)}
                    >
                        Stop drafting
                    </button>
                )}
            </PageTitle>

            <ConfirmDialog
                open={confirmStop}
                title="Stop drafting?"
                message={
                    <StopDraftSummary
                        picks={picks.filter(
                            (p) =>
                                p.brand === game.player_brand &&
                                p.save_wrestler_id !== null,
                        )}
                        maxPicks={max_picks}
                        budget={yourBudget}
                    />
                }
                confirmLabel="Stop and crown champions"
                cancelLabel="Keep drafting"
                focusCancel
                onCancel={() => setConfirmStop(false)}
                onConfirm={() => {
                    setConfirmStop(false);
                    router.post(gm.draft.finish.url(game.id));
                }}
            />

            <div className="mb-6 grid gap-4 md:grid-cols-4">
                <div data-brand-tint={nextBrand ?? undefined}>
                    <Panel
                        className={cn(
                            aiTurn && 'shadow-[0_0_40px_-14px_var(--gm-brand)]',
                        )}
                    >
                        <div className="gm-label">On the clock</div>
                        <div
                            className="mt-1 flex items-center gap-3"
                            aria-live="polite"
                        >
                            {nextBrand ? (
                                <>
                                    <BrandBug brand={nextBrand} size="sm" />
                                    <span
                                        data-testid="on-the-clock"
                                        className={cn(
                                            'font-condensed text-sm tracking-wider uppercase',
                                            'animate-gm-pulse text-brand-hot',
                                        )}
                                    >
                                        {yourTurn
                                            ? 'Your pick'
                                            : 'Rival GM drafting…'}
                                    </span>
                                </>
                            ) : (
                                <span className="text-ink-dim">Draft over</span>
                            )}
                        </div>
                        <div className="mt-2 text-xs text-ink-faint">
                            Pick {picks.length + 1} · Order:{' '}
                            {draft_order.map(brandName).join(' → ')}
                        </div>
                    </Panel>
                </div>
                <Panel>
                    <Stat
                        label="Your picks"
                        value={`${picksMade[game.player_brand]} / ${max_picks}`}
                        tone="brand"
                    />
                </Panel>
                <Panel>
                    <Stat label="Budget" value={formatMoney(yourBudget)} />
                </Panel>
                <Panel>
                    <Stat
                        label={`${brandName(game.rival_brand)} picks`}
                        value={`${picksMade[game.rival_brand]} / ${max_picks}`}
                    />
                </Panel>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
                <Panel
                    title={`Free agent pool · ${pool.length}`}
                    bodyClassName="p-0"
                    actions={
                        <PoolFilters
                            value={poolFilter}
                            onChange={setPoolFilter}
                            types={poolTypes}
                            disabled={animating}
                        />
                    }
                >
                    <div className="gm-scroll max-h-[65vh] overflow-y-auto">
                        <table className="gm-table">
                            <thead className="sticky top-0 z-10 bg-arena-2">
                                <tr>
                                    <th>Superstar</th>
                                    <th>Type</th>
                                    <th>Class</th>
                                    <th>Specialties</th>
                                    <th className="text-right">Signing</th>
                                    <th className="text-right">Weekly</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((w) => (
                                    <tr
                                        key={w.id}
                                        ref={(el) => {
                                            if (el) {
                                                rows.current.set(w.id, el);
                                            } else {
                                                rows.current.delete(w.id);
                                            }
                                        }}
                                        data-highlight={
                                            highlight?.id === w.id
                                                ? highlight.brand
                                                : undefined
                                        }
                                        className="gm-row-highlight"
                                    >
                                        <td className="font-semibold">
                                            <WrestlerName wrestler={w} />
                                        </td>
                                        <td className="text-ink-dim">
                                            {RosterTypeLabels[w.roster_type]}
                                        </td>
                                        <td className="text-ink-dim">
                                            {WeightClassLabels[w.weight_class]}
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap gap-1">
                                                {w.specialties.map((s) => (
                                                    <SpecialtyBadge
                                                        key={s}
                                                        family={s}
                                                    />
                                                ))}
                                            </div>
                                        </td>
                                        <td className="gm-num text-right">
                                            {formatMoney(w.quote.upfront)}
                                        </td>
                                        <td className="gm-num text-right text-ink-dim">
                                            {formatMoney(w.quote.weekly)}
                                        </td>
                                        <td className="text-right">
                                            <button
                                                type="button"
                                                className="gm-btn gm-btn-sm"
                                                disabled={
                                                    !yourTurn ||
                                                    busy ||
                                                    animating ||
                                                    yourBudget < w.quote.upfront
                                                }
                                                onClick={() => pick(w.id)}
                                            >
                                                Draft
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="space-y-3">
                    <div
                        className="flex items-center justify-between gap-3"
                        role="group"
                        aria-label="Draft picks view"
                    >
                        <h2 className="gm-heading text-2xl">Draft Picks</h2>
                        <div className="flex gap-1">
                            {(
                                [
                                    ['all', 'All picks'],
                                    ['by-show', 'By show'],
                                ] as const
                            ).map(([view, label]) => (
                                <button
                                    key={view}
                                    type="button"
                                    aria-pressed={boardView === view}
                                    onClick={() => setBoardView(view)}
                                    className={cn(
                                        'px-2 py-1 font-condensed text-xs font-semibold tracking-wider uppercase',
                                        boardView === view
                                            ? 'bg-brand text-white'
                                            : 'text-ink-dim hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {boardView === 'all' ? (
                        <AllPicks picks={picks} flashPick={flashPick} />
                    ) : (
                        <div className="space-y-6">
                            <DraftBoard
                                title="Your roster"
                                brand={game.player_brand}
                                picks={yourPicks}
                                flashPick={flashPick}
                            />
                            <DraftBoard
                                title="Rival roster"
                                brand={game.rival_brand}
                                picks={rivalPicks}
                                flashPick={flashPick}
                            />
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

/** Every pick in draft order, newest at the bottom, with the drafting brand. */
function AllPicks({
    picks,
    flashPick,
}: {
    picks: Pick[];
    flashPick: number | null;
}) {
    const list = useRef<HTMLOListElement>(null);

    useEffect(() => {
        list.current?.scrollTo?.({ top: list.current.scrollHeight });
    }, [picks.length]);

    return (
        <Panel bodyClassName="p-0">
            <ol
                ref={list}
                className="gm-scroll max-h-[65vh] divide-y divide-white/5 overflow-y-auto"
                aria-label="Draft picks"
            >
                {picks.length === 0 && (
                    <li className="px-4 py-3 text-sm text-ink-faint">
                        No picks yet.
                    </li>
                )}
                {picks.map((p) => (
                    <li
                        key={p.pick_number}
                        data-highlight={
                            p.pick_number === flashPick ? p.brand : undefined
                        }
                        className={cn(
                            'gm-row-highlight flex items-center gap-3 px-4 py-2 text-sm',
                            p.pick_number === flashPick && 'animate-gm-rise',
                        )}
                    >
                        <span className="gm-num w-8 text-ink-faint">
                            {p.pick_number}.
                        </span>
                        <span className="min-w-0 flex-1 truncate font-medium">
                            <WrestlerName wrestler={p} />
                        </span>
                        <BrandBug
                            brand={p.brand}
                            size="sm"
                            className="ml-4 shrink-0"
                        />
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

function DraftBoard({
    title,
    brand,
    picks,
    flashPick,
}: {
    title: string;
    brand: Brand;
    picks: Pick[];
    flashPick: number | null;
}) {
    return (
        <div data-brand-tint={brand}>
            <Panel title={`${title} · ${picks.length}`} bodyClassName="p-0">
                <ol
                    className="gm-scroll max-h-[30vh] divide-y divide-white/5 overflow-y-auto"
                    aria-label={title}
                >
                    {picks.length === 0 && (
                        <li className="px-4 py-3 text-sm text-ink-faint">
                            No picks yet.
                        </li>
                    )}
                    {[...picks].reverse().map((p) => (
                        <li
                            key={p.pick_number}
                            data-highlight={
                                p.pick_number === flashPick
                                    ? p.brand
                                    : undefined
                            }
                            className={cn(
                                'gm-row-highlight flex items-center gap-3 px-4 py-2 text-sm',
                                p.pick_number === flashPick &&
                                    'animate-gm-rise',
                            )}
                        >
                            <span className="gm-num w-7 text-ink-faint">
                                #{p.pick_number}
                            </span>
                            <span className="flex-1 font-medium">
                                <WrestlerName wrestler={p} />
                            </span>
                            {p.roster_type && p.roster_type !== 'superstar' && (
                                <span className="font-condensed text-xs tracking-widest text-ink-dim uppercase">
                                    {RosterTypeLabels[p.roster_type]}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </Panel>
        </div>
    );
}

/**
 * What Stop drafting locks in: the roster so far, by division, the budget
 * left, and what happens next.
 */
function StopDraftSummary({
    picks,
    maxPicks,
    budget,
}: {
    picks: Pick[];
    maxPicks: number;
    budget: number;
}) {
    const divas = picks.filter((p) => p.roster_type === 'diva').length;
    const light = picks.filter(
        (p) =>
            p.roster_type !== 'diva' &&
            (p.weight_class === 'cruiserweight' ||
                p.weight_class === 'light_heavyweight'),
    ).length;

    return (
        <div className="space-y-3" data-testid="stop-draft-summary">
            <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <dt className="gm-label">Your roster</dt>
                    <dd className="gm-num text-lg text-ink">
                        {picks.length} of {maxPicks}
                    </dd>
                </div>
                <div>
                    <dt className="gm-label">Superstars</dt>
                    <dd className="gm-num text-lg text-ink">
                        {picks.length - divas}
                    </dd>
                </div>
                <div>
                    <dt className="gm-label">Divas</dt>
                    <dd className="gm-num text-lg text-ink">{divas}</dd>
                </div>
                <div>
                    <dt className="gm-label">Cruiser / LHW</dt>
                    <dd className="gm-num text-lg text-ink">{light}</dd>
                </div>
            </dl>
            <p className="text-sm">
                Budget left:{' '}
                <span className="gm-num text-gold-hot">
                    {formatMoney(budget)}
                </span>
            </p>
            {picks.length > 0 && (
                <ul
                    className="gm-scroll max-h-40 space-y-0.5 overflow-y-auto text-sm"
                    aria-label="Your picks"
                >
                    {picks.map((p) => (
                        <li key={p.pick_number}>
                            {p.roster_type && p.weight_class ? (
                                <WrestlerName
                                    wrestler={{
                                        name: p.name ?? '',
                                        roster_type: p.roster_type,
                                        weight_class: p.weight_class,
                                    }}
                                />
                            ) : (
                                p.name
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <p className="text-sm">
                The rival GM finishes its picks, then you crown your champions.
                Open roster spots can be filled through Free Agency.
            </p>
        </div>
    );
}
