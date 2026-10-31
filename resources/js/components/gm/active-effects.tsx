import type { TitleContext } from '@/types/gm';

export type BuriedAlive = { id: number; name: string; weeks_left: number };

/**
 * Active Effects (house rule): everything in play on this card's brand that
 * shapes the booking, always shown above the match cards. Earned title
 * shots, #1 contenders, Last Chance lockouts, the Money in the Bank
 * briefcase and Buried Alive losers sitting out.
 */
export function ActiveEffects({
    context,
    buriedAlive,
}: {
    context: TitleContext;
    buriedAlive: BuriedAlive[];
}) {
    const shots = context.title_shots.filter((s) => !s.booked);
    const contenderships = context.contenderships ?? [];
    const lockouts = context.last_chance_lockouts ?? [];
    const briefcase = context.briefcase;
    const count =
        shots.length +
        contenderships.length +
        lockouts.length +
        buriedAlive.length +
        (briefcase ? 1 : 0);

    return (
        <section
            aria-label="Active Effects"
            data-testid="active-effects"
            className="mb-4 border-l-4 border-gold bg-gold/10 px-4 py-3 text-sm"
        >
            <h2 className="gm-label text-gold-hot">Active Effects</h2>
            {count === 0 ? (
                <p className="mt-1 text-ink-dim">
                    No active effects at the moment
                </p>
            ) : (
                <ul className="mt-1 space-y-1">
                    {shots.map((shot) => (
                        <Effect
                            key={`shot-${shot.id}`}
                            kind="Required title match"
                        >
                            {shot.contenders} earned a{' '}
                            <strong>{shot.championship}</strong> match. Book it
                            before this card can air.
                        </Effect>
                    ))}
                    {contenderships.map((c) => (
                        <Effect
                            key={`no1-${c.championship_id}`}
                            kind="#1 contender"
                        >
                            {c.contenders}{' '}
                            {c.contender_ids.length > 1 ? 'await' : 'awaits'} a{' '}
                            <strong>{c.championship}</strong> match as #1
                            contender. Nobody else can challenge for it.
                        </Effect>
                    ))}
                    {lockouts.map((l) => (
                        <Effect key={`lc-${l.id}`} kind="Last Chance">
                            {l.contenders} lost a Last Chance match and can’t
                            contend for the <strong>{l.championship}</strong>{' '}
                            while {l.champion} is champion.
                        </Effect>
                    ))}
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
                            {w.weeks_left} more week
                            {w.weeks_left === 1 ? '' : 's'}.
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
