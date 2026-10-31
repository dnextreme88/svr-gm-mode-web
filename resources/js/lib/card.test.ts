import { describe, expect, it } from 'vitest';
import { isComplete } from '@/lib/card';
import type { SlotDraft } from '@/lib/card';
import type { MatchTypeOption } from '@/types/gm';

const withManager = {
    id: 3,
    name: 'Normal Matches — No Manager vs. w/Manager',
    family: 'single',
    variant: 'No Manager vs. w/Manager',
    sides: [1, 1],
    manager_sides: [1],
    fatigue_cost_bars: 1,
    cost: 0,
    divas_only: false,
    divas_allowed: true,
    titles_allowed: true,
    win_condition: null,
    ppv_only: false,
    captain_fall: false,
} as MatchTypeOption;

function draft(overrides: Partial<SlotDraft> = {}): SlotDraft {
    return {
        position: 1,
        matchTypeId: 3,
        sides: [[10], [11]],
        managers: {},
        titleChampionshipId: null,
        stipulation: null,
        stipulationChampionshipId: null,
        refereeId: null,
        fixed: false,
        ...overrides,
    };
}

describe('isComplete', () => {
    it('needs every manager slot of a w/Manager type filled', () => {
        expect(isComplete(draft(), withManager)).toBe(false);
        expect(isComplete(draft({ managers: { 1: null } }), withManager)).toBe(
            false,
        );
        expect(isComplete(draft({ managers: { 1: 12 } }), withManager)).toBe(
            true,
        );
    });

    it('needs no manager for a type without manager slots', () => {
        expect(isComplete(draft(), { ...withManager, manager_sides: [] })).toBe(
            true,
        );
    });
});
