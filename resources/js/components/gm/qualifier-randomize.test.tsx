import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { QualifierRandomize } from '@/components/gm/qualifier-randomize';

const requestJson = vi.fn();

vi.mock('@/lib/http', () => ({
    requestJson: (...args: unknown[]) => requestJson(...args),
}));

describe('Qualifying Match Randomize (house rule, 2026-11-17)', () => {
    it('fills the pickers with the server’s draw, rolling again each time', async () => {
        const user = userEvent.setup();
        const onPick = vi.fn();
        requestJson.mockResolvedValue({ qualifier: [3, 7, 9] });

        render(<QualifierRandomize url="/randomize" onPick={onPick} />);
        await user.click(screen.getByRole('button', { name: /Randomize/ }));
        await waitFor(() => expect(onPick).toHaveBeenCalledWith([3, 7, 9]));
        await user.click(screen.getByRole('button', { name: /Randomize/ }));

        await waitFor(() =>
            expect(requestJson).toHaveBeenLastCalledWith('POST', '/randomize', {
                roll: 1,
            }),
        );
    });

    it('says so when there aren’t enough superstars', async () => {
        const user = userEvent.setup();
        const onPick = vi.fn();
        requestJson.mockResolvedValue({ qualifier: [] });

        render(<QualifierRandomize url="/randomize" onPick={onPick} />);
        await user.click(screen.getByRole('button', { name: /Randomize/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Not enough superstars',
        );
        expect(onPick).not.toHaveBeenCalled();
    });
});
