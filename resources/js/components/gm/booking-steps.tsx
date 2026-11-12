import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AirWeekButton } from '@/components/gm/air-week-button';
import type { RepeatWarning } from '@/components/gm/air-week-button';
import { BusyLabel } from '@/components/gm/busy-label';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { useBusy } from '@/lib/use-busy';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

/** Where a card sits in the week's booking order (`BookingOrderPresenter`). */
export type BookingOrder = {
    steps: Array<{
        id: number;
        name: string;
        show_type: 'weekly' | 'ppv';
        confirmed: boolean;
        current: boolean;
        aired: boolean;
    }>;
    blocked_by: { id: number; name: string } | null;
    /** The weekly show waits for the PPV night to air, not to be confirmed. */
    waits_for_airing: boolean;
    next: { id: number; name: string } | null;
    confirmed: boolean;
    editable: boolean;
    /** The last card of its night: its Air button airs that night. */
    is_last: boolean;
    airs_this_week: boolean;
    /** House rule (2026-11-01): the PPV night airs before the weekly show is booked. */
    night: 'ppv' | 'weekly';
};

/** The cards that air on a night, in booking order. */
export function nightSteps(order: BookingOrder): BookingOrder['steps'] {
    return order.steps.filter(
        (s) => (s.show_type === 'ppv') === (order.night === 'ppv'),
    );
}

/**
 * A banner when this card has to wait for an earlier one. There's no card
 * switcher (house rule, 2026-11-12): on a PPV week the PPV night is booked
 * and aired first, then the weekly show opens; Continue moves between a
 * night's cards.
 */
export function BookingSteps({
    game,
    order,
}: {
    game: GameShell;
    order: BookingOrder;
}) {
    if (!order.airs_this_week) {
        return null;
    }

    return (
        <div className="mb-4 space-y-3">
            {order.blocked_by && (
                <div
                    role="alert"
                    className="border-l-4 border-brand bg-brand/10 px-4 py-3 text-sm"
                >
                    {order.waits_for_airing ? (
                        <>
                            Weeks start on Sunday, so{' '}
                            <strong>{order.blocked_by.name}</strong> airs first.
                            This show is booked once its results are in.{' '}
                        </>
                    ) : (
                        <>
                            Weeks start on Sunday, so{' '}
                            <strong>{order.blocked_by.name}</strong> is booked
                            first.{' '}
                        </>
                    )}
                    <Link
                        href={gm.card.show.url({
                            save: game.id,
                            show: order.blocked_by.id,
                        })}
                        className="font-semibold underline underline-offset-4"
                    >
                        {order.waits_for_airing ? 'Go to' : 'Book'}{' '}
                        {order.blocked_by.name}
                    </Link>
                </div>
            )}

            {order.confirmed && (
                <div className="border-l-4 border-zone-safe bg-zone-safe/10 px-4 py-3 text-sm">
                    This card is confirmed and airs with the rest of the{' '}
                    {order.night === 'ppv' ? 'PPV night' : 'week'}. Unlock it to
                    make changes.
                </div>
            )}
        </div>
    );
}

/**
 * The header action for a card: "Continue to …" moves on to the week's next
 * card, the last card airs the week, and a confirmed card can be unlocked.
 */
export function BookingAction({
    game,
    showId,
    showName,
    order,
    repeats,
}: {
    game: GameShell;
    showId: number;
    showName: string;
    order: BookingOrder;
    repeats?: RepeatWarning;
}) {
    const [confirming, setConfirming] = useState(false);
    const { busy, track } = useBusy();

    if (!order.airs_this_week) {
        return null;
    }

    if (order.confirmed) {
        return (
            <button
                type="button"
                className="gm-btn gm-btn-ghost gm-btn-sm"
                disabled={busy !== null}
                aria-busy={busy !== null || undefined}
                onClick={() =>
                    router.post(
                        gm.card.unlock.url({ save: game.id, show: showId }),
                        {},
                        track('unlock'),
                    )
                }
            >
                <BusyLabel busy={busy !== null} busyText="Unlocking…">
                    Unlock card
                </BusyLabel>
            </button>
        );
    }

    if (order.is_last) {
        const shows = nightSteps(order).map((s) => s.name);

        return (
            <AirWeekButton
                game={game}
                shows={shows}
                ppv={order.night === 'ppv' ? shows[0] : undefined}
                disabled={order.blocked_by !== null}
                repeats={repeats}
            />
        );
    }

    const next = order.next?.name ?? 'the next card';

    return (
        <>
            <button
                type="button"
                className="gm-btn"
                disabled={!order.editable || busy !== null}
                onClick={() => setConfirming(true)}
            >
                Continue to {next}
            </button>
            <ConfirmDialog
                open={confirming}
                title={`Finish ${showName}?`}
                message={
                    <>
                        {showName} is locked in and airs with the rest of{' '}
                        {order.night === 'ppv'
                            ? 'the PPV night'
                            : `week ${game.season_week}`}
                        . Next up: {next}. You can unlock it again until it
                        airs.
                    </>
                }
                confirmLabel={`Continue to ${next}`}
                busy={busy !== null}
                busyText="Locking in…"
                onCancel={() => setConfirming(false)}
                onConfirm={() =>
                    router.post(
                        gm.card.confirm.url({ save: game.id, show: showId }),
                        {},
                        {
                            ...track('confirm'),
                            onSuccess: () => setConfirming(false),
                        },
                    )
                }
            />
        </>
    );
}
