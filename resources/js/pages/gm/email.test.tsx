import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Inbox from '@/pages/gm/email';

const body =
    'I accept your trade. The roster change is effective immediately.\n\nKane\nChristian\n\nfor\n\nBatista';

const props = {
    game: { id: 1, player_brand: 'raw', unread_emails: 0 },
    emails: [
        {
            id: 5,
            template: 'trade',
            sender: 'SmackDown! GM',
            subject: 'Trade accepted',
            week_number: 3,
            read: true,
        },
    ],
    selected: {
        id: 5,
        template: 'trade',
        sender: 'SmackDown! GM',
        subject: 'Trade accepted',
        week_number: 3,
        body,
        payload: {},
    },
    wrestlers: [
        { name: 'Kane', brand: 'raw' },
        { name: 'Batista', brand: 'smackdown' },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn() },
    usePage: () => ({ props }),
}));

describe('Inbox', () => {
    it('shows who sent the email and keeps the body’s line breaks', () => {
        render(<Inbox />);

        expect(screen.getByText(/From SmackDown! GM/)).toBeInTheDocument();

        const paragraph = screen.getByText(/I accept your trade/);

        expect(paragraph).toHaveClass('whitespace-pre-line');
        expect(paragraph.textContent).toBe(body);
    });
});
