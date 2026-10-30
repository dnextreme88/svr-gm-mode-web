import type { Stipulation } from '@/types/enums';
import type { FatigueConfig, MatchTypeOption, RosterEntry } from '@/types/gm';

/** A slot being edited in the card builder. */
export type SlotDraft = {
    position: number;
    matchTypeId: number | null;
    sides: Array<Array<number | null>>;
    managers: Record<number, number | null>;
    titleChampionshipId: number | null;
    stipulation: Stipulation | null;
    stipulationChampionshipId: number | null;
    /** The special referee, for Special Referee matches (§10). */
    refereeId: number | null;
    fixed: boolean;
};

/** Fatigue a booked promo adds before a match slot (§5). */
export type PromoFatigue = {
    wrestlerId: number;
    beforePosition: number;
    bars: number;
};

export function emptySides(
    type: MatchTypeOption | undefined,
): Array<Array<number | null>> {
    return type
        ? type.sides.map((size) => Array<number | null>(size).fill(null))
        : [];
}

export function competitorIds(draft: SlotDraft): number[] {
    return draft.sides.flat().filter((id): id is number => id !== null);
}

export function participantIds(draft: SlotDraft): number[] {
    const managers = Object.values(draft.managers).filter(
        (id): id is number => id !== null && id !== undefined,
    );

    return [
        ...competitorIds(draft),
        ...managers,
        ...(draft.refereeId != null ? [draft.refereeId] : []),
    ];
}

/** How many matches each wrestler appears in on this card (competitor, manager or referee). */
export function bookingCounts(drafts: SlotDraft[]): Map<number, number> {
    const counts = new Map<number, number>();

    for (const draft of drafts) {
        for (const id of new Set(participantIds(draft))) {
            counts.set(id, (counts.get(id) ?? 0) + 1);
        }
    }

    return counts;
}

/**
 * Fatigue going into and coming out of a slot, in card order (§4, §6).
 * Managing and refereeing add no fatigue; booked promos placed before a
 * match add theirs there (§5).
 */
export function projectedFatigue(
    wrestlerId: number,
    upToPosition: number,
    drafts: SlotDraft[],
    types: Map<number, MatchTypeOption>,
    roster: Map<number, RosterEntry>,
    config: FatigueConfig,
    promos: PromoFatigue[] = [],
): { goingIn: number; after: number } {
    let fatigue = roster.get(wrestlerId)?.fatigue ?? 0;
    let goingIn = fatigue;

    for (const draft of [...drafts].sort((a, b) => a.position - b.position)) {
        if (draft.position > upToPosition) {
            break;
        }

        for (const promo of promos) {
            if (
                promo.wrestlerId === wrestlerId &&
                promo.beforePosition === draft.position
            ) {
                fatigue = Math.min(
                    config.max,
                    fatigue + promo.bars * config.bar_size,
                );
            }
        }

        if (draft.position === upToPosition) {
            goingIn = fatigue;
        }

        const type = draft.matchTypeId
            ? types.get(draft.matchTypeId)
            : undefined;

        if (!type || !competitorIds(draft).includes(wrestlerId)) {
            continue;
        }

        fatigue = Math.min(
            config.max,
            fatigue + type.fatigue_cost_bars * config.bar_size,
        );
    }

    return { goingIn, after: fatigue };
}

/**
 * Whether a draft has every competitor position filled (and its special
 * referee, when the match type needs one).
 */
export function isComplete(draft: SlotDraft, type?: MatchTypeOption): boolean {
    return (
        draft.matchTypeId !== null &&
        draft.sides.length > 0 &&
        draft.sides.every((side) => side.every((id) => id !== null)) &&
        // Every manager slot of a w/Manager type is filled too.
        (type?.manager_sides ?? []).every(
            (side) => draft.managers[side] != null,
        ) &&
        (!type?.special_referee || draft.refereeId != null)
    );
}
