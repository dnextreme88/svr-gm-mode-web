import { Fragment } from 'react';
import { groupSide } from '@/lib/factions';
import type { MatchFaction } from '@/types/gm';

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
 * A faction's name in place of its members' (house rule); hovering shows
 * who they are.
 */
export function FactionName({
    name,
    members,
}: {
    name: string;
    members: string[];
}) {
    return (
        <span className="gm-faction-name" title={members.join(' & ')}>
            {name}
        </span>
    );
}

/**
 * One side's names joined with `&`, each champion tagged `(C)` and each
 * Captain Fall captain tagged `(Cpt)`. Factions teaming on the side show as
 * the faction, tagged when any of its members is.
 */
export function SideNames({
    names,
    champions,
    captains = [],
    factions = [],
}: {
    names: string[];
    champions: string[];
    captains?: string[];
    factions?: MatchFaction[];
}) {
    return (
        <>
            {groupSide(names, (n) => n, factions).map((item, i) => {
                const members =
                    item.kind === 'faction' ? item.members : [item.member];
                const key =
                    item.kind === 'faction' ? `f:${item.name}` : item.member;

                return (
                    <Fragment key={key}>
                        {i > 0 && ' & '}
                        {item.kind === 'faction' ? (
                            <FactionName
                                name={item.name}
                                members={item.members}
                            />
                        ) : (
                            item.member
                        )}
                        {members.some((m) => champions.includes(m)) && (
                            <ChampionTag />
                        )}
                        {members.some((m) => captains.includes(m)) && (
                            <CaptainTag />
                        )}
                    </Fragment>
                );
            })}
        </>
    );
}
