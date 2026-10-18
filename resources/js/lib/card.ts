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
    fixed: boolean;
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

    return [...competitorIds(draft), ...managers];
}

/** How many matches each wrestler appears in on this card (competitor or manager). */
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
 * Managing adds no fatigue.
 */
export function projectedFatigue(
    wrestlerId: number,
    upToPosition: number,
    drafts: SlotDraft[],
    types: Map<number, MatchTypeOption>,
    roster: Map<number, RosterEntry>,
    config: FatigueConfig,
): { goingIn: number; after: number } {
    let fatigue = roster.get(wrestlerId)?.fatigue ?? 0;
    let goingIn = fatigue;

    for (const draft of [...drafts].sort((a, b) => a.position - b.position)) {
        if (draft.position > upToPosition) {
            break;
        }

        const type = draft.matchTypeId
            ? types.get(draft.matchTypeId)
            : undefined;

        if (!type || !competitorIds(draft).includes(wrestlerId)) {
            continue;
        }

        if (draft.position === upToPosition) {
            goingIn = fatigue;
        }

        fatigue = Math.min(
            config.max,
            fatigue + type.fatigue_cost_bars * config.bar_size,
        );
    }

    return { goingIn, after: fatigue };
}

/** Whether a draft has every competitor position filled. */
export function isComplete(draft: SlotDraft): boolean {
    return (
        draft.matchTypeId !== null &&
        draft.sides.length > 0 &&
        draft.sides.every((side) => side.every((id) => id !== null))
    );
}
