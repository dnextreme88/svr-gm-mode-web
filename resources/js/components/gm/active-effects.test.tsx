import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ActiveEffects } from '@/components/gm/active-effects';
import type { TitleContext } from '@/types/gm';

const empty: TitleContext = {
    championships: [],
    lockouts: {},
    last_chance: [],
    title_shots: [],
    briefcase: null,
};

describe('Active Effects (house rule)', () => {
    it('is always there, saying when nothing is in play', () => {
        render(<ActiveEffects context={empty} buriedAlive={[]} />);

        const box = screen.getByRole('region', { name: 'Active Effects' });
        expect(box).toHaveTextContent('No active effects at the moment');
    });

    it('lists every effect in play', () => {
        render(
            <ActiveEffects
                context={{
                    ...empty,
                    title_shots: [
                        {
                            id: 1,
                            championship_id: 5,
                            championship: 'World Heavyweight Championship',
                            contender_ids: [2],
                            contenders: 'Kane',
                            booked: false,
                        },
                    ],
                    contenderships: [
                        {
                            championship_id: 6,
                            championship: 'United States Championship',
                            contender_ids: [3],
                            contenders: 'Vito',
                        },
                    ],
                    last_chance_lockouts: [
                        {
                            id: 1,
                            championship: 'World Heavyweight Championship',
                            contenders: 'Chris Benoit',
                            champion: 'Daivari',
                        },
                    ],
                    briefcase: {
                        id: 1,
                        holder: 'Edge',
                        expires_week: 52,
                        cashed_in_here: false,
                    },
                }}
                buriedAlive={[{ id: 9, name: 'Heidenreich', weeks_left: 3 }]}
            />,
        );

        const items = within(
            screen.getByRole('region', { name: 'Active Effects' }),
        )
            .getAllByRole('listitem')
            .map((li) => li.textContent);

        expect(items).toEqual([
            'Required title matchKane earned a World Heavyweight Championship match. Book it before this card can air.',
            '#1 contenderVito awaits a United States Championship match as #1 contender. Nobody else can challenge for it.',
            'Last ChanceChris Benoit lost a Last Chance match and can’t contend for the World Heavyweight Championship while Daivari is champion.',
            'Money in the BankEdge holds the Money in the Bank briefcase: a world title shot on any card until week 52.',
            'Buried AliveHeidenreich was buried alive and is out for 3 more weeks.',
        ]);
    });

    it('drops a title shot once it is booked', () => {
        render(
            <ActiveEffects
                context={{
                    ...empty,
                    title_shots: [
                        {
                            id: 1,
                            championship_id: 5,
                            championship: 'World Heavyweight Championship',
                            contender_ids: [2],
                            contenders: 'Kane',
                            booked: true,
                        },
                    ],
                }}
                buriedAlive={[]}
            />,
        );

        expect(
            screen.getByRole('region', { name: 'Active Effects' }),
        ).toHaveTextContent('No active effects at the moment');
    });
});
