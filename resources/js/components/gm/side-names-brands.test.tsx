import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SideNames } from '@/components/gm/champion-tag';

describe('SideNames brand labels (2026-11-15)', () => {
    it('adds each superstar’s brand on the matches between the brands', () => {
        render(
            <p data-testid="names">
                <SideNames
                    names={['Val Venis']}
                    champions={[]}
                    brands={{
                        'Val Venis': 'Raw',
                        'Road Warrior Animal': 'Smackdown',
                    }}
                />{' '}
                vs{' '}
                <SideNames
                    names={['Road Warrior Animal']}
                    champions={[]}
                    brands={{
                        'Val Venis': 'Raw',
                        'Road Warrior Animal': 'Smackdown',
                    }}
                />
            </p>,
        );

        expect(screen.getByTestId('names')).toHaveTextContent(
            'Val Venis(Raw) vs Road Warrior Animal(Smackdown)',
        );
    });

    it('adds nothing on other matches', () => {
        render(<SideNames names={['Edge']} champions={[]} />);

        expect(screen.queryByTestId('brand-label')).toBeNull();
    });
});
