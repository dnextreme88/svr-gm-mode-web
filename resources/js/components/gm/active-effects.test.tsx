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
        // Gold only while something is in play.
        expect(box.className).not.toContain('border-gold');
    });

    it('lists a Rematch Clause with the weeks left (house rule, 2026-11-07)', () => {
        const rematch = {
            id: 4,
            championship_id: 1,
            championship: 'WWE Championship',
            former_ids: [10],
            former: 'John Cena',
            champion_ids: [11],
            champions: 'Edge',
            weeks_left: 3,
            booked: false,
        };
        const { rerender } = render(
            <ActiveEffects
                context={{ ...empty, rematch_clauses: [rematch] }}
                buriedAlive={[]}
            />,
        );

        const box = screen.getByRole('region', { name: 'Active Effects' });
        expect(box).toHaveTextContent('Rematch Clause');
        expect(box).toHaveTextContent(
            'John Cena vs. Edge · WWE Championship · 3 weeks left',
        );
        expect(box.className).toContain('border-gold');

        rerender(
            <ActiveEffects
                context={{
                    ...empty,
                    rematch_clauses: [{ ...rematch, booked: true }],
                }}
                buriedAlive={[]}
            />,
        );
        expect(box).toHaveTextContent('booked on this card');
    });

    it('turns gold while something is in play', () => {
        render(
            <ActiveEffects
                context={empty}
                buriedAlive={[{ id: 1, name: 'Kane', weeks_left: 2 }]}
            />,
        );

        expect(
            screen.getByRole('region', { name: 'Active Effects' }).className,
        ).toContain('border-gold');
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

    it('lists the brand rivalries with their heat and turns gold', () => {
        render(
            <ActiveEffects
                context={empty}
                buriedAlive={[]}
                rivalries={[
                    {
                        id: 3,
                        wrestler_a: 'Edge',
                        wrestler_b: 'John Cena',
                        heat: 40,
                    },
                ]}
            />,
        );

        const region = screen.getByRole('region', { name: 'Active Effects' });

        expect(region).toHaveTextContent('RivalryEdge vs. John Cena · Heat 40');
        expect(region).toHaveClass('border-gold');
        expect(region).not.toHaveTextContent('No active effects');
    });
});
