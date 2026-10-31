import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AirWeekButton } from '@/components/gm/air-week-button';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { cn } from '@/lib/utils';
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
 * The week's cards in booking order (Sunday PPV first, weekly show last),
 * plus a banner when this card has to wait for an earlier one.
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
            {order.steps.length > 1 && (
                <ol
                    aria-label="This week’s cards"
                    className="flex flex-wrap items-center gap-2 text-sm"
                >
                    {order.steps.map((step, i) => (
                        <li key={step.id} className="flex items-center gap-2">
                            {i > 0 && (
                                <span aria-hidden className="text-ink-faint">
                                    →
                                </span>
                            )}
                            <Link
                                href={gm.card.show.url({
                                    save: game.id,
                                    show: step.id,
                                })}
                                aria-current={step.current ? 'step' : undefined}
                                className={cn(
                                    'flex items-center gap-2 border px-3 py-1 font-condensed tracking-wider uppercase',
                                    step.current
                                        ? 'border-brand bg-brand/15 text-white'
                                        : 'border-white/10 text-ink-dim hover:text-ink',
                                )}
                            >
                                <span className="gm-num text-xs text-ink-faint">
                                    {i + 1}
                                </span>
                                {step.name}
                                {step.aired ? (
                                    <span className="text-xs text-ink-faint">
                                        aired
                                    </span>
                                ) : (
                                    step.confirmed && (
                                        <span
                                            className="text-zone-safe"
                                            aria-label="confirmed"
                                        >
                                            ✓
                                        </span>
                                    )
                                )}
                            </Link>
                        </li>
                    ))}
                </ol>
            )}

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
}: {
    game: GameShell;
    showId: number;
    showName: string;
    order: BookingOrder;
}) {
    const [confirming, setConfirming] = useState(false);

    if (!order.airs_this_week) {
        return null;
    }

    if (order.confirmed) {
        return (
            <button
                type="button"
                className="gm-btn gm-btn-ghost gm-btn-sm"
                onClick={() =>
                    router.post(
                        gm.card.unlock.url({ save: game.id, show: showId }),
                    )
                }
            >
                Unlock card
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
            />
        );
    }

    const next = order.next?.name ?? 'the next card';

    return (
        <>
            <button
                type="button"
                className="gm-btn"
                disabled={!order.editable}
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
                onCancel={() => setConfirming(false)}
                onConfirm={() => {
                    setConfirming(false);
                    router.post(
                        gm.card.confirm.url({ save: game.id, show: showId }),
                    );
                }}
            />
        </>
    );
}
