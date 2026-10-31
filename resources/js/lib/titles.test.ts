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

        expect(values).toEqual(['champion_vs_contender', 'loser_leaves_show']);
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

    it("offers a Gauntlet only a #1 Contender's Match", () => {
        const gauntlet = {
            ...singles,
            id: 3,
            sides: [1, 1, 1, 1, 1, 1],
            titles_allowed: false,
            allowed_stipulations: ['number_one_contender'],
        } as MatchTypeOption;
        const six = new Map(
            [20, 21, 22, 23, 24, 25].map((id) => [id, wrestler(id)]),
        );

        expect(
            stipulationOptions(
                draft([[20], [21], [22], [23], [24], [25]]),
                gauntlet,
                context,
                six,
            ).map((o) => [o.value, o.titles?.map((t) => t.id)]),
        ).toEqual([['number_one_contender', [10]]]);
    });

    it('offers no Loser Leaves Show or Champion vs. Contender outside 1v1 and 2v2', () => {
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

describe("#1 Contender's Match (house rule)", () => {
    const battleRoyal = {
        ...singles,
        id: 4,
        sides: [1, 1, 1, 1, 1, 1],
    } as MatchTypeOption;
    const tag = { ...singles, id: 5, sides: [2, 2, 2] } as MatchTypeOption;
    const people = new Map(
        [20, 21, 22, 23, 24, 25].map((id) => [id, wrestler(id)]),
    );
    const numberOne = (
        sides: number[][],
        type: MatchTypeOption,
        ctx: TitleContext = context,
        who = people,
    ) =>
        stipulationOptions(draft(sides), type, ctx, who)
            .find((o) => o.value === 'number_one_contender')
            ?.titles?.map((t) => t.id) ?? [];

    it('can be a 6-man Battle Royal of non-champions', () => {
        expect(
            numberOne([[20], [21], [22], [23], [24], [25]], battleRoyal),
        ).toEqual([10]);
    });

    it('never includes the champion of the title in contention', () => {
        expect(
            numberOne(
                [[1], [20], [21], [22], [23], [24]],
                battleRoyal,
                context,
                new Map([...people, [1, wrestler(1)]]),
            ),
        ).not.toContain(10);
    });

    it("is 2 vs 2 vs 2 for a tag title, and all Divas for the Women's title", () => {
        const ctx: TitleContext = {
            ...context,
            championships: [
                {
                    id: 30,
                    name: 'World Tag Team Championship',
                    is_tag: true,
                    eligibility: 'open',
                    holder_ids: [1, 2],
                    holder_name: 'Champs',
                },
                {
                    id: 31,
                    name: "Women's Championship",
                    is_tag: false,
                    eligibility: 'divas_only',
                    holder_ids: [3],
                    holder_name: 'Trish',
                },
            ],
        };
        const divas = new Map(
            [40, 41].map((id) => [
                id,
                wrestler(id, {
                    roster_type: 'diva',
                    weight_class: 'featherweight',
                }),
            ]),
        );

        expect(
            numberOne(
                [
                    [20, 21],
                    [22, 23],
                    [24, 25],
                ],
                tag,
                ctx,
            ),
        ).toEqual([30]);
        expect(numberOne([[40], [41]], singles, ctx, divas)).toEqual([31]);
    });

    it('is gone once the title has a #1 contender waiting', () => {
        expect(
            numberOne([[20], [21], [22], [23], [24], [25]], battleRoyal, {
                ...context,
                contenderships: [
                    {
                        championship_id: 10,
                        championship: 'WWE Championship',
                        contender_ids: [2],
                        contenders: 'Edge',
                    },
                ],
            }),
        ).toEqual([]);
    });

    it('keeps the title match for exactly the #1 contender(s)', () => {
        const ctx: TitleContext = {
            ...context,
            contenderships: [
                {
                    championship_id: 10,
                    championship: 'WWE Championship',
                    contender_ids: [2],
                    contenders: 'Edge',
                },
            ],
        };

        expect(
            titleCandidates(draft([[1], [2]]), singles, ctx, roster).map(
                (t) => t.id,
            ),
        ).toContain(10);
        expect(
            titleCandidates(
                draft([[1], [20]]),
                singles,
                ctx,
                new Map([...roster, [20, wrestler(20)]]),
            ).map((t) => t.id),
        ).not.toContain(10);
    });
});

describe('stipulation order', () => {
    it('lists the stipulations alphabetically', () => {
        const people = new Map([20, 21].map((id) => [id, wrestler(id)]));
        const labels = stipulationOptions(
            draft([[20], [21]]),
            singles,
            context,
            people,
        ).map((o) => o.label);

        expect(labels).toEqual([...labels].sort((a, b) => a.localeCompare(b)));
        expect(labels).toEqual(["#1 Contender's Match", 'Loser Leaves Show']);
    });
});

describe('Winner Takes All (house rule)', () => {
    it('needs every competitor to hold a title, and never takes Divas', () => {
        const ctx: TitleContext = {
            ...context,
            championships: [
                ...context.championships,
                {
                    id: 13,
                    name: "Women's Championship",
                    is_tag: false,
                    eligibility: 'divas_only',
                    holder_ids: [3],
                    holder_name: 'Trish',
                },
            ],
        };
        const values = (sides: number[][]) =>
            stipulationOptions(draft(sides), singles, ctx, roster).map(
                (o) => o.value,
            );

        expect(values([[1], [4]])).toContain('winner_takes_all');
        expect(values([[1], [2]])).not.toContain('winner_takes_all');
        expect(values([[1], [3]])).not.toContain('winner_takes_all');
    });

    it('skips a title already on the line elsewhere on the card', () => {
        expect(
            stipulationOptions(draft([[1], [4]]), singles, context, roster, {
                takenIds: [10],
            }).map((o) => o.value),
        ).not.toContain('winner_takes_all');
    });
});
