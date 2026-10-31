import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AttendanceChip } from '@/components/gm/attendance-chip';
import type { AttendanceBreakdown } from '@/components/gm/attendance-chip';

const breakdown: AttendanceBreakdown = {
    kind: 'weekly',
    factors: {
        full_card: 10,
        main_event: 20,
        match_one: 4,
        hot_rivalries: 10,
        legends: 8,
        promos: 10,
        win_streak: 5,
    },
    noise: -2,
    score: 65,
    gate: 451500,
};

describe('AttendanceChip', () => {
    it('shows the crowd and the gate, with the factors on hover', () => {
        render(<AttendanceChip attendance={15050} breakdown={breakdown} />);

        const chip = screen.getByTestId('attendance-chip');

        expect(chip).toHaveTextContent('Attendance 15,050 · Gate $451,500');
        expect(chip.className).toContain('text-gold-hot');
        expect(chip.getAttribute('title')).toContain('Main Event: 20');
        expect(chip.getAttribute('title')).toContain('Luck: -2');
        expect(chip.getAttribute('title')).toContain('Draw score: 65 / 100');
    });

    it('shows nothing before the night has aired', () => {
        const { container } = render(
            <AttendanceChip attendance={null} breakdown={null} />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
