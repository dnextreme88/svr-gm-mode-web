import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FactionPromptModal } from '@/components/gm/faction-prompt-modal';
import type { FactionPrompt } from '@/types/gm';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: { game: { player_brand: 'raw' } } }),
}));

const prompt: FactionPrompt = {
    id: 5,
    wins: 3,
    show: 'Monday Night Raw',
    default_type: 'tag_team',
    name_max_length: 40,
    members: [
        {
            id: 1,
            name: 'John Cena',
            roster_type: 'superstar',
            weight_class: 'heavyweight',
        },
        {
            id: 2,
            name: 'Kane',
            roster_type: 'superstar',
            weight_class: 'super_heavyweight',
        },
    ],
};

describe('FactionPromptModal', () => {
    beforeEach(() => post.mockReset());

    it('names the team, then confirms before forming it', async () => {
        const user = userEvent.setup();
        render(<FactionPromptModal saveId={9} prompt={prompt} brand="raw" />);

        expect(screen.getByTestId('faction-prompt-members')).toHaveTextContent(
            'John Cena & Kane',
        );
        expect(
            screen.getByRole('button', { name: 'Form faction' }),
        ).toBeDisabled();

        await user.type(screen.getByLabelText('Faction name'), 'Cenation');
        await user.click(screen.getByRole('button', { name: 'Next type' }));
        expect(screen.getByTestId('faction-prompt-type')).toHaveTextContent(
            'Stable',
        );

        await user.click(screen.getByRole('button', { name: 'Form faction' }));

        expect(screen.getByText('Form Cenation?')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Form faction' }));

        expect(post).toHaveBeenCalledWith(
            '/gm/saves/9/faction-prompts/5/form',
            { name: 'Cenation', type: 'stable' },
            expect.anything(),
        );
    });

    it('puts the team off with Not now', async () => {
        const user = userEvent.setup();
        render(<FactionPromptModal saveId={9} prompt={prompt} brand="raw" />);

        await user.click(screen.getByRole('button', { name: 'Not now' }));

        expect(post).toHaveBeenCalledWith(
            '/gm/saves/9/faction-prompts/5/defer',
            {},
            expect.anything(),
        );
    });
});
