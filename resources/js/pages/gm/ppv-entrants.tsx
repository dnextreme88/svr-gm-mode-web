import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { InjuryChip, MoraleBadge } from '@/components/gm/badges';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { BookingAction, BookingSteps } from '@/components/gm/booking-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    show: { id: number; name: string; status: string };
    mode: 'rumble' | 'survivor_series';
    needed: number;
    pool: WrestlerRow[];
    selected: number[];
    rival: string[];
    booking_order: BookingOrder;
};

/**
 * Pick your 15 Royal Rumble entrants or your Survivor Series team of 3 (§10).
 */
export default function PpvEntrants() {
    const { game, show, mode, needed, pool, selected, rival, booking_order } =
        usePage<Props>().props;
    const [picked, setPicked] = useState<number[]>(selected);
    const readOnly = show.status !== 'booking' || !booking_order.editable;
    const rumble = mode === 'rumble';

    const toggle = (id: number) =>
        setPicked((current) =>
            current.includes(id)
                ? current.filter((x) => x !== id)
                : current.length < needed
                  ? [...current, id]
                  : current,
        );

    const url = gm.ppv.entrants.store.url({ save: game.id, show: show.id });

    return (
        <>
            <Head title={show.name} />
            <PageTitle
                kicker={
                    rumble
                        ? '30-man interbrand Royal Rumble'
                        : 'Traditional 3 vs 3 Elimination'
                }
                title={show.name}
            >
                {!readOnly && (
                    <>
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            onClick={() =>
                                router.post(
                                    url,
                                    { random: true },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Random pick
                        </button>
                        <button
                            type="button"
                            className="gm-btn"
                            disabled={picked.length !== needed}
                            onClick={() =>
                                router.post(
                                    url,
                                    { ids: picked },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {rumble ? 'Lock in entrants' : 'Lock in team'} (
                            {picked.length}/{needed})
                        </button>
                    </>
                )}
                <Link
                    href={gm.schedule.url(game.id)}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    Schedule
                </Link>
                <BookingAction
                    game={game}
                    showId={show.id}
                    showName={show.name}
                    order={booking_order}
                />
            </PageTitle>
            <BookingSteps game={game} order={booking_order} />
            <p className="-mt-3 mb-6 max-w-2xl text-sm text-ink-dim">
                {rumble
                    ? 'Fifteen from each brand. No titles are on the line; the winner’s brand gets the WrestleMania main event.'
                    : 'Raw vs. SmackDown! as the Survivor Series main event. Eliminations by pinfall, submission, DQ or count-out. No titles can be defended.'}
            </p>

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <Panel title="Your roster" bodyClassName="p-0">
                    <ul className="gm-scroll max-h-[65vh] divide-y divide-white/5 overflow-y-auto">
                        {pool.map((w) => {
                            const checked = picked.includes(w.id);

                            return (
                                <li key={w.id}>
                                    <label
                                        className={cn(
                                            'flex items-center gap-3 px-4 py-2 text-sm',
                                            readOnly
                                                ? ''
                                                : 'cursor-pointer hover:bg-white/[0.03]',
                                            checked && 'bg-brand/12',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            disabled={
                                                readOnly ||
                                                (!checked &&
                                                    picked.length >= needed)
                                            }
                                            onChange={() => toggle(w.id)}
                                            aria-label={w.name}
                                        />
                                        <span className="flex-1 font-medium">
                                            <WrestlerName wrestler={w} />
                                        </span>
                                        <FatigueMeter
                                            fatigue={w.fatigue}
                                            config={game.fatigue_config}
                                        />
                                        <InjuryChip injury={w.injury} />
                                        <MoraleBadge morale={w.morale} />
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                </Panel>
                <Panel title="Rival entrants">
                    {rival.length === 0 ? (
                        <p className="text-sm text-ink-faint">
                            The rival GM hasn’t announced yet.
                        </p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {rival.map((name) => (
                                <li key={name}>{name}</li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
