import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FloppyIcon } from '@/components/gm/floppy-icon';

const floppy = () => screen.getByTestId('floppy-icon');

describe('FloppyIcon', () => {
    it('is still when idle', () => {
        render(<FloppyIcon />);

        expect(floppy()).toHaveAttribute('data-status', 'idle');
        expect(floppy()).toHaveAttribute('aria-hidden', 'true');
        expect(floppy().querySelector('.animate-gm-floppy-write')).toBeNull();
        expect(screen.queryByTestId('floppy-check')).toBeNull();
    });

    it('slides the shutter and blinks the label while saving', () => {
        render(<FloppyIcon status="saving" />);

        expect(floppy()).toHaveAttribute('data-status', 'saving');
        expect(
            floppy().querySelector('.animate-gm-floppy-write'),
        ).not.toBeNull();
        expect(
            floppy().querySelector('.animate-gm-floppy-blink'),
        ).not.toBeNull();
        expect(screen.queryByTestId('floppy-check')).toBeNull();
    });

    it('pops a check once saved', () => {
        render(<FloppyIcon status="saved" />);

        expect(floppy()).toHaveAttribute('data-status', 'saved');
        expect(screen.getByTestId('floppy-check')).toHaveClass(
            'animate-gm-check-pop',
        );
        expect(floppy().querySelector('.animate-gm-floppy-write')).toBeNull();
    });
});
