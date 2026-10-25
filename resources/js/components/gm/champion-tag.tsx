import { Fragment } from 'react';

/**
 * Marks the champion going into a title match.
 */
export function ChampionTag() {
    return <span className="ml-1 text-sm text-gold">(C)</span>;
}

/**
 * Marks a team's captain in a Captain Fall match.
 */
export function CaptainTag() {
    return <span className="ml-1 text-sm text-gold">(Cpt)</span>;
}

/**
 * One side's names joined with `&`, each champion tagged `(C)` and each
 * Captain Fall captain tagged `(Cpt)`.
 */
export function SideNames({
    names,
    champions,
    captains = [],
}: {
    names: string[];
    champions: string[];
    captains?: string[];
}) {
    return (
        <>
            {names.map((name, i) => (
                <Fragment key={name}>
                    {i > 0 && ' & '}
                    {name}
                    {champions.includes(name) && <ChampionTag />}
                    {captains.includes(name) && <CaptainTag />}
                </Fragment>
            ))}
        </>
    );
}
