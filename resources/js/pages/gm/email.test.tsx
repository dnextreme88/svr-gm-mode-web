import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Inbox from '@/pages/gm/email';

const body =
    'I accept your trade. The roster change is effective immediately.\n\nKane\nChristian\n\nfor\n\nBatista';

const trade = {
    id: 5,
    template: 'trade',
    sender: 'Smackdown GM',
    sender_kind: 'rival_gm',
    subject: 'Trade accepted',
    season: 1,
    week_number: 3,
    read: true,
};

const complaint = {
    id: 4,
    template: 'morale_complaint',
    sender: 'Kane',
    sender_kind: 'superstar',
    subject: "I've lost 5 in a row",
    season: 1,
    week_number: 2,
    read: false,
};

const baseProps = {
    game: { id: 1, player_brand: 'raw', unread_emails: 1 },
    emails: [trade, complaint],
    selected: { ...trade, sender_wrestler_id: null, body, payload: {} },
    categories: [
        { template: 'trade', total: 1, unread: 0 },
        { template: 'morale_complaint', total: 3, unread: 1 },
    ],
    filters: { category: null, view: 'all' },
    wrestlers: [
        { name: 'Kane', brand: 'raw' },
        { name: 'Batista', brand: 'smackdown' },
    ],
};

let props: Record<string, unknown> = baseProps;
let url = '/gm/saves/1/email';

const router = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router,
    usePage: () => ({ props, url }),
}));

beforeEach(() => {
    props = baseProps;
    url = '/gm/saves/1/email';
    Object.values(router).forEach((fn) => fn.mockClear());
});

describe('Inbox', () => {
    it('shows who sent the email and keeps the body’s line breaks', () => {
        render(<Inbox />);

        const reader = screen.getByRole('region', { name: 'Email' });

        expect(within(reader).getByText('Smackdown GM')).toBeInTheDocument();
        expect(
            within(reader).getByText(/Rival GM · to you/),
        ).toBeInTheDocument();

        const paragraph = screen.getByText(/I accept your trade/);

        expect(paragraph).toHaveClass('whitespace-pre-line');
        expect(paragraph.textContent).toBe(body);
    });

    it('names the commentator by brand', () => {
        props = {
            ...baseProps,
            selected: {
                ...baseProps.selected,
                sender: 'JR',
                sender_kind: 'commentator',
            },
        };
        render(<Inbox />);

        expect(
            screen.getByText(/Raw commentator · to you/),
        ).toBeInTheDocument();
    });

    it('lists each email with its sender, category and an unread dot', () => {
        render(<Inbox />);

        const list = screen.getByRole('region', { name: 'Inbox' });

        expect(within(list).getByText('Kane')).toBeInTheDocument();
        expect(within(list).getByText('Morale Complaint')).toBeInTheDocument();
        expect(
            within(list).getAllByRole('img', { name: 'Unread' }),
        ).toHaveLength(1);
    });

    it('filters by a category the inbox holds, with its counts', () => {
        render(<Inbox />);

        fireEvent.click(screen.getByRole('button', { name: 'Category' }));

        const options = screen.getAllByRole('option');

        expect(options.map((o) => o.textContent)).toEqual([
            'All categories (4)',
            'Morale Complaint13',
            'Trade1',
        ]);

        fireEvent.click(options[1]);

        expect(router.get).toHaveBeenCalledWith(
            '/gm/saves/1/email?category=morale_complaint',
            {},
            { preserveScroll: true },
        );
    });

    it('asks before deleting everything in the view, naming it', () => {
        props = {
            ...baseProps,
            emails: [complaint],
            selected: null,
            filters: { category: 'morale_complaint', view: 'all' },
        };
        render(<Inbox />);

        fireEvent.click(screen.getByRole('button', { name: /Delete all/ }));

        expect(
            screen.getByText(
                "Delete 3 Morale Complaint emails? This can't be undone.",
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(router.delete).toHaveBeenCalledWith(
            '/gm/saves/1/email?category=morale_complaint',
            expect.anything(),
        );
    });

    it('offers to clear the filters when nothing matches', () => {
        props = {
            ...baseProps,
            emails: [],
            selected: null,
            filters: { category: 'trade', view: 'unread' },
        };
        render(<Inbox />);

        expect(screen.getByText('No unread Trade emails.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

        expect(router.get).toHaveBeenCalledWith(
            '/gm/saves/1/email',
            {},
            { preserveScroll: true },
        );
    });

    it('opens on the list on small screens until an email is picked', () => {
        const { unmount } = render(<Inbox />);

        expect(screen.getByRole('region', { name: 'Email' })).toHaveClass(
            'hidden',
        );
        unmount();

        url = '/gm/saves/1/email?email=5';
        render(<Inbox />);

        expect(screen.getByRole('region', { name: 'Inbox' })).toHaveClass(
            'hidden',
        );
        expect(screen.getByRole('link', { name: /Inbox/ })).toHaveAttribute(
            'href',
            '/gm/saves/1/email',
        );
    });
});
