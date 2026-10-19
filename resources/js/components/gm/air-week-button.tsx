import { router } from '@inertiajs/react';
import { useState } from 'react';
import gm from '@/routes/gm';
import type { GameShell } from '@/types/gm';

/**
 * Airs the current week and resolves every match. It lives on the show
 * pages, where the week's card is finalized.
 */
export function AirWeekButton({ game }: { game: GameShell }) {
    const [airing, setAiring] = useState(false);

    if (game.stage !== 'in_season') {
        return null;
    }

    return (
        <button
            type="button"
            className="gm-btn"
            disabled={airing || game.deficit.blocked}
            title={
                game.deficit.blocked
                    ? 'Choose a deficit recovery plan on Financials first'
                    : 'Air this week and resolve every match'
            }
            onClick={() => {
                setAiring(true);
                router.post(
                    gm.advance.url(game.id),
                    {},
                    { onFinish: () => setAiring(false) },
                );
            }}
        >
            <span className="size-2 animate-gm-pulse rounded-full bg-white" />
            {airing ? 'On air…' : `Air week ${game.season_week}`}
        </button>
    );
}
