import { pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { TitleContext } from '@/types/gm';

export type BuriedAlive = { id: number; name: string; weeks_left: number };
export type ActiveRivalry = {
    id: number;
    wrestler_a: string;
    wrestler_b: string;
    heat: number;
};

/**
 * Active Effects (house rule): everything in play on this card's brand that
 * shapes the booking, always shown above the match cards. Earned title
 * shots, #1 contenders, Last Chance lockouts, Rematch Clauses, the Money in
 * the Bank briefcase, Buried Alive losers sitting out, superstars sitting
 * out after a blocked defection (2026-11-20), and the brand's rivalries
 * with their heat (house rule, 2026-11-11).
 */
export function ActiveEffects({
    context,
    buriedAlive,
    sittingOut = [],
    rivalries = [],
}: {
    context: TitleContext;
    buriedAlive: BuriedAlive[];
    /** Sitting out for another reason: a blocked defection. */
    sittingOut?: BuriedAlive[];
    rivalries?: ActiveRivalry[];
}) {
    const shots = context.title_shots.filter((s) => !s.booked);
    const contenderships = context.contenderships ?? [];
    const lockouts = context.last_chance_lockouts ?? [];
    const rematches = context.rematch_clauses ?? [];
    const briefcase = context.briefcase;
    const qualifiers = context.qualifiers ?? null;
    const count =
        (qualifiers ? 1 : 0) +
        shots.length +
        contenderships.length +
        lockouts.length +
        rematches.length +
        buriedAlive.length +
        sittingOut.length +
        rivalries.length +
        (briefcase ? 1 : 0);

    return (
        <section
            aria-label="Active Effects"
            data-testid="active-effects"
            // Gold only while something is in play; quiet otherwise.
            className={cn(
                'mb-4 border-l-4 px-4 py-3',
                count === 0
                    ? 'border-white/15 bg-white/[0.03]'
                    : 'border-gold bg-gold/10',
            )}
        >
            <h2
                className={cn(
                    'gm-label',
                    count === 0 ? 'text-ink-dim' : 'text-gold-hot',
                )}
            >
                Active Effects
            </h2>
            {count === 0 ? (
                <p className="mt-3 text-ink-dim">
                    No active effects at the moment
                </p>
            ) : (
                <ul className="mt-3 space-y-1">
                    {shots.map((shot) => (
                        <Effect
                            key={`shot-${shot.id}`}
                            kind="Champion vs. Contender"
                        >
                            {shot.contenders} earned a{' '}
                            <strong>{shot.championship}</strong> match last
                            week. You are required to book this title match this
                            week.
                        </Effect>
                    ))}
                    {contenderships.map((c) => (
                        <Effect
                            key={`no1-${c.championship_id}`}
                            kind="#1 contender"
                        >
                            The next <strong>{c.championship}</strong> match can
                            only be booked with {c.contenders} as the only
                            challenger since he rightfully earned the title
                            opportunity
                        </Effect>
                    ))}
                    {lockouts.map((l) => (
                        <Effect key={`lc-${l.id}`} kind="Last Chance">
                            {l.contenders} lost a Last Chance match and can’t
                            contend for the <strong>{l.championship}</strong>{' '}
                            while {l.champion} is champion.
                        </Effect>
                    ))}
                    {rematches.map((r) => (
                        <Effect key={`rematch-${r.id}`} kind="Rematch Clause">
                            Former champion {r.former} has{' '}
                            <strong>
                                {r.booked
                                    ? 'booked on this card'
                                    : `${pluralize(r.weeks_left, 'week')} left`}
                            </strong>{' '}
                            to be booked on a <strong>{r.championship}</strong>{' '}
                            match against {r.champions}
                        </Effect>
                    ))}
                    {qualifiers && (
                        <Effect kind="Qualifying Matches">
                            {qualifiers.ppv === 'Survivor Series'
                                ? 'Survivor Series team'
                                : 'Money in the Bank'}
                            :{' '}
                            <span className="gm-num">
                                {qualifiers.count} of {qualifiers.of}
                            </span>{' '}
                            qualified
                            {qualifiers.names.length > 0
                                ? ` (${qualifiers.names.join(', ')})`
                                : ''}
                            .{' '}
                            {qualifiers.position === 6
                                ? 'The main event'
                                : `Match ${qualifiers.position ?? ''}`}{' '}
                            is this week’s Qualifying Match.
                        </Effect>
                    )}
                    {briefcase && (
                        <Effect kind="Money in the Bank">
                            {briefcase.cashed_in_here
                                ? `${briefcase.holder} is cashing in the Money in the Bank briefcase on this card.`
                                : `${briefcase.holder} holds the Money in the Bank briefcase: a world title shot on any card until week ${briefcase.expires_week}.`}
                        </Effect>
                    )}
                    {buriedAlive.map((w) => (
                        <Effect key={`buried-${w.id}`} kind="Buried Alive">
                            {w.name} was buried alive and is out for{' '}
                            {pluralize(w.weeks_left, 'more week', 'more weeks')}
                            .
                        </Effect>
                    ))}
                    {sittingOut.map((w) => (
                        <Effect key={`sitting-out-${w.id}`} kind="Sitting out">
                            {w.name} tried to leave for the other brand, which
                            had no room, and sits out for{' '}
                            {pluralize(w.weeks_left, 'more week', 'more weeks')}
                            .
                        </Effect>
                    ))}
                    {rivalries.map((r) => (
                        <Effect key={`rivalry-${r.id}`} kind="Rivalry">
                            {r.wrestler_a} vs. {r.wrestler_b} · Heat{' '}
                            <span className="gm-num">{r.heat}</span>
                        </Effect>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Effect({
    kind,
    children,
}: {
    kind: string;
    children: React.ReactNode;
}) {
    return (
        <li>
            <span className="mr-2 font-condensed text-xs font-bold tracking-wider text-gold-hot uppercase">
                {kind}
            </span>
            {children}
        </li>
    );
}
