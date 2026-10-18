import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { nameTone, WrestlerName } from '@/components/gm/wrestler-name';

describe('SvR 2006 name colour coding', () => {
    it('colours Cruiserweights and Light Heavyweights blue and Divas red', () => {
        expect(
            nameTone({
                roster_type: 'superstar',
                weight_class: 'cruiserweight',
            }),
        ).toBe('cruiser');
        expect(
            nameTone({
                roster_type: 'superstar',
                weight_class: 'light_heavyweight',
            }),
        ).toBe('cruiser');
        expect(
            nameTone({
                roster_type: 'legend',
                weight_class: 'light_heavyweight',
            }),
        ).toBe('cruiser');
        expect(
            nameTone({ roster_type: 'diva', weight_class: 'featherweight' }),
        ).toBe('diva');
        expect(
            nameTone({ roster_type: 'superstar', weight_class: 'heavyweight' }),
        ).toBeNull();
    });

    it('renders the matching class', () => {
        render(
            <>
                <WrestlerName
                    wrestler={{
                        name: 'Rey Mysterio',
                        roster_type: 'superstar',
                        weight_class: 'cruiserweight',
                    }}
                />
                <WrestlerName
                    wrestler={{
                        name: 'Lita',
                        roster_type: 'diva',
                        weight_class: 'featherweight',
                    }}
                />
                <WrestlerName
                    wrestler={{
                        name: 'Batista',
                        roster_type: 'superstar',
                        weight_class: 'heavyweight',
                    }}
                />
            </>,
        );

        expect(screen.getByText('Rey Mysterio')).toHaveClass('gm-name-cruiser');
        expect(screen.getByText('Lita')).toHaveClass('gm-name-diva');
        expect(screen.getByText('Batista')).not.toHaveAttribute('data-tone');
    });
});
