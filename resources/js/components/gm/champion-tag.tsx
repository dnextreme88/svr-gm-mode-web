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

/** " w/ Eugene": the side's manager in a w/Manager match. */
export function ManagerTag({ name }: { name: string }) {
    return (
        <span data-testid="side-manager">
            <span className="mx-1 text-ink-faint">w/</span>
            {name}
        </span>
    );
}

/**
 * One side's names joined with `&`, each champion tagged `(C)` and each
 * Captain Fall captain tagged `(Cpt)`. Factions teaming on the side show as
 * the faction, tagged when any of its members is. A side's manager follows
 * as "w/ Eugene".
 */
export function SideNames({
    names,
    champions,
    captains = [],
    factions = [],
    manager = null,
    brands = null,
}: {
    names: string[];
    champions: string[];
    captains?: string[];
    factions?: MatchFaction[];
    manager?: string | null;
    /** Each name's brand, on the matches between the brands: "Val Venis (Raw)". */
    brands?: Record<string, string> | null;
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
                        {brands?.[members[0]] && (
                            <BrandLabel brand={brands[members[0]]} />
                        )}
                    </Fragment>
                );
            })}
            {manager && <ManagerTag name={manager} />}
        </>
    );
}

/**
 * "(Raw)" after a name on the matches between the brands: WrestleMania's
 * interbrand series and the Survivor Series 3 vs 3 (2026-11-15).
 */
export function BrandLabel({ brand }: { brand: string }) {
    return (
        <span
            data-testid="brand-label"
            className="mr-1 ml-2 text-sm font-normal text-ink-dim"
        >
            ({brand})
        </span>
    );
}
