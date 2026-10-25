import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import PpvEntrants from '@/pages/gm/ppv-entrants';

function wrestler(id: number, name: string, rosterType: string) {
    return {
        id,
        name,
        slug: name.toLowerCase().replace(/ /g, '-'),
        roster_type: rosterType,
        weight_class: 'heavyweight',
        brand: 'raw',
        morale: 'happy',
        fatigue: 0,
        fatigue_zone: 'safe',
        wins: 0,
        losses: 0,
        win_streak: 0,
        loss_streak: 0,
        popularity: null,
        specialties: [],
        injury: null,
        sitting_out: false,
        unbookable_until_week: null,
        contract: null,
        titles: [],
    };
}

const props = {
    game: { id: 1, player_brand: 'raw' },
    show: { id: 9, name: 'Royal Rumble', status: 'booking' },
    mode: 'rumble',
    needed: 2,
    pool: [
        wrestler(1, 'Edge', 'superstar'),
        wrestler(2, 'Kane', 'superstar'),
        wrestler(3, 'Trish Stratus', 'diva'),
    ],
    selected: [],
    rival: [],
    booking_order: { editable: true },
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn() },
    usePage: () => ({ props, url: '/gm/saves/1/ppv/9/entrants' }),
}));

vi.mock('@/components/gm/booking-steps', () => ({
    BookingAction: () => null,
    BookingSteps: () => null,
}));

vi.mock('@/components/gm/fatigue-meter', () => ({
    FatigueMeter: () => null,
}));

describe('PPV entrants', () => {
    it('greys out Divas in the Royal Rumble', () => {
        render(<PpvEntrants />);

        expect(screen.getByLabelText('Trish Stratus')).toBeDisabled();
        expect(screen.getByText('No Divas')).toBeInTheDocument();
        expect(screen.getByLabelText('Edge')).toBeEnabled();
        expect(screen.getByLabelText('Kane')).toBeEnabled();
    });
});
