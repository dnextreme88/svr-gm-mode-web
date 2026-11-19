import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { game: { player_brand: 'raw' } } }),
}));

describe('ConfirmDialog', () => {
    it('stays open on a click outside or Escape with stayOpen (Simulate to here)', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();

        render(
            <ConfirmDialog
                open
                stayOpen
                title="Simulate to week 29?"
                message="The computer books every card."
                onCancel={onCancel}
                onConfirm={vi.fn()}
            />,
        );

        fireEvent.pointerDown(document.body);
        await user.keyboard('{Escape}');
        expect(onCancel).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('closes on Escape otherwise', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();

        render(
            <ConfirmDialog
                open
                title="Release Edge?"
                message="He goes to Free Agency."
                onCancel={onCancel}
                onConfirm={vi.fn()}
            />,
        );

        await user.keyboard('{Escape}');
        expect(onCancel).toHaveBeenCalled();
    });
});
