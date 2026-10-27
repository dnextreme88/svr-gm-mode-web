import { Fragment, useState } from 'react';
import {
    CaptainTag,
    ChampionTag,
    FactionName,
} from '@/components/gm/champion-tag';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { groupSide } from '@/lib/factions';
import type { PendingMatch } from '@/types/gm';

type Side = PendingMatch['sides'][number];

/**
 * An undecided match on the live results screen: each side with a Book to
 * Win button, plus Simulate match. Only the match up next can be decided.
 * A Gauntlet is booked bout by bout (house rule).
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
    /**
     * `null` simulates the match; a side index books that side to win. A
     * Gauntlet passes its bout picks instead (the rest are simulated).
     */
    onDecide: (side: number | null, bouts?: number[]) => void;
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

            {match.gauntlet ? (
                <GauntletBouts
                    key={match.id}
                    match={match}
                    disabled={disabled}
                    onDecide={onDecide}
                />
            ) : (
                <>
                    <ul className="mt-6 divide-y divide-white/10 border-y border-white/10">
                        {match.sides.map((side) => (
                            <li
                                key={side.side}
                                data-testid={`book-side-${side.side}`}
                                className="flex items-center justify-between gap-4 py-3"
                            >
                                <SideLabel match={match} side={side} />
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
                </>
            )}
        </article>
    );
}

function SideLabel({ match, side }: { match: PendingMatch; side: Side }) {
    return (
        <span className="text-lg font-semibold">
            {groupSide(side.members, (m) => m.name, match.factions).map(
                (item, i) => {
                    const members =
                        item.kind === 'faction' ? item.members : [item.member];

                    return (
                        <Fragment
                            key={
                                item.kind === 'faction'
                                    ? `f:${item.name}`
                                    : item.member.id
                            }
                        >
                            {i > 0 && ' & '}
                            {item.kind === 'faction' ? (
                                <FactionName
                                    name={item.name}
                                    members={item.members.map((m) => m.name)}
                                />
                            ) : (
                                <WrestlerName wrestler={item.member} />
                            )}
                            {members.some((m) =>
                                match.champions.includes(m.name),
                            ) && <ChampionTag />}
                            {members.some((m) =>
                                match.captains.includes(m.name),
                            ) && <CaptainTag />}
                        </Fragment>
                    );
                },
            )}
        </span>
    );
}

/**
 * Gauntlet: entrants 1 and 2 open, and each bout's winner meets the next
 * entrant. The player books one bout at a time; after the last bout the
 * picks are sent. Simulate sends the picks so far and simulates the rest.
 */
function GauntletBouts({
    match,
    disabled,
    onDecide,
}: {
    match: PendingMatch;
    disabled: boolean;
    onDecide: (side: number | null, bouts?: number[]) => void;
}) {
    const [picks, setPicks] = useState<number[]>([]);
    const entrants = match.sides;
    const total = entrants.length - 1;

    // Replay the picks to find who meets whom.
    const decided: Array<{ winner: Side; loser: Side }> = [];
    let holder = entrants[0];

    picks.forEach((pick, i) => {
        const challenger = entrants[i + 1];
        const winner = pick === holder.side ? holder : challenger;
        decided.push({
            winner,
            loser: winner === holder ? challenger : holder,
        });
        holder = winner;
    });

    const challenger = entrants[picks.length + 1];
    const pick = (side: number) => {
        const next = [...picks, side];

        if (next.length === total) {
            onDecide(null, next);
        } else {
            setPicks(next);
        }
    };

    return (
        <>
            {decided.length > 0 && (
                <ol
                    data-testid="gauntlet-picks"
                    className="mt-6 space-y-1 text-sm text-ink-dim"
                >
                    {decided.map((bout, i) => (
                        <li key={i}>
                            <span className="gm-label mr-2">Bout {i + 1}</span>
                            <span className="font-semibold text-ink">
                                {bout.winner.members[0].name}
                            </span>{' '}
                            def. {bout.loser.members[0].name}
                        </li>
                    ))}
                </ol>
            )}

            {challenger && (
                <>
                    <div
                        data-testid="gauntlet-bout"
                        className="gm-label mt-6 text-center"
                    >
                        Bout {picks.length + 1} of {total}
                    </div>
                    <ul className="mt-2 divide-y divide-white/10 border-y border-white/10">
                        {[holder, challenger].map((side) => (
                            <li
                                key={side.side}
                                data-testid={`book-side-${side.side}`}
                                className="flex items-center justify-between gap-4 py-3"
                            >
                                <SideLabel match={match} side={side} />
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-gold gm-btn-sm shrink-0"
                                    disabled={disabled}
                                    onClick={() => pick(side.side)}
                                >
                                    Book to Win
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <p className="mt-4 text-center text-sm text-ink-faint">
                {match.is_next
                    ? 'Book each bout in order: the winner meets the next entrant.'
                    : 'Decide the matches before this one first.'}
            </p>

            <div className="mt-5 flex justify-center gap-3">
                {picks.length > 0 && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-sm"
                        disabled={disabled}
                        onClick={() => setPicks(picks.slice(0, -1))}
                    >
                        Back
                    </button>
                )}
                <button
                    type="button"
                    className="gm-btn"
                    disabled={disabled}
                    onClick={() => onDecide(null, picks)}
                >
                    {picks.length > 0 ? 'Simulate the rest' : 'Simulate match'}
                </button>
            </div>
        </>
    );
}
