import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

/**
 * Airs the current week and resolves every match, after a confirmation. It
 * lives on the week's last card (the weekly show), so in a PPV week it airs
 * the PPV and the weekly show together.
 */
export function AirWeekButton({
    game,
    shows,
    disabled = false,
}: {
    game: GameShell;
    /** Every card that airs this week, in air order. */
    shows: string[];
    disabled?: boolean;
}) {
    const [airing, setAiring] = useState(false);
    const [confirming, setConfirming] = useState(false);

    if (game.stage !== 'in_season') {
        return null;
    }

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
                        : 'Air this week and resolve every match'
                }
                onClick={() => setConfirming(true)}
            >
                <span className="size-2 animate-gm-pulse rounded-full bg-white" />
                {airing ? 'On air…' : `Air week ${game.season_week}`}
            </button>
            <ConfirmDialog
                open={confirming}
                title={`Air week ${game.season_week}?`}
                message={
                    <>
                        {list} {shows.length > 1 ? 'air' : 'airs'} now and every
                        match is resolved. You can’t change{' '}
                        {shows.length > 1 ? 'these cards' : 'this card'}{' '}
                        afterwards.
                    </>
                }
                confirmLabel={`Air week ${game.season_week}`}
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
