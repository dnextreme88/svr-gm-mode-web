import { Head, router, usePage } from '@inertiajs/react';
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
        raw_wins: number;
        smackdown_wins: number;
        winner_brand: Brand | null;
        immunity_brand: Brand | null;
        exemption_wrestler_id: number | null;
        handpick_wrestler_id: number | null;
        crowds: Record<Brand, number>;
    };
    rosters: Record<Brand, Row[]>;
    order: Brand[];
    per_brand: number;
    picks: Pick[];
};

const SKIP_ANIMATION_KEY = 'gm.redraft.skip-animation';

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
    const [choice, setChoice] = useState<number | null>(null);
    // Which tag's confirmation is open: the immunity tag or the manual pick.
    const [confirming, setConfirming] = useState<
        'immunity' | 'handpick' | null
    >(null);
    const [running, setRunning] = useState(false);
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
        setChoice(null);
    }, [props.rosters, props.picks, props.phase]);

    const all = [...rosters.raw, ...rosters.smackdown];
    const nameOf = (id: number | null) =>
        all.find((w) => w.id === id)?.name ?? 'nobody';
    const chosen = all.find((w) => w.id === choice) ?? null;
    // Which list the player chooses from right now, if any.
    const choosing: Brand | null =
        phase === 'immunity'
            ? player
            : phase === 'manual'
              ? series.winner_brand === 'raw'
                  ? 'smackdown'
                  : 'raw'
              : null;

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

            <RedraftRules
                series={series}
                perBrand={per_brand}
                nameOf={nameOf}
            />

            <div className="mb-6 grid gap-4 md:grid-cols-2" aria-live="polite">
                {brands.map((brand) => (
                    <TagCard
                        key={brand}
                        brand={brand}
                        you={brand === player}
                        immunity={series.immunity_brand === brand}
                        manual={series.winner_brand === brand}
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
                        className="min-w-0"
                    >
                        <RosterColumn
                            brand={brand}
                            rows={rosters[brand]}
                            config={game.fatigue_config}
                            choosable={choosing === brand}
                            chosen={choice}
                            onChoose={setChoice}
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
                <p
                    data-testid="redraft-step"
                    className="min-w-0 flex-1 text-sm text-ink-dim"
                >
                    {phase === 'immunity' &&
                        (chosen
                            ? `Protect ${chosen.name} from the re-draft.`
                            : 'Step 1: you hold the immunity tag. Choose one of your superstars to protect.')}
                    {phase === 'manual' &&
                        (chosen
                            ? `Take ${chosen.name} from ${brandName(chosen.brand ?? 'raw')} with the first pick.`
                            : `Step 2: you hold the manual draft pick. Choose a superstar from ${brandName(choosing ?? 'raw')}.`)}
                    {phase === 'picks' &&
                        (running
                            ? `Pick ${Math.min(pickNumber + 1, order.length)} of ${order.length}: ${brandName(nextBrand ?? 'raw')} is drafting…`
                            : `Both tags are set. ${pluralize(per_brand, 'pick')} each, ${brandName(order[0] ?? 'raw')} first.`)}
                    {phase === 'done' &&
                        'The re-draft is over. Continue to the WrestleMania news.'}
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
                        Skip animation: {skipAnimation ? 'On' : 'Off'}
                    </button>
                )}

                {phase === 'immunity' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={chosen === null || busy !== null}
                        onClick={() => setConfirming('immunity')}
                    >
                        Use the immunity tag
                    </button>
                )}

                {phase === 'manual' && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={chosen === null || busy !== null}
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
                            Start the re-draft
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

            <ConfirmDialog
                open={confirming === 'immunity' && chosen !== null}
                title="Use the immunity tag?"
                message={
                    chosen && (
                        <>
                            <strong>{chosen.name}</strong> is protected and
                            stays on {brandName(player)}: the other brand can’t
                            draft them in this re-draft. This can’t be undone.
                        </>
                    )
                }
                confirmLabel={`Protect ${chosen?.name ?? ''}`}
                busy={busy === 'immunity'}
                busyText="Protecting…"
                onCancel={() => setConfirming(null)}
                onConfirm={() =>
                    chosen &&
                    router.post(
                        gm.redraft.immunity.url(game.id),
                        { save_wrestler_id: chosen.id },
                        {
                            preserveScroll: true,
                            ...track('immunity'),
                            onSuccess: () => setConfirming(null),
                        },
                    )
                }
            />

            <ConfirmDialog
                open={confirming === 'handpick' && chosen !== null}
                title="Use the manual draft pick?"
                message={
                    chosen && (
                        <>
                            <strong>{chosen.name}</strong> moves from{' '}
                            {brandName(chosen.brand ?? 'raw')} to{' '}
                            {brandName(player)} as the first pick of the
                            re-draft. This can’t be undone.
                        </>
                    )
                }
                confirmLabel={`Draft ${chosen?.name ?? ''}`}
                busy={busy === 'handpick'}
                busyText="Drafting…"
                onCancel={() => setConfirming(null)}
                onConfirm={() =>
                    chosen &&
                    router.post(
                        gm.redraft.handpick.url(game.id),
                        { save_wrestler_id: chosen.id },
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

function RedraftRules({
    series: s,
    perBrand,
    nameOf,
}: {
    series: Props['series'];
    perBrand: number;
    nameOf: (id: number | null) => string;
}) {
    return (
        <Panel title="How the re-draft works" className="mb-6">
            <div className="grid gap-x-8 gap-y-4 text-sm md:grid-cols-2">
                <ul className="list-disc space-y-1 pl-5 text-ink-dim">
                    <li>
                        Each brand drafts {pluralize(perBrand, 'superstar')}{' '}
                        from the other, one pick at a time, taking turns. Every
                        pick is a swap, so no roster cap applies.
                    </li>
                    <li>
                        Picks are random from the other brand’s non-champions.
                        Champions stay put, and contracts move with the
                        superstar.
                    </li>
                    <li>
                        The manual pick’s brand goes first, and its first pick
                        is its own choice.
                    </li>
                </ul>
                <ul className="space-y-2 text-ink-dim">
                    <li data-testid="immunity-tag">
                        <span className="gm-label text-gold-hot">
                            Immunity tag
                        </span>{' '}
                        {s.immunity_brand ? (
                            <>
                                <strong className="text-ink">
                                    {brandName(s.immunity_brand)}
                                </strong>{' '}
                                drew more fans to its weekly shows before
                                WrestleMania (Raw{' '}
                                {s.crowds.raw.toLocaleString('en-US')} ·
                                Smackdown{' '}
                                {s.crowds.smackdown.toLocaleString('en-US')}
                                ).{' '}
                                {s.exemption_wrestler_id
                                    ? `It protects ${nameOf(s.exemption_wrestler_id)}.`
                                    : 'It protects one of its own superstars.'}
                            </>
                        ) : (
                            'Nobody holds it this year.'
                        )}
                    </li>
                    <li data-testid="manual-tag">
                        <span className="gm-label text-gold-hot">
                            Manual draft pick
                        </span>{' '}
                        {s.winner_brand ? (
                            <>
                                <strong className="text-ink">
                                    {brandName(s.winner_brand)}
                                </strong>{' '}
                                won the WrestleMania interbrand series{' '}
                                {Math.max(s.raw_wins, s.smackdown_wins)}–
                                {Math.min(s.raw_wins, s.smackdown_wins)}.{' '}
                                {s.handpick_wrestler_id
                                    ? `It takes ${nameOf(s.handpick_wrestler_id)} with the first pick.`
                                    : 'It chooses its first pick.'}
                            </>
                        ) : (
                            'The series had no winner, so every pick is random.'
                        )}
                    </li>
                </ul>
            </div>
        </Panel>
    );
}

function TagCard({
    brand,
    you,
    immunity,
    manual,
    made,
    perBrand,
    drafting,
}: {
    brand: Brand;
    you: boolean;
    immunity: boolean;
    manual: boolean;
    made: number;
    perBrand: number;
    drafting: boolean;
}) {
    return (
        <div
            data-brand-tint={brand}
            data-testid={`tag-card-${brand}`}
            className={cn(
                'gm-panel flex items-center justify-between gap-4 p-4',
                drafting && 'ring-2 ring-brand',
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
                            Manual draft pick
                        </span>
                    )}
                    {!immunity && !manual && (
                        <span className="text-ink-faint">No tags</span>
                    )}
                </div>
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
    onChoose,
    highlight,
    rowRef,
}: {
    brand: Brand;
    rows: Row[];
    config: FatigueConfig;
    choosable: boolean;
    chosen: number | null;
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
                    const disabled = !choosable || w.locked !== null;
                    const checked = chosen === w.id;

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
                                        type="radio"
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
                                        <WrestlerName wrestler={w} />
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
