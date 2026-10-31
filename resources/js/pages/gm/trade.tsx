import { Head, router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { InjuryChip, MoraleBadge, TitleBelt } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { TradeStatus } from '@/types/enums';
import {
    MatchFamilyLabels,
    RosterTypeLabels,
    TradeStatusLabels,
} from '@/types/enums';
import type { FatigueConfig } from '@/types/gm';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type TradeRow = WrestlerRow & { untradeable: string | null };

/** How the rival GM values wrestlers in a trade (`gm.ai.trades`). */
type TradeJudge = {
    base_value: number;
    legend_value: number;
    focus_value: number;
    specialty_value: number;
    minor_injury_value: number;
    win_streak_step: number;
    win_streak_value: number;
    loss_streak_from: number;
    loss_streak_value: number;
    tag_partner_value: number;
    full_tag_team_value: number;
    full_stable_value: number;
    /** Reigns with their own brand's world title (from) => points. */
    world_reign_bands: Record<string, number>;
    repeat_title_reigns: number;
    repeat_title_value: number;
    fair_ratio: number;
    fair_accept: number;
    /** Points the rival comes out ahead (from) => chance for a fair offer. */
    fair_ahead_accept: Record<string, number>;
    unfair_accept: number;
};

type PointCategory =
    | 'base'
    | 'legend'
    | 'focus'
    | 'specialty'
    | 'minor_injury'
    | 'win_streak'
    | 'loss_streak'
    | 'tag_partner'
    | 'full_tag_team'
    | 'full_stable'
    | 'world_champion'
    | 'repeat_champion';

/** One side's worth to the rival, by category (AiTradeJudge::assess). */
type SidePoints = {
    total: number;
    lines: Partial<Record<PointCategory, number>> | [];
};

type Assessment = {
    chance: number;
    fair: boolean;
    gets: SidePoints;
    gives: SidePoints;
};

type Props = GamePageProps & {
    yours: TradeRow[];
    theirs: TradeRow[];
    roster_counts: Record<string, number>;
    max_per_side: number;
    /** How the rival GM judges an offer (`gm.ai.trades`). */
    judge?: TradeJudge;
    history: Array<{
        id: number;
        week_number: number;
        status: TradeStatus;
        out: string[];
        in: string[];
    }>;
};

export default function ProposeTrade() {
    const { game, yours, theirs, roster_counts, max_per_side, history, judge } =
        usePage<Props>().props;
    const [out, setOut] = useState<number[]>([]);
    const [incoming, setIncoming] = useState<number[]>([]);

    const toggle = (list: number[], set: (ids: number[]) => void, id: number) =>
        set(
            list.includes(id)
                ? list.filter((x) => x !== id)
                : list.length < max_per_side
                  ? [...list, id]
                  : list,
        );

    const yourSize =
        roster_counts[game.player_brand] - out.length + incoming.length;
    const theirSize =
        roster_counts[game.rival_brand] - incoming.length + out.length;
    const overCap = yourSize > game.roster_cap || theirSize > game.roster_cap;
    const empty = out.length === 0 && incoming.length === 0;
    // Why the offer can't go yet; nothing once it can.
    const hint = empty
        ? `Pick up to ${max_per_side} wrestlers on each side. Uneven trades are fine.`
        : yourSize > game.roster_cap
          ? `Your roster would end at ${yourSize}/${game.roster_cap}. Send more or receive fewer.`
          : theirSize > game.roster_cap
            ? `Their roster would end at ${theirSize}/${game.roster_cap}. Ask for more or send fewer.`
            : null;
    // The rival's judgement (AiTradeJudge::assess): its chance of accepting
    // and each side's points, refreshed as the offer changes. Null while
    // there's nothing to judge.
    const [assessment, setAssessment] = useState<Assessment | null>(null);
    const chance = assessment?.chance ?? null;

    useEffect(() => {
        if (empty || overCap) {
            setAssessment(null);

            return;
        }

        let current = true;
        requestJson<Assessment>('POST', gm.trade.odds.url(game.id), {
            out,
            in: incoming,
        })
            .then((r) => current && setAssessment(r))
            .catch(() => current && setAssessment(null));

        return () => {
            current = false;
        };
    }, [out, incoming, empty, overCap, game.id]);

    const send = () =>
        router.post(
            gm.trade.store.url(game.id),
            { out, in: incoming },
            { onSuccess: () => (setOut([]), setIncoming([])) },
        );

    return (
        <>
            <Head title="Propose Trade" />
            <PageTitle
                kicker={`Up to ${max_per_side} for ${max_per_side}`}
                title="Propose Trade"
            />

            <TradeRules
                maxPerSide={max_per_side}
                rosterCap={game.roster_cap}
                rivalBrand={game.rival_brand}
                judge={judge}
            />

            <div className="mb-6 grid gap-4 md:grid-cols-2" aria-live="polite">
                <SizeCard
                    brand={game.player_brand}
                    size={yourSize}
                    cap={game.roster_cap}
                    label="Your roster after the trade"
                />
                <SizeCard
                    brand={game.rival_brand}
                    size={theirSize}
                    cap={game.roster_cap}
                    label="Their roster after the trade"
                />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <RosterPicker
                    title="You send"
                    rows={yours}
                    selected={out}
                    max={max_per_side}
                    config={game.fatigue_config}
                    onToggle={(id) => toggle(out, setOut, id)}
                />
                <div data-brand-tint={game.rival_brand} className="min-w-0">
                    <RosterPicker
                        title="You receive"
                        rows={theirs}
                        selected={incoming}
                        max={max_per_side}
                        config={game.fatigue_config}
                        onToggle={(id) => toggle(incoming, setIncoming, id)}
                    />
                </div>
            </div>

            {/* The offer stays in view while picking from either list. */}
            <section
                aria-label="Your offer"
                className="gm-panel sticky bottom-0 z-10 mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 !bg-arena-2 px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-12px_24px_rgb(0_0_0/0.6)]"
            >
                <div className="min-w-0 flex-1 space-y-1 text-sm">
                    <OfferLine
                        label="You send"
                        rows={yours.filter((w) => out.includes(w.id))}
                        onRemove={(id) => toggle(out, setOut, id)}
                    />
                    <OfferLine
                        label="You receive"
                        rows={theirs.filter((w) => incoming.includes(w.id))}
                        onRemove={(id) => toggle(incoming, setIncoming, id)}
                    />
                    {hint && (
                        <p
                            id="offer-hint"
                            className={cn(
                                'text-ink-dim',
                                overCap && 'text-zone-serious',
                            )}
                        >
                            {hint}
                        </p>
                    )}
                    {chance !== null && (
                        <p data-testid="offer-chance" className="text-ink-dim">
                            Chance the rival GM accepts:{' '}
                            <span
                                className={cn(
                                    'gm-num font-semibold',
                                    chance >= HIGH_CHANCE
                                        ? 'text-zone-safe'
                                        : 'text-gm-orange',
                                )}
                            >
                                {percent(chance)}
                            </span>
                        </p>
                    )}
                    {assessment && (
                        <div
                            data-testid="offer-points"
                            className="grid gap-x-8 gap-y-2 pt-1 sm:grid-cols-2"
                        >
                            <OfferPoints
                                label="They get"
                                side={assessment.gets}
                                rivalBrand={game.rival_brand}
                            />
                            <OfferPoints
                                label="They give"
                                side={assessment.gives}
                                rivalBrand={game.rival_brand}
                            />
                        </div>
                    )}
                </div>
                <button
                    type="button"
                    className="gm-btn"
                    disabled={empty || overCap}
                    aria-describedby={hint ? 'offer-hint' : undefined}
                    onClick={send}
                >
                    Send offer
                </button>
            </section>

            {history.length > 0 && (
                <Panel
                    title="Recent offers"
                    className="mt-6"
                    bodyClassName="p-0"
                >
                    <ul className="divide-y divide-white/5 text-sm">
                        {history.map((t) => (
                            <li
                                key={t.id}
                                className="flex flex-wrap justify-between gap-2 px-4 py-2"
                            >
                                <span>
                                    <span className="gm-num mr-2 text-ink-faint">
                                        W{t.week_number}
                                    </span>
                                    {t.out.join(', ') || 'nothing'}{' '}
                                    <span className="text-ink-faint">for</span>{' '}
                                    {t.in.join(', ') || 'nothing'}
                                </span>
                                <span
                                    className={cn(
                                        'gm-label',
                                        t.status === 'accepted'
                                            ? 'text-zone-safe'
                                            : 'text-zone-serious',
                                    )}
                                >
                                    {TradeStatusLabels[t.status]}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}

/** The rival's focus class, worth extra to it in a trade (AiGm::isDraftFocus). */
const FOCUS_LABEL: Record<'raw' | 'smackdown', string> = {
    raw: 'A Diva',
    smackdown: 'A Cruiserweight or Light Heavyweight',
};

/** "+2" in the ink colour, "−1" in red. */
function PointValue({ value }: { value: number }) {
    return (
        <span
            className={cn(
                'gm-num font-semibold',
                value < 0 ? 'text-zone-serious' : 'text-zone-safe',
            )}
        >
            {signed(value)}
        </span>
    );
}

/** A nested list of "what: points" rule lines. */
function PointList({ items }: { items: Array<[string, number]> }) {
    return (
        <ul className="mt-1 list-[circle] space-y-1 pl-5 marker:text-ink-faint">
            {items.map(([label, value]) => (
                <li key={label}>
                    {label}: <PointValue value={value} />
                </li>
            ))}
        </ul>
    );
}

const FOCUS_CLASS: Record<'raw' | 'smackdown', string> = {
    raw: 'Divas',
    smackdown: 'Cruiserweights and Light Heavyweights',
};

const percent = (chance: number) => `${Math.round(chance * 100)}%`;

/** Acceptance chances at or above this show green, anything lower orange. */
const HIGH_CHANCE = 0.9;

/** "+2" / "−1": a signed point value. */
const signed = (n: number) => `${n < 0 ? '−' : '+'}${Math.abs(n)}`;

const points = (n: number) =>
    `${signed(n)} ${Math.abs(n) === 1 ? 'point' : 'points'}`;

const POINT_LABELS: Record<PointCategory, string> = {
    base: 'Wrestlers',
    legend: 'Legends',
    focus: 'Their focus class',
    specialty: 'Match specialists',
    minor_injury: 'Minor injuries',
    win_streak: 'Win streaks',
    loss_streak: 'Losing streaks',
    tag_partner: 'Tag team partners',
    full_tag_team: 'Full tag teams',
    full_stable: 'Full stables',
    world_champion: 'World champions',
    repeat_champion: 'Repeat champions',
};

/** The acceptance rates, one line each: even fair, each band ahead, the rest. */
function acceptLines(judge: TradeJudge): Array<[string, number]> {
    const bands = Object.entries(judge.fair_ahead_accept ?? {})
        .map(([from, chance]) => [Number(from), chance] as const)
        .sort((a, b) => a[0] - b[0]);

    return [
        ['A fair offer with even points', judge.fair_accept],
        ...bands.map(([from, chance], i): [string, number] => {
            const last = i === bands.length - 1 && from > 1;
            const count = last ? `${from} or more` : `${from}`;
            const unit = from === 1 && !last ? 'point' : 'points';

            return [`They come out ${count} ${unit} ahead`, chance];
        }),
        ['Anything else', judge.unfair_accept],
    ];
}

/** "+1 for 1–4 reigns, +2 for 5–7, +3 for 8 or more". */
function worldBands(bands: Record<string, number>): string {
    const entries = Object.entries(bands)
        .map(([from, value]) => [Number(from), value] as const)
        .sort((a, b) => a[0] - b[0]);

    return entries
        .map(([from, value], i) => {
            const next = entries[i + 1]?.[0];
            const range =
                next === undefined
                    ? `${from} or more`
                    : next - 1 === from
                      ? `${from}`
                      : `${from}–${next - 1}`;

            return `${signed(value)} for ${range}${i === 0 ? ' reigns' : ''}`;
        })
        .join(', ');
}

/** One side of the offer: its points to the rival, by category. */
function OfferPoints({
    label,
    side,
    rivalBrand,
}: {
    label: string;
    side: SidePoints;
    rivalBrand: 'raw' | 'smackdown';
}) {
    const lines = Object.entries(side.lines) as Array<[PointCategory, number]>;

    return (
        <div
            data-testid={`offer-points-${label === 'They get' ? 'gets' : 'gives'}`}
        >
            <p className="text-ink-dim">
                {label}:{' '}
                <span className="gm-num font-semibold text-ink">
                    {side.total}{' '}
                    {Math.abs(side.total) === 1 ? 'point' : 'points'}
                </span>
            </p>
            {lines.length > 0 && (
                <ol className="list-decimal pl-5 text-xs text-ink-dim marker:text-ink-faint">
                    {lines.map(([category, value]) => (
                        <li key={category}>
                            {category === 'focus'
                                ? `${POINT_LABELS.focus} (${FOCUS_CLASS[rivalBrand]})`
                                : POINT_LABELS[category]}
                            :{' '}
                            <span
                                className={cn(
                                    'gm-num',
                                    value < 0
                                        ? 'text-zone-serious'
                                        : 'text-zone-safe',
                                )}
                            >
                                {signed(value)}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

/** The trade rules as the game applies them (TradeService, AiTradeJudge). */
function TradeRules({
    maxPerSide,
    rosterCap,
    rivalBrand,
    judge,
}: {
    maxPerSide: number;
    rosterCap: number;
    rivalBrand: 'raw' | 'smackdown';
    judge?: TradeJudge;
}) {
    return (
        <Panel title="How trades work" className="mb-6">
            <div className="grid gap-x-8 gap-y-4 text-sm md:grid-cols-2">
                <ul className="list-disc space-y-1.5 pl-5 marker:text-ink-faint">
                    <li>
                        Up to {maxPerSide} wrestlers on each side. Uneven trades
                        are fine, even someone for nobody.
                    </li>
                    <li>
                        Champions and seriously injured superstars can’t be
                        traded.
                    </li>
                    <li>Neither roster can end up over {rosterCap}.</li>
                    <li>You can’t trade while a week is airing.</li>
                    <li>
                        The rival GM answers by email straight away. An accepted
                        trade moves everyone at once, and their contracts go
                        with them.
                    </li>
                    <li>
                        A traded Money in the Bank holder loses the briefcase,
                        and a traded #1 contender loses the title shot.
                    </li>
                </ul>
                {judge && (
                    <div data-testid="trade-judge">
                        <p className="mb-1.5 text-ink-dim">
                            How the rival GM weighs an offer:
                        </p>
                        <ul className="list-disc space-y-1.5 pl-5 marker:text-ink-faint">
                            <li>
                                Every wrestler starts at{' '}
                                {points(judge.base_value).slice(1)}, then:
                                <PointList
                                    items={[
                                        ['A Legend', judge.legend_value],
                                        [
                                            FOCUS_LABEL[rivalBrand],
                                            judge.focus_value,
                                        ],
                                        [
                                            'A match specialist',
                                            judge.specialty_value,
                                        ],
                                        [
                                            'Their tag team partner is on the rival’s roster',
                                            judge.tag_partner_value,
                                        ],
                                        [
                                            'A whole tag team traded together',
                                            judge.full_tag_team_value,
                                        ],
                                        [
                                            'A whole stable traded together',
                                            judge.full_stable_value,
                                        ],
                                    ]}
                                />
                            </li>
                            <li>
                                Form:
                                <PointList
                                    items={[
                                        [
                                            `Every ${judge.win_streak_step} wins in a row`,
                                            judge.win_streak_value,
                                        ],
                                        [
                                            `A losing streak of ${judge.loss_streak_from} or more`,
                                            judge.loss_streak_value,
                                        ],
                                        [
                                            'A minor injury',
                                            judge.minor_injury_value,
                                        ],
                                    ]}
                                />
                            </li>
                            <li>
                                Title history in this career:
                                <ul className="mt-1 list-[circle] space-y-1 pl-5 marker:text-ink-faint">
                                    <li>
                                        Their own brand’s world title:{' '}
                                        {worldBands(judge.world_reign_bands)}
                                    </li>
                                    <li>
                                        The same other title{' '}
                                        {judge.repeat_title_reigns} or more
                                        times:{' '}
                                        <PointValue
                                            value={judge.repeat_title_value}
                                        />
                                    </li>
                                </ul>
                            </li>
                            <li>
                                An offer is fair when they get{' '}
                                {judge.fair_ratio === 1
                                    ? 'at least as many points as they give'
                                    : `at least ${judge.fair_ratio} times the points they give`}{' '}
                                and can still book every title match they could
                                before.
                            </li>
                            <li>
                                How often they accept:
                                <ul className="mt-1 list-[circle] space-y-1 pl-5 marker:text-ink-faint">
                                    {acceptLines(judge).map(
                                        ([label, chance]) => (
                                            <li key={label}>
                                                {label}:{' '}
                                                <span className="gm-num font-semibold text-ink">
                                                    {percent(chance)}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </li>
                        </ul>
                    </div>
                )}
            </div>
        </Panel>
    );
}

function SizeCard({
    brand,
    size,
    cap,
    label,
}: {
    brand: 'raw' | 'smackdown';
    size: number;
    cap: number;
    label: string;
}) {
    return (
        <div
            data-brand-tint={brand}
            className="gm-panel flex items-center justify-between p-4"
        >
            <div>
                <BrandBug brand={brand} size="sm" />
                <div className="gm-label mt-2">{label}</div>
            </div>
            <div
                className={cn(
                    'gm-num text-3xl',
                    size > cap && 'text-zone-serious',
                )}
            >
                {size}
                <span className="text-lg text-ink-faint">/{cap}</span>
            </div>
        </div>
    );
}

function OfferLine({
    label,
    rows,
    onRemove,
}: {
    label: string;
    rows: TradeRow[];
    onRemove: (id: number) => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="w-24 shrink-0 text-ink-dim">{label}</span>
            {rows.length === 0 ? (
                <span className="text-ink-faint">Nobody</span>
            ) : (
                rows.map((w) => (
                    <span
                        key={w.id}
                        className="inline-flex items-center gap-1 bg-white/[0.06] py-0.5 pr-1 pl-2 font-medium"
                    >
                        <WrestlerName wrestler={w} />
                        <button
                            type="button"
                            onClick={() => onRemove(w.id)}
                            aria-label={`Remove ${w.name}`}
                            className="gm-row-link p-0.5 text-ink-dim hover:text-ink"
                        >
                            <X aria-hidden className="size-3.5" />
                        </button>
                    </span>
                ))
            )}
        </div>
    );
}

function RosterPicker({
    title,
    rows,
    selected,
    max,
    config,
    onToggle,
}: {
    title: string;
    rows: TradeRow[];
    selected: number[];
    max: number;
    config: FatigueConfig;
    onToggle: (id: number) => void;
}) {
    const [search, setSearch] = useState('');
    const q = search.trim().toLowerCase();
    const visible = rows.filter((w) => w.name.toLowerCase().includes(q));

    return (
        <Panel
            title={`${title} · ${selected.length}/${max}`}
            bodyClassName="p-0"
            actions={
                <input
                    type="search"
                    className="gm-input min-h-11 w-36 py-1 text-sm sm:min-h-0"
                    placeholder="Search…"
                    aria-label={`Search: ${title.toLowerCase()}`}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            }
        >
            <ul className="gm-scroll max-h-[60vh] divide-y divide-white/5 overflow-y-auto">
                {visible.length === 0 && (
                    <li className="px-4 py-3 text-sm text-ink-faint">
                        No one matches “{search}”.
                    </li>
                )}
                {visible.map((w) => {
                    const checked = selected.includes(w.id);
                    const disabled =
                        w.untradeable !== null ||
                        (!checked && selected.length >= max);

                    return (
                        <li key={w.id} data-testid={`trade-row-${w.id}`}>
                            <label
                                className={cn(
                                    'flex items-center gap-3 px-4 py-2 text-sm',
                                    disabled
                                        ? 'cursor-not-allowed'
                                        : 'cursor-pointer hover:bg-white/[0.03]',
                                    checked && 'bg-brand/12',
                                )}
                            >
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={disabled}
                                    onChange={() => onToggle(w.id)}
                                    className="size-4 shrink-0 accent-[var(--gm-brand)]"
                                />
                                <span
                                    className={cn(
                                        'min-w-0 flex-1',
                                        disabled && 'opacity-60',
                                    )}
                                >
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 font-medium">
                                        <WrestlerName wrestler={w} />
                                        {w.roster_type !== 'superstar' && (
                                            <span className="font-condensed text-xs tracking-widest text-ink-faint uppercase">
                                                {
                                                    RosterTypeLabels[
                                                        w.roster_type
                                                    ]
                                                }
                                            </span>
                                        )}
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
                                        {w.contract && (
                                            <span className="gm-num">
                                                {w.contract.weeks_remaining}w
                                                left
                                            </span>
                                        )}
                                        {w.specialties.length > 0 && (
                                            <span>
                                                {w.specialties
                                                    .map(
                                                        (f) =>
                                                            MatchFamilyLabels[
                                                                f
                                                            ],
                                                    )
                                                    .join(', ')}
                                            </span>
                                        )}
                                    </span>
                                </span>
                                {w.untradeable ? (
                                    <span className="shrink-0 text-right text-xs text-ink-dim">
                                        {w.untradeable}:
                                        <br />
                                        can’t be traded
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
