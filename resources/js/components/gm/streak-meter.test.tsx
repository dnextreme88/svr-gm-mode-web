import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StreakMeter } from '@/components/gm/streak-meter';

const filled = () => document.querySelectorAll('[data-state="filled"]').length;

describe('StreakMeter', () => {
    it('shows one bar per win with a winning label', () => {
        render(<StreakMeter wins={1} losses={0} />);

        expect(filled()).toBe(1);
        expect(screen.getByText('1 winning streak')).toBeInTheDocument();
    });

    it('shows a losing streak', () => {
        render(<StreakMeter wins={0} losses={5} />);

        expect(filled()).toBe(5);
        expect(screen.getByText('5 losing streak')).toBeInTheDocument();
    });

    it('caps the bars but keeps the full count in the label', () => {
        render(<StreakMeter wins={14} losses={0} />);

        expect(filled()).toBe(10);
        expect(screen.getByText('14 winning streak')).toBeInTheDocument();
    });

    it('says so when there is no streak', () => {
        render(<StreakMeter wins={0} losses={0} />);

        expect(screen.getByText('No streak')).toBeInTheDocument();
    });
});
