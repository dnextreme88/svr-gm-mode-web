import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TitleShotReminder } from '@/components/gm/title-shot-reminder';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
}));

const shots = [
    {
        id: 1,
        championship_id: 5,
        championship: 'WWE Championship',
        contender_ids: [2],
        contenders: 'Edge',
        booked: false,
    },
];

describe('TitleShotReminder', () => {
    it('keeps the reminder open and says why when booking fails (UI audit)', async () => {
        const user = userEvent.setup();
        render(
            <TitleShotReminder
                shots={shots}
                canBook
                onBook={() => Promise.reject(new Error('No free slot.'))}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Book it now' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'No free slot.',
        );
        expect(screen.getByText('Title match to book')).toBeInTheDocument();
    });

    it('closes once the match is booked', async () => {
        const user = userEvent.setup();
        render(
            <TitleShotReminder
                shots={shots}
                canBook
                onBook={() => undefined}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Book it now' }));

        expect(screen.queryByText('Title match to book')).toBeNull();
    });
});
