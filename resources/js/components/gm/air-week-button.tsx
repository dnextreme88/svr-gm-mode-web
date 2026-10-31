import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { unsavedList, useUnsavedSlots } from '@/components/gm/unsaved-changes';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

/**
 * Airs a night, after a confirmation, and opens its live results, where the
 * player decides each match. It lives on the night's last card. In a PPV
 * week (house rule, 2026-11-01) it first airs the PPV night ("Air
 * SummerSlam"); the weekly show is booked after the PPV's results and its
 * button airs the rest of the week. While a night is airing it goes back to
 * the results.
 *
 * With unsaved matches or promos on the card, the dialog lists them instead
 * and offers no way to air until they're saved or discarded.
 */
export function AirWeekButton({
    game,
    shows,
    ppv,
    disabled = false,
}: {
    game: GameShell;
    /** Every card that airs on this night, in air order. */
    shows: string[];
    /** The PPV this button airs, when it airs a PPV night. */
    ppv?: string;
    disabled?: boolean;
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
