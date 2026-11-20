import { Head, Link, router, usePage } from '@inertiajs/react';
import { BusyLabel } from '@/components/gm/busy-label';
import { useBusy } from '@/lib/use-busy';
import { useState } from 'react';
import { InjuryChip, MoraleBadge } from '@/components/gm/badges';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { BookingAction, BookingSteps } from '@/components/gm/booking-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import { RosterType } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    show: { id: number; name: string; status: string };
    mode: 'rumble' | 'survivor_series';
    needed: number;
    pool: WrestlerRow[];
    selected: number[];
    /** House rule (2026-11-17): the team spots won in Qualifying Matches. */
    qualified?: { id: number; name: string }[];
    rival: string[];
    booking_order: BookingOrder;
};

/**
 * Pick your 15 Royal Rumble entrants or your Survivor Series team of 5 (§10).
 * Survivor Series spots won in Qualifying Matches are locked; only the rest
 * are picked here.
 */
export default function PpvEntrants() {
    const {
        game,
        show,
        mode,
        needed,
        pool,
        selected,
        qualified = [],
        rival,
        booking_order,
    } = usePage<Props>().props;
    const qualifiedIds = qualified.map((q) => q.id);
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
    const { busy, track } = useBusy();

    return (
        <>
            <Head title={show.name} />
            <PageTitle
                kicker={
                    rumble
                        ? '30-man interbrand Royal Rumble'
                        : 'Traditional 5 vs 5 Elimination'
                }
                title={show.name}
            >
                {!readOnly && (
                    <>
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={busy !== null}
                            aria-busy={busy === 'random' || undefined}
                            onClick={() =>
                                router.post(
                                    url,
                                    { random: true },
                                    {
                                        preserveScroll: true,
                                        ...track('random'),
                                    },
                                )
                            }
                        >
                            <BusyLabel
                                busy={busy === 'random'}
                                busyText="Picking…"
                            >
                                Random pick
                            </BusyLabel>
                        </button>
                        <button
                            type="button"
                            className="gm-btn"
                            disabled={picked.length !== needed || busy !== null}
                            aria-busy={busy === 'lock' || undefined}
                            onClick={() =>
                                router.post(
                                    url,
                                    { ids: picked },
                                    { preserveScroll: true, ...track('lock') },
                                )
                            }
                        >
                            <BusyLabel
                                busy={busy === 'lock'}
                                busyText="Locking in…"
                            >
                                {rumble ? 'Lock in entrants' : 'Lock in team'} (
                                {picked.length}/{needed})
                            </BusyLabel>
                        </button>
                    </>
                )}
                {/* The Rumble and the 5 vs 5 are Match 7 of their PPV's card
                    (house rule, 2026-11-15). */}
                <Link
                    href={gm.card.show.url({ save: game.id, show: show.id })}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    Back to the card
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
                    : 'Raw vs. Smackdown as the Survivor Series main event. Eliminations by pinfall, submission, DQ or count-out. No titles can be defended.'}
                {!rumble &&
                    qualified.length > 0 &&
                    ` ${qualified.length} of your team qualified on the weekly shows and can’t be changed; pick the other ${needed}.`}
            </p>
            {/* Why the other boxes are greyed out (UI audit, 2026-11-20). */}
            <p
                role="status"
                className="-mt-3 mb-6 text-sm text-gold empty:hidden"
            >
                {picked.length >= needed && !readOnly && (
                    <span data-testid="entrants-full">
                        All {needed} spots are picked. Untick someone to swap
                        them out.
                    </span>
                )}
            </p>

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <Panel title="Your roster" bodyClassName="p-0">
                    <ul className="gm-scroll max-h-[65vh] divide-y divide-white/5 overflow-y-auto">
                        {pool.map((w) => {
                            const qualifier = qualifiedIds.includes(w.id);
                            const checked = qualifier || picked.includes(w.id);
                            // House rule: Divas can't enter either PPV.
                            const diva = w.roster_type === RosterType.Diva;
                            const locked = readOnly || diva || qualifier;

                            return (
                                <li key={w.id}>
                                    <label
                                        className={cn(
                                            'flex items-center gap-3 px-4 py-2 text-sm',
                                            locked
                                                ? ''
                                                : 'cursor-pointer hover:bg-white/[0.03]',
                                            diva &&
                                                'cursor-not-allowed opacity-40',
                                            checked && 'bg-brand/12',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            disabled={
                                                locked ||
                                                (!checked &&
                                                    picked.length >= needed)
                                            }
                                            data-testid={`entrant-${w.id}`}
                                            onChange={() => toggle(w.id)}
                                            aria-label={w.name}
                                        />
                                        <span className="flex-1 font-medium">
                                            <WrestlerName wrestler={w} />
                                            {diva && (
                                                <span className="ml-2 text-xs text-ink-faint">
                                                    No Divas
                                                </span>
                                            )}
                                            {qualifier && (
                                                <span className="ml-2 text-xs font-bold text-gold uppercase">
                                                    Qualified
                                                </span>
                                            )}
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
