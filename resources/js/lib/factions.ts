import type { MatchFaction } from '@/types/gm';

export type SideItem<T> =
    | { kind: 'faction'; name: string; members: T[] }
    | { kind: 'member'; member: T };

/**
 * One side split into factions and single wrestlers (house rule): the
 * members of a faction teaming here collapse into its name, placed where
 * the first of them was. The server decides which factions apply.
 */
export function groupSide<T>(
    members: T[],
    nameOf: (member: T) => string,
    factions: MatchFaction[] = [],
): SideItem<T>[] {
    const names = members.map(nameOf);
    const items: SideItem<T>[] = [];
    const placed = new Set<string>();

    members.forEach((member, i) => {
        const name = names[i];
        const faction = factions.find(
            (f) =>
                f.members.includes(name) &&
                f.members.every((m) => names.includes(m)),
        );

        if (!faction) {
            items.push({ kind: 'member', member });

            return;
        }

        if (placed.has(faction.name)) {
            return;
        }

        placed.add(faction.name);
        items.push({
            kind: 'faction',
            name: faction.name,
            members: members.filter((_, j) =>
                faction.members.includes(names[j]),
            ),
        });
    });

    return items;
}
