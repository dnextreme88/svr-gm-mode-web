import { competitorIds } from '@/lib/card';
import type { SlotDraft } from '@/lib/card';
import type { Stipulation } from '@/types/enums';
import type {
    MatchTypeOption,
    RosterEntry,
    TitleContext,
    TitleOption,
} from '@/types/gm';

/**
 * Display-side mirror of the server's title booking rules (§4, §14, §22),
 * used to offer only valid choices. The server re-validates every booking.
 */
export function allowsWrestler(
    title: TitleOption,
    wrestler: RosterEntry | undefined,
): boolean {
    if (!wrestler) {
        return false;
    }

    switch (title.eligibility) {
        case 'divas_only':
            return wrestler.roster_type === 'diva';
        case 'cruiserweight_or_light_heavyweight':
            return (
                wrestler.roster_type !== 'diva' &&
                ['cruiserweight', 'light_heavyweight'].includes(
                    wrestler.weight_class,
                )
            );
        default:
            // Open titles: anyone but Divas (house rule).
            return wrestler.roster_type !== 'diva';
    }
}

function shapeFits(title: TitleOption, type: MatchTypeOption): boolean {
    const sizes = new Set(type.sides);

    return title.is_tag
        ? sizes.size === 1 && sizes.has(2)
        : sizes.size === 1 && sizes.has(1);
}

function holdersSide(draft: SlotDraft, holders: number[]): number | null {
    if (holders.length === 0) {
        return null;
    }

    const index = draft.sides.findIndex((side) =>
        holders.every((h) => side.includes(h)),
    );

    return index === -1 ? null : index;
}

/** Titles that could be on the line in this booking. */
export function titleCandidates(
    draft: SlotDraft,
    type: MatchTypeOption | undefined,
    ctx: TitleContext,
    roster: Map<number, RosterEntry>,
    /** Titles already on the line in another match on this card. */
    takenIds: number[] = [],
): TitleOption[] {
    if (!type || !type.titles_allowed) {
        return [];
    }

    const competitors = competitorIds(draft);

    if (competitors.length !== type.sides.reduce((a, b) => a + b, 0)) {
        return [];
    }

    return ctx.championships.filter((title) => {
        // A title can only be defended once per card.
        if (
            takenIds.includes(title.id) &&
            title.id !== draft.titleChampionshipId
        ) {
            return false;
        }

        if (!shapeFits(title, type)) {
            return false;
        }

        if (
            title.holder_ids.length > 0 &&
            holdersSide(draft, title.holder_ids) === null
        ) {
            return false;
        }

        const challengers = competitors.filter(
            (id) => !title.holder_ids.includes(id),
        );
        const locked = ctx.lockouts[title.id] ?? [];

        return challengers.every(
            (id) =>
                allowsWrestler(title, roster.get(id)) && !locked.includes(id),
        );
    });
}

/** Stipulations available for this booking. */
export function stipulationOptions(
    draft: SlotDraft,
    type: MatchTypeOption | undefined,
    ctx: TitleContext,
    roster: Map<number, RosterEntry>,
): Array<{ value: Stipulation; label: string; championshipId?: number }> {
    // House rule: the Gauntlet takes no stipulation.
    if (!type || type.stipulations_allowed === false) {
        return [];
    }

    const oneOnOne =
        type.sides.length === 2 && type.sides.every((s) => s === 1);
    const twoOnTwo =
        type.sides.length === 2 && type.sides.every((s) => s === 2);
    const options: Array<{
        value: Stipulation;
        label: string;
        championshipId?: number;
    }> = [];

    if (oneOnOne || twoOnTwo) {
        options.push({
            value: 'loser_leaves_show',
            label: 'Loser Leaves Show',
        });

        if (draft.titleChampionshipId === null) {
            const stake = ctx.championships.find(
                (t) =>
                    t.holder_ids.length > 0 &&
                    t.is_tag === twoOnTwo &&
                    holdersSide(draft, t.holder_ids) !== null &&
                    competitorIds(draft)
                        .filter((id) => !t.holder_ids.includes(id))
                        .every(
                            (id) =>
                                allowsWrestler(t, roster.get(id)) &&
                                !(ctx.lockouts[t.id] ?? []).includes(id),
                        ),
            );

            if (stake) {
                options.push({
                    value: 'champion_vs_contender',
                    label: `Champion vs. Contender (${stake.name.replace(' Championship', '')})`,
                    championshipId: stake.id,
                });
            }
        }

        if (draft.titleChampionshipId !== null) {
            const title = ctx.championships.find(
                (t) => t.id === draft.titleChampionshipId,
            );
            const contenders = competitorIds(draft)
                .filter((id) => !(title?.holder_ids ?? []).includes(id))
                .sort((a, b) => a - b);
            const eligible = ctx.last_chance.some(
                (entry) =>
                    entry.championship_id === draft.titleChampionshipId &&
                    entry.contender_ids.length === contenders.length &&
                    entry.contender_ids.every((id, i) => id === contenders[i]),
            );

            if (eligible) {
                options.push({
                    value: 'last_chance',
                    label: 'Last Chance Match',
                });
            }
        }
    }

    return options;
}
