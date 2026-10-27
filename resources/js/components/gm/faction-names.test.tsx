import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SideNames } from '@/components/gm/champion-tag';
import { groupSide } from '@/lib/factions';

const teamXtreme = {
    name: 'Team Xtreme',
    members: ['Matt Hardy', 'Jeff Hardy'],
};

describe('groupSide', () => {
    it('collapses a faction where its first member was and keeps the rest', () => {
        const items = groupSide(
            ['Kane', 'Matt Hardy', 'Jeff Hardy'],
            (n) => n,
            [teamXtreme],
        );

        expect(items).toEqual([
            { kind: 'member', member: 'Kane' },
            {
                kind: 'faction',
                name: 'Team Xtreme',
                members: ['Matt Hardy', 'Jeff Hardy'],
            },
        ]);
    });

    it('leaves a side alone when the faction is not all on it', () => {
        expect(groupSide(['Matt Hardy'], (n) => n, [teamXtreme])).toEqual([
            { kind: 'member', member: 'Matt Hardy' },
        ]);
    });
});

describe('SideNames', () => {
    it('shows the faction name, with its members on hover and the (C) tag', () => {
        const { container } = render(
            <SideNames
                names={['Matt Hardy', 'Jeff Hardy', 'Kane']}
                champions={['Matt Hardy', 'Jeff Hardy']}
                factions={[teamXtreme]}
            />,
        );

        expect(container.textContent).toBe('Team Xtreme(C) & Kane');
        expect(screen.getByText('Team Xtreme')).toHaveAttribute(
            'title',
            'Matt Hardy & Jeff Hardy',
        );
    });

    it('joins plain names when no faction applies', () => {
        const { container } = render(
            <SideNames names={['Edge', 'Kane']} champions={[]} />,
        );

        expect(container.textContent).toBe('Edge & Kane');
    });
});
