import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { PageTitle, Panel, Stat } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { brandName, formatCompactMoney, formatMoney } from '@/lib/gm';
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
};

type AiStep = {
    pick: Pick | null;
    ai_turn: boolean;
    next_brand: Brand | null;
    draft_over: boolean;
    budgets?: Record<Brand, number>;
};

const filters: Array<{ value: RosterType | 'all'; label: string }> = [
    { value: 'all', label: 'All' },
    { value: 'superstar', label: 'Superstars' },
    { value: 'diva', label: 'Divas' },
];

const sleep = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms));

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    );
}

export default function Draft() {
    const props = usePage<Props>().props;
    const { game, max_picks, draft_order } = props;

    // Local copies so AI picks can be played out one at a time.
    const [pool, setPool] = useState(props.pool);
    const [picks, setPicks] = useState(props.picks);
    const [nextBrand, setNextBrand] = useState(props.next_brand);
    const [picksMade, setPicksMade] = useState(props.picks_made);
    const [budgets, setBudgets] = useState<Record<Brand, number> | null>(null);
    const [filter, setFilter] = useState<RosterType | 'all'>('all');
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [animating, setAnimating] = useState(false);
    const [highlight, setHighlight] = useState<{
        id: number;
        brand: Brand;
    } | null>(null);
    const [flashPick, setFlashPick] = useState<number | null>(null);
    const running = useRef(false);
    const skip = useRef(false);
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
        skip.current = false;
        setAnimating(true);
        setFilter('all');
        setSearch('');

        let draftOver = false;

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
                draftOver = step.draft_over;

                if (!step.ai_turn || draftOver) {
                    break;
                }
            }
        } finally {
            running.current = false;
            setAnimating(false);
            setFlashPick(null);
        }

        if (draftOver) {
            router.visit(gm.saves.show.url(game.id));
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
        () =>
            pool.filter(
                (w) =>
                    (filter === 'all' || w.roster_type === filter) &&
                    w.name.toLowerCase().includes(search.toLowerCase()),
            ),
        [pool, filter, search],
    );

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
            <PageTitle kicker="Initial draft" title="Build your roster">
                {animating && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={() => (skip.current = true)}
                    >
                        Skip animation
                    </button>
                )}
                <button
                    type="button"
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                    disabled={busy || animating || !yourTurn}
                    onClick={() => {
                        if (
                            confirm(
                                'Stop drafting? You can fill the remaining spots through Free Agency.',
                            )
                        ) {
                            router.post(gm.draft.finish.url(game.id));
                        }
                    }}
                >
                    Stop drafting
                </button>
            </PageTitle>

            <div className="mb-6 grid gap-4 md:grid-cols-4">
                <div data-brand-tint={nextBrand ?? undefined}>
                    <Panel
                        className={cn(
                            aiTurn &&
                                'shadow-[0_0_40px_-14px_var(--color-brand)]',
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
                    <Stat
                        label="Budget"
                        value={formatCompactMoney(yourBudget)}
                    />
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
                        <div className="flex flex-wrap items-center gap-2">
                            {filters.map((f) => (
                                <button
                                    key={f.value}
                                    type="button"
                                    disabled={animating}
                                    onClick={() => setFilter(f.value)}
                                    className={cn(
                                        'px-2 py-1 font-condensed text-xs font-semibold tracking-wider uppercase',
                                        filter === f.value
                                            ? 'bg-brand text-white'
                                            : 'text-ink-dim hover:text-ink',
                                    )}
                                >
                                    {f.label}
                                </button>
                            ))}
                            <input
                                className="gm-input w-40 py-1 text-sm"
                                placeholder="Search…"
                                aria-label="Search wrestlers"
                                value={search}
                                disabled={animating}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    }
                >
                    <p
                        className="border-b border-white/6 px-4 py-2 text-xs text-ink-faint"
                        data-testid="legends-note"
                    >
                        Legends sit out the draft. Sign them from Free Agency
                        once it's over.
                    </p>
                    <div className="gm-scroll max-h-[65vh] overflow-y-auto">
                        <table className="gm-table">
                            <thead className="sticky top-0 z-10 bg-arena-2">
                                <tr>
                                    <th>Wrestler</th>
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
            </div>
        </>
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
                                <span className="font-condensed text-[0.65rem] tracking-widest text-ink-dim uppercase">
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
