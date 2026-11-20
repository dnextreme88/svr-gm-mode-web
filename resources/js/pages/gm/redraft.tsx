import { Head, router, usePage } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { InjuryChip, MoraleBadge, TitleBelt } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { BusyLabel } from '@/components/gm/busy-label';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PageTitle, Panel } from '@/components/gm/panel';
import { SuperstarReel } from '@/components/gm/superstar-reel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import {
    brandName,
    pluralize,
    prefersReducedMotion,
    readPreference,
    writePreference,
} from '@/lib/gm';
import { requestJson } from '@/lib/http';
import { useBusy } from '@/lib/use-busy';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
import type { FatigueConfig, GamePageProps, WrestlerRow } from '@/types/gm';

type Row = WrestlerRow & { locked: string | null };

type Pick = {
    pick_number: number;
    brand: Brand;
    from: Brand;
    save_wrestler_id: number | null;
    name: string | null;
    pick_type: 'manual' | 'random';
    skipped: boolean;
};

type Phase = 'immunity' | 'manual' | 'picks' | 'done';

type Props = GamePageProps & {
    phase: Phase;
    series: {
        season: number;
        /** When the career was created (keys what this browser has seen). */
        career: number;
        raw_wins: number;
        smackdown_wins: number;
        winner_brand: Brand | null;
        immunity_brand: Brand | null;
        /** Who the immunity tag protects (house rule, 2026-11-17: up to 3). */
        exemption_wrestler_ids: number[];
        /** Who the manual draft pick takes, in pick order (up to 2). */
        handpick_wrestler_ids: number[];
        /** How many each tag takes this time (fewer when fewer are eligible). */
        immunity_needed: number;
        manual_needed: number;
        crowds: Record<Brand, number>;
    };
    rosters: Record<Brand, Row[]>;
    order: Brand[];
    per_brand: number;
    picks: Pick[];
};

const SKIP_ANIMATION_KEY = 'gm.redraft.skip-animation';

/** "Edge, Kane and Carlito". */
function joinNames(names: string[]): string {
    if (names.length <= 1) {
        return names[0] ?? '';
    }

    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

const sleep = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * The post-WrestleMania re-draft (house rule, 2026-11-15), laid out like
 * Propose Trade: the immunity tag, the manual draft pick (confirmed in a
 * modal), then 5 picks each way, alternating, each one played out: a
 * random pick's reel stops on the superstar (SuperstarReel), then the
 * list scrolls to them, highlights them in the picking brand's colour and
 * moves them across. Continue opens WrestleMania week's news.
 */
export default function Redraft() {
    const props = usePage<Props>().props;
    const { game, series, order, per_brand } = props;
    const player = game.player_brand;
    // Like Propose Trade (2026-11-16): your brand on the left, the rival's on the right.
    const brands: Brand[] = [player, player === 'raw' ? 'smackdown' : 'raw'];
    const { busy, track } = useBusy();

    const [rosters, setRosters] = useState(props.rosters);
    const [picks, setPicks] = useState(props.picks);
    const [phase, setPhase] = useState<Phase>(props.phase);
    // The superstars ticked for the tag being used, in the order ticked.
    const [choice, setChoice] = useState<number[]>([]);
    // Which tag's confirmation is open: the immunity tag or the manual pick.
    const [confirming, setConfirming] = useState<
        'immunity' | 'handpick' | null
    >(null);
    const [running, setRunning] = useState(false);
    // The rules open on their own the first time this re-draft's page opens
    // (remembered per browser, per career and season), and the button under
    // the title opens them again.
    const rulesKey = `gm.redraft-rules.${game.id}.${series.career}.${series.season}`;
    const [rulesOpen, setRulesOpen] = useState(
        () =>
            readPreference(rulesKey, 'unseen', ['seen', 'unseen']) === 'unseen',
    );
    const [highlight, setHighlight] = useState<{
        id: number;
        brand: Brand;
    } | null>(null);
    const [flash, setFlash] = useState<number | null>(null);
    const [skipAnimation, setSkipAnimation] = useState(
        () => readPreference(SKIP_ANIMATION_KEY, 'off', ['on', 'off']) === 'on',
    );
    const skip = useRef(skipAnimation);
    // A random pick's slot-machine reveal (house rule, 2026-11-16): who it
    // could have been, who it is, and the resolve that lets the move play.
    const [reel, setReel] = useState<{
        pool: Row[];
        result: Row;
        pick: Pick;
    } | null>(null);
    const reelDone = useRef<(() => void) | null>(null);
    const rostersRef = useRef(rosters);

    useEffect(() => {
        rostersRef.current = rosters;
    }, [rosters]);
    const rows = useRef(new Map<number, HTMLLIElement>());
    const started = useRef(false);

    // Server state after a tag is set (an Inertia visit), unless picks are playing.
    useEffect(() => {
        if (started.current) {
            return;
        }

        setRosters(props.rosters);
        setPicks(props.picks);
        setPhase(props.phase);
        setChoice([]);
    }, [props.rosters, props.picks, props.phase]);

    // The rival GM's tags (2026-11-17): when it uses the immunity tag or the
    // manual draft pick, a reel in its colour reveals each superstar in
    // turn, then the list scrolls to them and highlights them. Each tag
    // plays once per browser (Skip animation skips it), after the rules modal.
    const rival = brands[1];
    const [tagQueue, setTagQueue] = useState<
        {
            tag: 'immunity' | 'manual';
            pool: Row[];
            result: Row;
            n: number;
            of: number;
        }[]
    >([]);
    const tagReel = tagQueue[0] ?? null;
    const tagKey = (tag: 'immunity' | 'manual') =>
        `gm.redraft-tag.${game.id}.${series.career}.${series.season}.${tag}`;

    useEffect(() => {
        if (rulesOpen || tagQueue.length > 0 || running || started.current) {
            return;
        }

        const unseen = (tag: 'immunity' | 'manual') =>
            readPreference(tagKey(tag), 'unseen', ['seen', 'unseen']) ===
            'unseen';
        const reveal = (
            tag: 'immunity' | 'manual',
            from: Brand,
            ids: number[],
        ): boolean => {
            if (ids.length === 0 || !unseen(tag)) {
                return false;
            }

            const pool = rosters[from].filter(
                (w) => w.locked === null || ids.includes(w.id),
            );
            const results = ids
                .map((id) => rosters[from].find((w) => w.id === id))
                .filter((w): w is Row => w !== undefined);

            if (skip.current || results.length === 0) {
                writePreference(tagKey(tag), 'seen');

                return false;
            }

            setTagQueue(
                results.map((result, i) => ({
                    tag,
                    pool,
                    result,
                    n: i + 1,
                    of: results.length,
                })),
            );

            return true;
        };

        if (
            series.immunity_brand === rival &&
            reveal('immunity', rival, series.exemption_wrestler_ids)
        ) {
            return;
        }

        if (series.winner_brand === rival) {
            reveal('manual', player, series.handpick_wrestler_ids);
        }
    }, [
        rulesOpen,
        tagQueue.length,
        running,
        series.exemption_wrestler_ids,
        series.handpick_wrestler_ids,
    ]);

    const tagRevealed = async (id: number) => {
        // The tag's last superstar: it's been shown.
        if (tagReel && tagQueue.length === 1) {
            writePreference(tagKey(tagReel.tag), 'seen');
        }

        setTagQueue((queue) => queue.slice(1));
        rows.current.get(id)?.scrollIntoView?.({
            block: 'center',
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
        setHighlight({ id, brand: rival });
        await sleep(1200);
        setHighlight(null);
    };

    const all = [...rosters.raw, ...rosters.smackdown];
    const nameOf = (id: number | null) =>
        all.find((w) => w.id === id)?.name ?? 'nobody';
    const chosen = choice
        .map((id) => all.find((w) => w.id === id))
        .filter((w): w is Row => w !== undefined);
    const chosenNames = joinNames(chosen.map((w) => w.name));
    // How many the tag being used takes (house rule, 2026-11-17).
    const needed =
        phase === 'immunity'
            ? series.immunity_needed
            : phase === 'manual'
              ? series.manual_needed
              : 0;
    const ready = choice.length === needed && needed > 0;
    // Which list the player chooses from right now, if any.
    const choosing: Brand | null =
        phase === 'immunity'
            ? player
            : phase === 'manual'
              ? series.winner_brand === 'raw'
                  ? 'smackdown'
                  : 'raw'
              : null;

    // Steps 1 and 2 (house rule, 2026-11-17): the side you aren't choosing
    // from, its brand card and its roster, is greyed out: the rival's while
    // you protect one of yours, your own while you pick from the rival.
    const dimmed = (brand: Brand) => choosing !== null && brand !== choosing;

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

        await sleep(fast ? 0 : 1000);
        setPicks((current) => [...current, pick]);
        setFlash(pick.pick_number);

        if (id !== null) {
            setRosters((current) => {
                const moving = current[pick.from].find((w) => w.id === id);

                if (!moving) {
                    return current;
                }

                return {
                    ...current,
                    [pick.from]: current[pick.from].filter((w) => w.id !== id),
                    [pick.brand]: [
                        { ...moving, brand: pick.brand, locked: 'Drafted' },
                        ...current[pick.brand],
                    ],
                };
            });
        }

        setHighlight(null);
        await sleep(fast ? 0 : 450);
    }, []);

    // Spin the reel over everyone the pick could have been (the other
    // brand's superstars still unlocked), then stop on the drafted one. The
    // manual pick was chosen, not drawn, so it has no reel; Skip animation
    // skips it too.
    const revealPick = useCallback(async (pick: Pick) => {
        const id = pick.save_wrestler_id;

        if (skip.current || pick.pick_type !== 'random' || id === null) {
            return;
        }

        const pool = rostersRef.current[pick.from].filter(
            (w) => w.locked === null,
        );
        const result = pool.find((w) => w.id === id);

        if (!result) {
            return;
        }

        await new Promise<void>((resolve) => {
            reelDone.current = resolve;
            setReel({ pool, result, pick });
        });
        setReel(null);
    }, []);

    const runPicks = useCallback(async () => {
        if (started.current) {
            return;
        }

        started.current = true;
        setRunning(true);

        try {
            for (;;) {
                const step = await requestJson<{
                    pick: Pick | null;
                    done: boolean;
                }>('POST', gm.redraft.step.url(game.id));

                if (step.pick) {
                    await revealPick(step.pick);
                    await animatePick(step.pick);
                }

                if (step.done || !step.pick) {
                    break;
                }
            }

            setPhase('done');
        } finally {
            setRunning(false);
            setFlash(null);
        }
    }, [animatePick, revealPick, game.id]);

    const pickNumber = picks.length;
    const nextBrand = order[pickNumber] ?? null;
    const madeBy = (brand: Brand) =>
        picks.filter((p) => p.brand === brand && !p.skipped).length;

    return (
        <>
            <Head title="WrestleMania Re-Draft" />
            <PageTitle
                kicker={`Season ${series.season} is over`}
                title="WrestleMania Re-Draft"
            />

            <div className="mb-6">
                <button
                    type="button"
                    className="gm-btn gm-btn-sm gm-btn-ghost"
                    onClick={() => setRulesOpen(true)}
                    disabled={running}
                    data-testid="redraft-rules-button"
                >
                    <BookOpen className="size-4" aria-hidden />
                    Post-WrestleMania Season {series.season} draft
                </button>
            </div>

            <RedraftRulesModal
                open={rulesOpen && confirming === null && reel === null}
                onClose={() => {
                    setRulesOpen(false);
                    writePreference(rulesKey, 'seen');
                }}
                series={series}
                perBrand={per_brand}
            />

            <div className="mb-6 grid gap-4 md:grid-cols-2">
                {brands.map((brand) => (
                    <TagCard
                        key={brand}
                        dimmed={dimmed(brand)}
                        brand={brand}
                        you={brand === player}
                        immunity={series.immunity_brand === brand}
                        manual={series.winner_brand === brand}
                        protectedName={
                            series.immunity_brand === brand &&
                            series.exemption_wrestler_ids.length > 0
                                ? series.exemption_wrestler_ids
                                      .map(nameOf)
                                      .join(', ')
                                : null
                        }
                        handpickName={
                            series.winner_brand === brand &&
                            series.handpick_wrestler_ids.length > 0
                                ? series.handpick_wrestler_ids
                                      .map(nameOf)
                                      .join(', ')
                                : null
                        }
                        made={madeBy(brand)}
                        perBrand={per_brand}
                        drafting={running && nextBrand === brand}
                    />
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {brands.map((brand) => (
                    <div
                        key={brand}
                        data-brand-tint={brand}
                        data-testid={`redraft-side-${brand}`}
                        className={cn(
                            'min-w-0 transition-opacity',
                            dimmed(brand) && 'opacity-50',
                        )}
                    >
                        <RosterColumn
                            brand={brand}
                            rows={rosters[brand]}
                            config={game.fatigue_config}
                            choosable={choosing === brand}
                            chosen={choice}
                            max={needed}
                            onChoose={(id) =>
                                setChoice((current) =>
                                    current.includes(id)
                                        ? current.filter((c) => c !== id)
                                        : current.length < needed
                                          ? [...current, id]
                                          : current,
                                )
                            }
                            highlight={highlight}
                            rowRef={(id, el) => {
                                if (el) {
                                    rows.current.set(id, el);
                                } else {
                                    rows.current.delete(id);
                                }
                            }}
                        />
                    </div>
                ))}
            </div>

            {picks.length > 0 && (
                <Panel
                    title="Re-draft picks"
                    className="mt-6"
                    bodyClassName="p-0"
                >
                    <ol
                        data-testid="redraft-picks"
                        className="divide-y divide-white/5 text-sm"
                    >
                        {picks.map((p) => (
                            <li
                                key={p.pick_number}
                                data-brand-tint={p.brand}
                                className={cn(
                                    'flex flex-wrap items-center gap-3 px-4 py-2',
                                    p.pick_number === flash &&
                                        'animate-gm-rise',
                                )}
                            >
                                <span className="gm-num w-8 text-ink-faint">
                                    #{p.pick_number}
                                </span>
                                {/* A fixed box: the logos differ in width, so names line up. */}
                                <span
                                    data-testid="redraft-pick-logo"
                                    className="flex w-20 shrink-0 justify-center"
                                >
                                    <BrandBug brand={p.brand} size="sm" />
                                </span>
                                <span className="min-w-0 flex-1 font-medium">
                                    {p.skipped ? (
                                        <span className="text-ink-faint">
                                            No one left to pick
                                        </span>
                                    ) : (
                                        <>
                                            {p.name}
                                            <span className="ml-2 text-xs text-ink-dim">
                                                from {brandName(p.from)}
                                            </span>
                                        </>
                                    )}
                                </span>
                                {p.pick_type === 'manual' && (
                                    <span className="gm-label text-gold-hot">
                                        Manual pick
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </Panel>
            )}

            {/* What to do next stays in view, like the trade page's offer bar. */}
            <section
                aria-label="Re-draft step"
                className="gm-panel sticky bottom-0 z-10 mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 !bg-arena-2 px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-12px_24px_rgb(0_0_0/0.6)]"
            >
                {/* The progress line is what's announced, pick by pick (UI
                    audit, 2026-11-20), not the whole tag cards. */}
                <p
                    data-testid="redraft-step"
                    aria-live="polite"
                    className="min-w-0 flex-1 text-sm text-ink-dim"
                >
                    {phase === 'immunity' &&
                        (ready
                            ? `Exempt ${chosenNames} from this draft.`
                            : `Step 1: you hold the immunity tag. Choose ${pluralize(needed, 'superstar')} of yours to exempt from being chosen (${choice.length} of ${needed} chosen).`)}
                    {phase === 'manual' &&
                        (ready
                            ? `Choose ${chosenNames} from ${brandName(choosing ?? 'raw')} as your first ${needed === 1 ? 'pick' : pluralize(needed, 'pick')}.`
                            : `Step 2: you hold the manual draft pick tag. Choose ${pluralize(needed, 'superstar')} from ${brandName(choosing ?? 'raw')} that will replace your random ${needed === 1 ? 'pick' : 'picks'} with manual ${needed === 1 ? 'pick' : 'picks'} (${choice.length} of ${needed} chosen).`)}
                    {phase === 'picks' &&
                        (running
                            ? `Pick ${Math.min(pickNumber + 1, order.length)} of ${order.length}: ${brandName(nextBrand ?? 'raw')} is drafting…`
                            : `Both tags are now set. ${pluralize(per_brand, 'pick')} each. ${brandName(order[0] ?? 'raw')} goes first.`)}
                    {phase === 'done' && 'The draft is over.'}
                </p>

                {phase === 'picks' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        aria-pressed={skipAnimation}
                        onClick={() => {
                            const next = !skipAnimation;
                            setSkipAnimation(next);
                            skip.current = next;
                            writePreference(
                                SKIP_ANIMATION_KEY,
                                next ? 'on' : 'off',
                            );
                        }}
                    >
                        Skip animation
                        {/* aria-pressed carries the state; On / Off is
                            visual only (UI audit, 2026-11-20). */}
                        <span aria-hidden>
                            : {skipAnimation ? 'On' : 'Off'}
                        </span>
                    </button>
                )}

                {phase === 'immunity' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={!ready || busy !== null}
                        onClick={() => setConfirming('immunity')}
                    >
                        Use the immunity tag
                    </button>
                )}

                {phase === 'manual' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={!ready || busy !== null}
                        onClick={() => setConfirming('handpick')}
                    >
                        Use the manual pick
                    </button>
                )}

                {phase === 'picks' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={running}
                        aria-busy={running || undefined}
                        onClick={() => void runPicks()}
                    >
                        <BusyLabel busy={running} busyText="Drafting…">
                            Start the Post-WrestleMania Season {series.season}{' '}
                            Draft
                        </BusyLabel>
                    </button>
                )}

                {phase === 'done' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={busy !== null}
                        aria-busy={busy === 'finish' || undefined}
                        onClick={() =>
                            router.post(
                                gm.redraft.finish.url(game.id),
                                {},
                                track('finish'),
                            )
                        }
                    >
                        <BusyLabel
                            busy={busy === 'finish'}
                            busyText="Writing the news…"
                        >
                            Continue
                        </BusyLabel>
                    </button>
                )}
            </section>

            {reel && (
                <SuperstarReel
                    pool={reel.pool}
                    result={reel.result}
                    brand={reel.pick.brand}
                    pickNumber={reel.pick.pick_number}
                    onDone={() => reelDone.current?.()}
                />
            )}

            {tagReel && (
                <SuperstarReel
                    key={`${tagReel.tag}-${tagReel.result.id}`}
                    pool={tagReel.pool}
                    result={tagReel.result}
                    brand={rival}
                    heading={`${
                        tagReel.tag === 'immunity'
                            ? 'Immunity tag'
                            : 'Manual draft pick tag'
                    }${tagReel.of > 1 ? ` · ${tagReel.n} of ${tagReel.of}` : ''}`}
                    spinningText={
                        tagReel.tag === 'immunity'
                            ? `${brandName(rival)} is choosing who to protect…`
                            : `${brandName(rival)} is making its manual pick…`
                    }
                    landedText={
                        tagReel.tag === 'immunity'
                            ? `${brandName(rival)} protects ${tagReel.result.name}!`
                            : `${brandName(rival)} takes ${tagReel.result.name} with the manual draft pick!`
                    }
                    onDone={() => void tagRevealed(tagReel.result.id)}
                />
            )}

            <ConfirmDialog
                open={confirming === 'immunity' && ready}
                title="Use the immunity tag?"
                message={
                    ready && (
                        <>
                            <strong>{chosenNames}</strong> will be exempted and
                            will stay on {brandName(player)}: the other brand
                            can’t draft them in this re-draft. You won’t be able
                            to change this after confirming.
                        </>
                    )
                }
                confirmLabel={
                    chosen.length === 1
                        ? `Exempt ${chosen[0]?.name ?? ''}`
                        : `Exempt ${pluralize(chosen.length, 'superstar')}`
                }
                busy={busy === 'immunity'}
                busyText="Exempting…"
                onCancel={() => setConfirming(null)}
                onConfirm={() =>
                    ready &&
                    router.post(
                        gm.redraft.immunity.url(game.id),
                        { save_wrestler_ids: choice },
                        {
                            preserveScroll: true,
                            ...track('immunity'),
                            onSuccess: () => setConfirming(null),
                        },
                    )
                }
            />

            <ConfirmDialog
                open={confirming === 'handpick' && ready}
                title="Use the manual draft pick?"
                message={
                    ready && (
                        <>
                            <strong>{chosenNames}</strong> will move from{' '}
                            {brandName(choosing ?? 'raw')} to{' '}
                            {brandName(player)} as{' '}
                            {chosen.length === 1
                                ? 'the first pick'
                                : `your first ${pluralize(chosen.length, 'pick')}`}{' '}
                            of the draft. You won’t be able to change this after
                            confirming.
                        </>
                    )
                }
                confirmLabel={
                    chosen.length === 1
                        ? `Draft ${chosen[0]?.name ?? ''}`
                        : `Draft ${pluralize(chosen.length, 'superstar')}`
                }
                busy={busy === 'handpick'}
                busyText="Drafting…"
                onCancel={() => setConfirming(null)}
                onConfirm={() =>
                    ready &&
                    router.post(
                        gm.redraft.handpick.url(game.id),
                        { save_wrestler_ids: choice },
                        {
                            preserveScroll: true,
                            ...track('handpick'),
                            onSuccess: () => setConfirming(null),
                        },
                    )
                }
            />
        </>
    );
}

/**
 * The Post-WrestleMania Season N draft modal (2026-11-17): the rules and who holds each tag, in
 * a modal rather than a card on the page.
 */
function RedraftRulesModal({
    open,
    onClose,
    series: s,
    perBrand,
}: {
    open: boolean;
    onClose: () => void;
    series: Props['series'];
    perBrand: number;
}) {
    return (
        <ConfirmDialog
            open={open}
            title={`Post-WrestleMania Season ${s.season} draft`}
            cancelLabel="Got it"
            onCancel={onClose}
            wide
            message={
                <div
                    className="grid gap-y-4 text-sm"
                    data-testid="redraft-rules"
                >
                    <p className="text-ink" data-testid="redraft-welcome">
                        Welcome to the Post-WrestleMania draft! As the season
                        closes, there will be a major shake-up that happens
                        every year when the biggest PPV of the calendar
                        concludes. This means that both brands get their rosters
                        shaken up and that means a draft takes place. Here are
                        the rules:
                    </p>
                    <ul className="list-disc space-y-1 pl-5 text-ink-dim">
                        <li>
                            Each brand drafts {pluralize(perBrand, 'superstar')}{' '}
                            from the other, one pick at a time, taking turns.
                            Every pick is a swap, so no roster cap applies.
                        </li>
                        <li>
                            Picks are random from the other brand’s
                            non-champions. Champions stay put, and contracts
                            move with the superstar.
                        </li>
                        <li>
                            The manual pick’s brand goes first, and its first{' '}
                            {s.manual_needed === 1
                                ? 'pick is its own choice'
                                : `${pluralize(s.manual_needed, 'pick')} are its own choice`}
                            .
                        </li>
                    </ul>
                    <ul className="space-y-2 text-ink-dim">
                        <li data-testid="immunity-tag">
                            <span className="gm-label text-gold-hot">
                                Immunity tag
                            </span>{' '}
                            {s.immunity_brand ? (
                                <>
                                    This tag is awarded to{' '}
                                    <strong className="text-ink">
                                        {brandName(s.immunity_brand)}
                                    </strong>{' '}
                                    for drawing more fans to its weekly shows
                                    before WrestleMania (Raw{' '}
                                    {s.crowds.raw.toLocaleString('en-US')} vs.
                                    Smackdown{' '}
                                    {s.crowds.smackdown.toLocaleString('en-US')}
                                    ). This tag enables the winning brand to
                                    exempt{' '}
                                    {pluralize(
                                        s.immunity_needed,
                                        'superstar',
                                    )}{' '}
                                    of its own from being chosen.
                                </>
                            ) : (
                                'Nobody holds it this year.'
                            )}
                        </li>
                        <li data-testid="manual-tag">
                            <span className="gm-label text-gold-hot">
                                Manual draft pick tag
                            </span>{' '}
                            {s.winner_brand ? (
                                <>
                                    This tag is awarded to{' '}
                                    <strong className="text-ink">
                                        {brandName(s.winner_brand)}
                                    </strong>{' '}
                                    for winning the WrestleMania interbrand
                                    tournament with a score of{' '}
                                    {Math.max(s.raw_wins, s.smackdown_wins)}–
                                    {Math.min(s.raw_wins, s.smackdown_wins)}.{' '}
                                    This tag enables the winning brand to
                                    replace its first{' '}
                                    {pluralize(s.manual_needed, 'random pick')}{' '}
                                    with manual picks of its own choosing.
                                </>
                            ) : (
                                'The series had no winner, so every pick is random.'
                            )}
                        </li>
                    </ul>
                </div>
            }
        />
    );
}

function TagCard({
    brand,
    you,
    immunity,
    manual,
    dimmed,
    protectedName,
    handpickName,
    made,
    perBrand,
    drafting,
}: {
    brand: Brand;
    you: boolean;
    immunity: boolean;
    manual: boolean;
    /** Greyed out: the side not being chosen from in Step 1 or 2. */
    dimmed: boolean;
    /** Who the brand protected with the immunity tag, once chosen. */
    protectedName: string | null;
    /** Who the brand took with the manual draft pick, once chosen. */
    handpickName: string | null;
    made: number;
    perBrand: number;
    drafting: boolean;
}) {
    return (
        <div
            data-brand-tint={brand}
            data-testid={`tag-card-${brand}`}
            className={cn(
                'gm-panel flex items-center justify-between gap-4 p-4 transition-opacity',
                drafting && 'ring-2 ring-brand',
                dimmed && 'opacity-50',
            )}
        >
            <div className="min-w-0">
                <BrandBug brand={brand} size="sm" />
                <div className="gm-label mt-2">
                    {you ? 'Your brand' : 'Rival GM'}
                </div>
                <div className="mt-1 flex flex-wrap gap-2 text-xs">
                    {immunity && (
                        <span className="bg-gold/15 px-2 py-0.5 text-gold-hot">
                            Immunity tag
                        </span>
                    )}
                    {manual && (
                        <span className="bg-gold/15 px-2 py-0.5 text-gold-hot">
                            Manual draft pick tag
                        </span>
                    )}
                    {!immunity && !manual && (
                        <span className="text-ink-faint">No tags</span>
                    )}
                </div>
                {(protectedName || handpickName) && (
                    <ul className="mt-2 space-y-1 text-sm text-ink-dim">
                        {protectedName && (
                            <li data-testid={`tag-chosen-immunity-${brand}`}>
                                <span className="text-gold-hot">
                                    Immunity tag:
                                </span>{' '}
                                {brandName(brand)} has chosen{' '}
                                <strong className="text-ink">
                                    {protectedName}
                                </strong>{' '}
                                to be exempted from the draft
                            </li>
                        )}
                        {handpickName && (
                            <li data-testid={`tag-chosen-manual-${brand}`}>
                                <span className="text-gold-hot">
                                    Manual draft pick tag:
                                </span>{' '}
                                {brandName(brand)} has chosen{' '}
                                <strong className="text-ink">
                                    {handpickName}
                                </strong>{' '}
                                {handpickName.includes(', ')
                                    ? `as its first ${pluralize(handpickName.split(', ').length, 'pick')}, replacing the usual random picks`
                                    : 'as its 1st pick, replacing the usual random pick'}
                            </li>
                        )}
                    </ul>
                )}
            </div>
            <div className="gm-num text-3xl">
                {made}
                <span className="text-lg text-ink-faint">/{perBrand}</span>
                <div className="gm-label text-right">Picks</div>
            </div>
        </div>
    );
}

function RosterColumn({
    brand,
    rows,
    config,
    choosable,
    chosen,
    max,
    onChoose,
    highlight,
    rowRef,
}: {
    brand: Brand;
    rows: Row[];
    config: FatigueConfig;
    choosable: boolean;
    /** The superstars ticked so far. */
    chosen: number[];
    /** How many can be ticked. */
    max: number;
    onChoose: (id: number) => void;
    highlight: { id: number; brand: Brand } | null;
    rowRef: (id: number, el: HTMLLIElement | null) => void;
}) {
    const [search, setSearch] = useState('');
    const q = search.trim().toLowerCase();
    const visible = rows.filter((w) => w.name.toLowerCase().includes(q));

    return (
        <Panel
            title={`${brandName(brand)} · ${rows.length}`}
            bodyClassName="p-0"
            actions={
                <input
                    type="search"
                    name={`search_${brand}`}
                    autoComplete="off"
                    spellCheck={false}
                    className="gm-input min-h-11 w-36 py-1 text-sm sm:min-h-0"
                    placeholder="Search…"
                    aria-label={`Search ${brandName(brand)}`}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            }
        >
            <ul
                data-testid={`redraft-roster-${brand}`}
                className="gm-scroll max-h-[60vh] divide-y divide-white/5 overflow-y-auto"
            >
                {visible.length === 0 && (
                    <li className="px-4 py-3 text-sm text-ink-faint">
                        No one matches “{search}”.
                    </li>
                )}
                {visible.map((w) => {
                    const checked = chosen.includes(w.id);
                    const disabled =
                        !choosable ||
                        w.locked !== null ||
                        (!checked && chosen.length >= max);

                    return (
                        <li
                            key={w.id}
                            ref={(el) => rowRef(w.id, el)}
                            data-testid={`redraft-row-${w.id}`}
                            data-highlight={
                                highlight?.id === w.id
                                    ? highlight.brand
                                    : undefined
                            }
                            className="gm-row-highlight"
                        >
                            <label
                                className={cn(
                                    'flex items-center gap-3 px-4 py-2 text-sm',
                                    choosable &&
                                        (disabled
                                            ? 'cursor-not-allowed'
                                            : 'cursor-pointer hover:bg-white/[0.03]'),
                                    checked && 'bg-brand/12',
                                )}
                            >
                                {choosable && (
                                    <input
                                        type="checkbox"
                                        name={`redraft-${brand}`}
                                        checked={checked}
                                        disabled={disabled}
                                        onChange={() => onChoose(w.id)}
                                        className="size-4 shrink-0 accent-[var(--gm-brand)]"
                                    />
                                )}
                                <span
                                    className={cn(
                                        'min-w-0 flex-1',
                                        choosable && disabled && 'opacity-60',
                                    )}
                                >
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 font-medium">
                                        <WrestlerName
                                            wrestler={w}
                                            portrait="md"
                                        />
                                        {w.titles.map((t) => (
                                            <TitleBelt key={t} name={t} />
                                        ))}
                                    </span>
                                    <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-dim">
                                        <FatigueMeter
                                            fatigue={w.fatigue}
                                            config={config}
                                        />
                                        <span className="gm-num">
                                            {w.wins}–{w.losses}
                                        </span>
                                        {/* Like Propose Trade (2026-11-16): the contract moves with them. */}
                                        {w.contract && (
                                            <span className="gm-num">
                                                {w.contract.weeks_remaining}w
                                                left
                                            </span>
                                        )}
                                    </span>
                                </span>
                                {w.locked ? (
                                    <span
                                        className={cn(
                                            'shrink-0 text-right text-xs',
                                            w.locked === 'Drafted'
                                                ? 'text-gold-hot'
                                                : 'text-ink-dim',
                                        )}
                                    >
                                        {w.locked}
                                    </span>
                                ) : (
                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        <InjuryChip injury={w.injury} />
                                        <MoraleBadge morale={w.morale} />
                                    </span>
                                )}
                            </label>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}
