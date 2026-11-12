import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { unsavedList, useUnsavedSlots } from '@/components/gm/unsaved-changes';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

/** A match slot booked in the same category as that slot last week. */
export type RepeatedSlot = { position: number; family: string; label: string };

/** Repeat matches on a weekly show (house rule, 2026-11-08). */
export type RepeatWarning = {
    slots: RepeatedSlot[];
    /** Attendance points each repeat costs, and the most they can cost. */
    per_slot: number;
    max: number;
};

/** "Match 3", or "Main Event" for a weekly show's 6th match. */
const slotName = (position: number) =>
    position === 6 ? 'Main Event' : `Match ${position}`;

/**
 * Airs a night, after a confirmation, and opens its live results, where the
 * player decides each match. It lives on the night's last card. In a PPV
 * week (house rule, 2026-11-01) it first airs the PPV night ("Air
 * SummerSlam"); the weekly show is booked after the PPV's results and its
 * button airs the rest of the week. While a night is airing it goes back to
 * the results.
 *
 * With unsaved matches or promos on the card, the dialog lists them instead
 * and offers no way to air until they're saved or discarded. Repeat
 * matches (house rule, 2026-11-08) add a warning to the confirmation; they
 * don't stop the week airing.
 */
export function AirWeekButton({
    game,
    shows,
    ppv,
    disabled = false,
    repeats,
}: {
    game: GameShell;
    /** Every card that airs on this night, in air order. */
    shows: string[];
    /** The PPV this button airs, when it airs a PPV night. */
    ppv?: string;
    disabled?: boolean;
    repeats?: RepeatWarning;
}) {
    const [airing, setAiring] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const unsaved = useUnsavedSlots();

    if (game.stage !== 'in_season') {
        return null;
    }

    if (game.airing) {
        return (
            <Link
                href={gm.results.url({ save: game.id, week: game.week })}
                className="gm-btn"
            >
                <span className="size-2 animate-gm-pulse rounded-full bg-white" />
                Resume results
            </Link>
        );
    }

    const label = ppv ? `Air ${ppv}` : `Air week ${game.season_week}`;
    const list =
        shows.length > 1
            ? `${shows.slice(0, -1).join(', ')} and ${shows[shows.length - 1]}`
            : (shows[0] ?? 'This week’s show');

    return (
        <>
            <button
                type="button"
                className="gm-btn"
                disabled={airing || disabled || game.deficit.blocked}
                aria-busy={airing || undefined}
                aria-describedby={
                    game.deficit.blocked ? 'air-week-blocked' : undefined
                }
                title={
                    game.deficit.blocked
                        ? 'Choose a deficit recovery plan on Financials first'
                        : ppv
                          ? 'Air the PPV and decide its matches'
                          : 'Air this week and decide its matches'
                }
                onClick={() => setConfirming(true)}
            >
                <span className="size-2 animate-gm-pulse rounded-full bg-white" />
                {airing ? 'On air…' : label}
            </button>
            {/* Why it can't air, in words (UI audit, 2026-11-12). */}
            {game.deficit.blocked && (
                <Link
                    id="air-week-blocked"
                    href={gm.financials.url(game.id)}
                    className="text-xs text-zone-serious underline underline-offset-4"
                >
                    Choose a deficit plan on Financials first
                </Link>
            )}
            <ConfirmDialog
                open={confirming && unsaved.length > 0}
                title="Unsaved changes"
                message={
                    <>
                        {unsavedList(unsaved)}{' '}
                        {unsaved.length === 1 ? 'has' : 'have'} unsaved changes.
                        Save or discard {unsaved.length === 1 ? 'it' : 'them'}{' '}
                        before airing {ppv ?? `week ${game.season_week}`}:
                        <ul
                            className="mt-2 list-disc pl-5 text-ink"
                            aria-label="Unsaved slots"
                        >
                            {unsaved.map((key) => (
                                <li key={key}>{key}</li>
                            ))}
                        </ul>
                    </>
                }
                cancelLabel="Back to the card"
                onCancel={() => setConfirming(false)}
            />
            <ConfirmDialog
                open={confirming && unsaved.length === 0}
                title={`${label}?`}
                message={
                    <>
                        {list} {shows.length > 1 ? 'air' : 'airs'} now. You
                        decide each match on the results screen: simulate it or
                        book a winner. You can’t change{' '}
                        {shows.length > 1 ? 'these cards' : 'this card'}{' '}
                        afterwards.
                        {ppv &&
                            ' The weekly show is booked once the PPV’s results are in.'}
                        {repeats && repeats.slots.length > 0 && (
                            <div
                                className="mt-3 rounded border border-gold/40 bg-gold/10 p-3 text-ink"
                                data-testid="repeat-warning"
                            >
                                <strong className="text-gold">
                                    Fans have seen these before:
                                </strong>{' '}
                                the same kind of match as last week in the same
                                slot. Each costs {repeats.per_slot} attendance
                                points (up to {repeats.max}).
                                <ul
                                    className="mt-2 list-disc pl-5"
                                    aria-label="Repeated matches"
                                >
                                    {repeats.slots.map((slot) => (
                                        <li key={slot.position}>
                                            {slotName(slot.position)}:{' '}
                                            {slot.label}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                }
                confirmLabel={label}
                onCancel={() => setConfirming(false)}
                onConfirm={() => {
                    setConfirming(false);
                    setAiring(true);
                    router.post(
                        gm.advance.url(game.id),
                        {},
                        { onFinish: () => setAiring(false) },
                    );
                }}
            />
        </>
    );
}
