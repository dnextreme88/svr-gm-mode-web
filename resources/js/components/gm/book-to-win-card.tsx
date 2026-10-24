import { Fragment } from 'react';
import { ChampionTag } from '@/components/gm/champion-tag';
import { WrestlerName } from '@/components/gm/wrestler-name';
import type { PendingMatch } from '@/types/gm';

/**
 * An undecided match on the live results screen: each side with a Book to
 * Win button, plus Simulate match. Only the match up next can be decided.
 */
export function BookToWinCard({
    match,
    showName,
    isMainEvent,
    busy,
    onDecide,
}: {
    match: PendingMatch;
    showName: string;
    isMainEvent: boolean;
    busy: boolean;
    /** `null` simulates the match; a side index books that side to win. */
    onDecide: (side: number | null) => void;
}) {
    const disabled = busy || !match.is_next;

    return (
        <article className="gm-panel relative overflow-hidden px-6 py-8 md:px-10">
            <div className="text-center">
                <div className="gm-label">
                    {showName} ·{' '}
                    {isMainEvent ? 'Main event' : `Match ${match.position}`}
                </div>
                <div className="mt-1 font-condensed text-sm tracking-wider text-ink-dim uppercase">
                    {match.match_type}
                    {match.title && (
                        <span className="ml-2 text-gold-hot">
                            · {match.title}
                        </span>
                    )}
                </div>
            </div>

            <ul className="mt-6 divide-y divide-white/10 border-y border-white/10">
                {match.sides.map((side) => (
                    <li
                        key={side.side}
                        data-testid={`book-side-${side.side}`}
                        className="flex items-center justify-between gap-4 py-3"
                    >
                        <span className="text-lg font-semibold">
                            {side.members.map((m, i) => (
                                <Fragment key={m.id}>
                                    {i > 0 && ' & '}
                                    <WrestlerName wrestler={m} />
                                    {match.champions.includes(m.name) && (
                                        <ChampionTag />
                                    )}
                                </Fragment>
                            ))}
                        </span>
                        <button
                            type="button"
                            className="gm-btn gm-btn-gold gm-btn-sm shrink-0"
                            disabled={disabled}
                            onClick={() => onDecide(side.side)}
                        >
                            Book to Win
                        </button>
                    </li>
                ))}
            </ul>

            {match.managers.length > 0 && (
                <p className="mt-3 text-sm text-ink-dim">
                    Managers: {match.managers.join(', ')}
                </p>
            )}
            {match.referee && (
                <p className="mt-1 text-sm text-ink-dim">
                    Special referee: {match.referee}
                </p>
            )}

            <p className="mt-4 text-center text-sm text-ink-faint">
                {match.is_next
                    ? match.title
                        ? 'A booked title match always ends with a decisive finish.'
                        : 'A booked winner can still win by DQ or count-out now and then.'
                    : 'Decide the matches before this one first.'}
            </p>

            <div className="mt-5 flex justify-center">
                <button
                    type="button"
                    className="gm-btn"
                    disabled={disabled}
                    onClick={() => onDecide(null)}
                >
                    Simulate match
                </button>
            </div>
        </article>
    );
}
