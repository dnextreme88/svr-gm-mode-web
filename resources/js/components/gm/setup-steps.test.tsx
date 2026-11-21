import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SetupSteps, WeekSteps } from '@/components/gm/setup-steps';

describe('SetupSteps (2026-11-21)', () => {
    it('highlights the draft as step 1', () => {
        render(<SetupSteps current={1} />);

        expect(screen.getByTestId('setup-step-1')).toHaveAttribute(
            'aria-current',
            'step',
        );
        expect(screen.getByTestId('setup-step-2')).not.toHaveAttribute(
            'aria-current',
        );
        expect(screen.getByTestId('setup-step-1')).toHaveTextContent(
            'Step 1 of 2: Draft your roster',
        );
    });

    it('highlights crowning the champions as step 2, the draft done', () => {
        render(<SetupSteps current={2} />);

        expect(screen.getByTestId('setup-step-2')).toHaveAttribute(
            'aria-current',
            'step',
        );
        expect(screen.getByTestId('setup-step-1')).toHaveTextContent(
            'Step 1 of 2, done: Draft your roster',
        );
    });

    it('animates the current step and fills the line once the draft is done', () => {
        const { rerender } = render(<SetupSteps current={1} />);

        expect(
            screen
                .getByTestId('setup-step-1')
                .querySelector('.gm-current-week'),
        ).not.toBeNull();
        expect(
            screen
                .getByTestId('setup-line-2')
                .querySelector('.gm-step-line-fill'),
        ).toBeNull();

        rerender(<SetupSteps current={2} />);

        expect(
            screen
                .getByTestId('setup-step-2')
                .querySelector('.gm-current-week'),
        ).not.toBeNull();
        expect(
            screen
                .getByTestId('setup-step-1')
                .querySelector('.gm-current-week'),
        ).toBeNull();
        expect(
            screen
                .getByTestId('setup-line-2')
                .querySelector('.gm-step-line-fill'),
        ).not.toBeNull();
    });
});

describe('WeekSteps (house rule, 2026-11-21)', () => {
    it('walks the week: book, results, Financials, News, Emails', () => {
        render(<WeekSteps current={3} />);

        const nav = screen.getByRole('navigation', { name: 'This week' });
        expect(nav).toHaveTextContent(
            /Book the week.*Show results.*Weekly financials.*News.*Emails/,
        );
        expect(screen.getByTestId('week-step-3')).toHaveAttribute(
            'aria-current',
            'step',
        );
        expect(screen.getByTestId('week-step-2')).toHaveTextContent(', done');
    });

    it('names the PPV on a PPV night', () => {
        render(<WeekSteps current={1} ppv="Backlash" />);

        expect(screen.getByTestId('week-step-1')).toHaveTextContent(
            'Book Backlash',
        );
        expect(screen.getByTestId('week-step-2')).toHaveTextContent(
            'Backlash results',
        );
        expect(screen.getByTestId('week-step-3')).toHaveTextContent(
            'Backlash financials',
        );
    });
});
