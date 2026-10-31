import { Head, router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { InjuryChip, MoraleBadge, TitleBelt } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
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

type Props = GamePageProps & {
    yours: TradeRow[];
    theirs: TradeRow[];
    roster_counts: Record<string, number>;
    max_per_side: number;
    history: Array<{
        id: number;
        week_number: number;
        status: TradeStatus;
        out: string[];
        in: string[];
    }>;
};

export default function ProposeTrade() {
    const { game, yours, theirs, roster_counts, max_per_side, history } =
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
    // Why the offer can't go yet, or what happens when it does.
    const hint = empty
        ? `Pick up to ${max_per_side} wrestlers on each side. Uneven trades are fine.`
        : yourSize > game.roster_cap
          ? `Your roster would end at ${yourSize}/${game.roster_cap}. Send more or receive fewer.`
          : theirSize > game.roster_cap
            ? `Their roster would end at ${theirSize}/${game.roster_cap}. Ask for more or send fewer.`
            : `${out.length} for ${incoming.length}. The rival GM answers by email.`;
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
                className="gm-panel sticky bottom-2 z-10 mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 !bg-arena-2 px-4 py-3 shadow-[0_-8px_24px_rgb(0_0_0/0.5)]"
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
                    <p
                        id="offer-hint"
                        className={cn(
                            'text-ink-dim',
                            overCap && 'text-zone-serious',
                        )}
                    >
                        {hint}
                    </p>
                </div>
                <button
                    type="button"
                    className="gm-btn"
                    disabled={empty || overCap}
                    aria-describedby="offer-hint"
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
