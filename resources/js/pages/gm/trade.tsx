import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { InjuryChip, MoraleBadge, TitleBelt } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { TradeStatus } from '@/types/enums';
import { TradeStatusLabels } from '@/types/enums';
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

    return (
        <>
            <Head title="Propose Trade" />
            <PageTitle
                kicker={`Up to ${max_per_side} for ${max_per_side}`}
                title="Propose Trade"
            >
                <button
                    type="button"
                    className="gm-btn"
                    disabled={
                        (out.length === 0 && incoming.length === 0) || overCap
                    }
                    onClick={() =>
                        router.post(
                            gm.trade.store.url(game.id),
                            { out, in: incoming },
                            { onSuccess: () => (setOut([]), setIncoming([])) },
                        )
                    }
                >
                    Send offer
                </button>
            </PageTitle>

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

            <div className="grid gap-6 lg:grid-cols-2">
                <RosterPicker
                    title="You send"
                    rows={yours}
                    selected={out}
                    max={max_per_side}
                    onToggle={(id) => toggle(out, setOut, id)}
                />
                <div data-brand-tint={game.rival_brand}>
                    <RosterPicker
                        title="You receive"
                        rows={theirs}
                        selected={incoming}
                        max={max_per_side}
                        onToggle={(id) => toggle(incoming, setIncoming, id)}
                    />
                </div>
            </div>

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

function RosterPicker({
    title,
    rows,
    selected,
    max,
    onToggle,
}: {
    title: string;
    rows: TradeRow[];
    selected: number[];
    max: number;
    onToggle: (id: number) => void;
}) {
    return (
        <Panel
            title={`${title} · ${selected.length}/${max}`}
            bodyClassName="p-0"
        >
            <ul className="gm-scroll max-h-[60vh] divide-y divide-white/5 overflow-y-auto">
                {rows.map((w) => {
                    const checked = selected.includes(w.id);
                    const disabled =
                        w.untradeable !== null ||
                        (!checked && selected.length >= max);

                    return (
                        <li key={w.id}>
                            <label
                                className={cn(
                                    'flex items-center gap-3 px-4 py-2 text-sm',
                                    disabled
                                        ? 'opacity-50'
                                        : 'cursor-pointer hover:bg-white/[0.03]',
                                    checked && 'bg-brand/12',
                                )}
                            >
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={disabled}
                                    onChange={() => onToggle(w.id)}
                                    className="accent-[var(--gm-brand)]"
                                />
                                <span className="flex-1 font-medium">
                                    <WrestlerName wrestler={w} />
                                    {w.titles.map((t) => (
                                        <span key={t} className="ml-2">
                                            <TitleBelt name={t} />
                                        </span>
                                    ))}
                                </span>
                                {w.untradeable ? (
                                    <span className="font-condensed text-xs tracking-wider text-ink-faint uppercase">
                                        {w.untradeable}
                                    </span>
                                ) : (
                                    <>
                                        <InjuryChip injury={w.injury} />
                                        <MoraleBadge morale={w.morale} />
                                    </>
                                )}
                            </label>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}
