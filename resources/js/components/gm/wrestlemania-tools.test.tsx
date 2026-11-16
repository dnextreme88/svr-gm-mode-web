import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { MainEventTypeButton } from '@/components/gm/wrestlemania-tools';
import type { MatchTypeOption } from '@/types/gm';

const { put } = vi.hoisted(() => ({ put: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { put },
    usePage: () => ({ props: {} }),
}));

describe('MainEventTypeButton', () => {
    it('picks a one-on-one type for the main event', async () => {
        const user = userEvent.setup();
        const type = (id: number, family: string, variant: string) =>
            ({
                id,
                name: variant,
                family,
                variant,
                sides: [1, 1],
                manager_sides: [],
                fatigue_cost_bars: 2,
                cost: 0,
                divas_only: false,
                divas_allowed: true,
                titles_allowed: true,
                win_condition: null,
                ppv_only: false,
            }) as unknown as MatchTypeOption;

        render(
            <MainEventTypeButton
                ids={{ save: 1, show: 9 }}
                types={[
                    type(1, 'normal', 'Single'),
                    type(2, 'ladder', 'Single'),
                ]}
                current={1}
                fatigueConfig={{
                    bar_size: 10,
                    safe_max: 70,
                    serious_at: 100,
                    max: 100,
                }}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Match type' }));
        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getAllByRole('radio')[1]);
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));

        expect(put).toHaveBeenLastCalledWith(
            expect.stringContaining('/main-event-type'),
            { match_type_id: 2 },
            expect.anything(),
        );
    });
});
