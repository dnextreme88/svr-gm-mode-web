import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Saves from '@/pages/gm/saves/index';

const router = vi.hoisted(() => ({ visit: vi.fn() }));
const requestJson = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router,
    usePage: () => ({ props: {} }),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setAll] = useState(initial);

        return {
            data,
            errors: {},
            setData: (key: keyof T, value: unknown) =>
                setAll((d) => ({ ...d, [key]: value })),
            setError: vi.fn(),
            clearErrors: vi.fn(),
        };
    },
}));

vi.mock('@/lib/http', () => ({
    HttpError: class extends Error {},
    requestJson,
}));

describe('new game rosters', () => {
    beforeEach(() => {
        router.visit.mockReset();
        requestJson.mockReset();
    });

    const renderPage = () =>
        render(<Saves saves={[]} unsaved={null} starting_budget={15000000} />);

    it('defaults to the manual draft', () => {
        renderPage();

        expect(
            screen.getByRole('radio', { name: /manual draft pick/i }),
        ).toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Start the draft' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Draft Rules:')).toBeInTheDocument();
    });

    it('signs the WWE rosters and goes straight to the next screen', async () => {
        requestJson.mockResolvedValue({
            next_url: '/gm/saves/1/titles/assign',
            first_brand: 'raw',
        });
        renderPage();

        await userEvent.click(
            screen.getByRole('radio', { name: /use wwe roster/i }),
        );

        expect(screen.getByText('Roster Rules:')).toBeInTheDocument();

        await userEvent.click(
            screen.getByRole('button', { name: 'Sign the rosters' }),
        );

        expect(requestJson).toHaveBeenCalledWith(
            'POST',
            expect.any(String),
            expect.objectContaining({ draft_mode: 'wwe_roster' }),
        );
        expect(router.visit).toHaveBeenCalledWith('/gm/saves/1/titles/assign');
        expect(screen.queryByText(/who picks first/i)).not.toBeInTheDocument();
    });
});
