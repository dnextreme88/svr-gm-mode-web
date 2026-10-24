import { Fragment } from 'react';

/**
 * Marks the champion going into a title match.
 */
export function ChampionTag() {
    return <span className="ml-1 text-sm text-gold">(C)</span>;
}

/**
 * One side's names joined with `&`, each champion tagged `(C)`.
 */
export function SideNames({
    names,
    champions,
}: {
    names: string[];
    champions: string[];
}) {
    return (
        <>
            {names.map((name, i) => (
                <Fragment key={name}>
                    {i > 0 && ' & '}
                    {name}
                    {champions.includes(name) && <ChampionTag />}
                </Fragment>
            ))}
        </>
    );
}
