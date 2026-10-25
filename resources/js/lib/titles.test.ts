import { describe, expect, it } from 'vitest';
import type { SlotDraft } from '@/lib/card';
import { stipulationOptions, titleCandidates } from '@/lib/titles';
import type { MatchTypeOption, RosterEntry, TitleContext } from '@/types/gm';

const singles = {
    id: 1,
    sides: [1, 1],
    titles_allowed: true,
} as MatchTypeOption;

const tripleThreat = { ...singles, id: 2, sides: [1, 1, 1] } as MatchTypeOption;

function wrestler(
    id: number,
    overrides: Partial<RosterEntry> = {},
): RosterEntry {
    return {
        id,
        roster_type: 'superstar',
        weight_class: 'heavyweight',
        ...overrides,
    } as RosterEntry;
}

const roster = new Map<number, RosterEntry>([
    [1, wrestler(1)],
    [2, wrestler(2)],
    [3, wrestler(3, { roster_type: 'diva', weight_class: 'featherweight' })],
    [4, wrestler(4, { weight_class: 'cruiserweight' })],
]);

const context: TitleContext = {
    championships: [
        {
            id: 10,
            name: 'WWE Championship',
            is_tag: false,
            eligibility: 'open',
            holder_ids: [1],
            holder_name: 'Batista',
        },
        {
            id: 11,
            name: 'Cruiserweight Championship',
            is_tag: false,
            eligibility: 'cruiserweight_or_light_heavyweight',
            holder_ids: [4],
            holder_name: 'Rey',
        },
        {
            id: 12,
            name: 'Intercontinental Championship',
            is_tag: false,
            eligibility: 'open',
            holder_ids: [],
            holder_name: null,
        },
    ],
    lockouts: {},
    last_chance: [
        {
            championship_id: 10,
            reign_id: 1,
            champion_ids: [1],
            contender_ids: [2],
        },
    ],
    title_shots: [],
    briefcase: null,
};

function draft(sides: number[][], title: number | null = null): SlotDraft {
    return {
        position: 1,
        matchTypeId: 1,
        sides,
        managers: {},
        titleChampionshipId: title,
        stipulation: null,
        stipulationChampionshipId: null,
        refereeId: null,
        fixed: false,
    };
}

describe('title candidates', () => {
    it('offers a title only when its champion is in the match, plus vacant titles', () => {
        expect(
            titleCandidates(draft([[1], [2]]), singles, context, roster).map(
                (t) => t.id,
            ),
        ).toEqual([10, 12]);
        expect(
            titleCandidates(draft([[2], [4]]), singles, context, roster).map(
                (t) => t.id,
            ),
        ).toEqual([12]);
    });

    it("never puts a men's title on the line with a Diva in the match", () => {
        // 3 is a Diva: not the WWE challenger, and not a vacant IC contender.
        expect(
            titleCandidates(draft([[1], [3]]), singles, context, roster),
        ).toEqual([]);
        expect(
            titleCandidates(draft([[2], [3]]), singles, context, roster),
        ).toEqual([]);
    });

    it('offers the Cruiserweight title to a Cruiserweight challenger', () => {
        const cruisers = new Map(roster).set(
            5,
            wrestler(5, { weight_class: 'light_heavyweight' }),
        );

        expect(
            titleCandidates(draft([[4], [5]]), singles, context, cruisers).map(
                (t) => t.id,
            ),
        ).toContain(11);
    });

    it('skips titles already on the line in another match on the card', () => {
        expect(
            titleCandidates(
                draft([[1], [2]]),
                singles,
                context,
                roster,
                [12],
            ).map((t) => t.id),
        ).toEqual([10]);
    });

    it('keeps the title this match already has, even if listed as taken', () => {
        expect(
            titleCandidates(
                draft([[1], [2]], 10),
                singles,
                context,
                roster,
                [10],
            ).map((t) => t.id),
        ).toEqual([10, 12]);
    });

    it('applies the Cruiserweight eligibility rule to challengers', () => {
        expect(
            titleCandidates(draft([[4], [2]]), singles, context, roster).map(
                (t) => t.id,
            ),
        ).not.toContain(11);
    });

    it('excludes challengers locked out by a Last Chance loss', () => {
        const locked = { ...context, lockouts: { '10': [2] } };

        expect(
            titleCandidates(draft([[1], [2]]), singles, locked, roster).map(
                (t) => t.id,
            ),
        ).not.toContain(10);
    });
});

describe('stipulation options', () => {
    it('offers Champion vs. Contender with the title off the line, and Loser Leaves Show for 1v1', () => {
        const values = stipulationOptions(
            draft([[1], [2]]),
            singles,
            context,
            roster,
        ).map((o) => o.value);

        expect(values).toEqual(['loser_leaves_show', 'champion_vs_contender']);
    });

    it('offers Last Chance only to an eligible contender with the title on the line', () => {
        expect(
            stipulationOptions(
                draft([[1], [2]], 10),
                singles,
                context,
                roster,
            ).map((o) => o.value),
        ).toContain('last_chance');
        expect(
            stipulationOptions(
                draft([[1], [3]], 10),
                singles,
                context,
                roster,
            ).map((o) => o.value),
        ).not.toContain('last_chance');
    });

    it('offers nothing for a Gauntlet', () => {
        const gauntlet = {
            ...singles,
            id: 3,
            sides: [1, 1, 1, 1, 1, 1],
            titles_allowed: false,
            stipulations_allowed: false,
        } as MatchTypeOption;

        expect(
            stipulationOptions(
                draft([[1], [2], [3], [4], [5], [6]]),
                gauntlet,
                context,
                roster,
            ),
        ).toEqual([]);
    });

    it('offers nothing outside 1v1 and 2v2', () => {
        expect(
            stipulationOptions(
                draft([[1], [2], [3]]),
                tripleThreat,
                context,
                roster,
            ),
        ).toEqual([]);
    });
});
