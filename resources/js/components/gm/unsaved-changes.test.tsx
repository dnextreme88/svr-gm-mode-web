import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    UnsavedChangesProvider,
    unsavedList,
    useReportUnsaved,
} from '@/components/gm/unsaved-changes';

const inertia = vi.hoisted(() => ({
    before: [] as Array<(event: { detail: { visit: unknown } }) => unknown>,
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    router: {
        on: (
            name: string,
            callback: (event: { detail: { visit: unknown } }) => unknown,
        ) => {
            inertia.before.push(callback);

            return () => {
                inertia.before = inertia.before.filter((c) => c !== callback);
            };
        },
        visit: inertia.visit,
    },
}));

function navigate(path: string) {
    const visit = {
        url: new URL(path, window.location.origin),
        method: 'get',
        data: {},
        headers: {},
        prefetch: false,
    };

    return inertia.before.map((c) => c({ detail: { visit } }));
}

/** A stand-in slot with a toggle for its unsaved state. */
function Slot({ name }: { name: string }) {
    const [dirty, setDirty] = useState(false);
    useReportUnsaved(name, dirty);

    return (
        <button type="button" onClick={() => setDirty((d) => !d)}>
            Edit {name}
        </button>
    );
}

describe('unsavedList', () => {
    it('lists matches first, then promos, each in order', () => {
        expect(unsavedList(['Match 2'])).toBe('Match 2');
        expect(unsavedList(['Promo 1', 'Match 2'])).toBe('Match 2 and Promo 1');
        expect(unsavedList(['Match 4', 'Match 1', 'Promo 2', 'Promo 1'])).toBe(
            'Matches 1 and 4 and Promos 1 and 2',
        );
        expect(unsavedList(['Match 3', 'Match 1', 'Match 5'])).toBe(
            'Matches 1, 3 and 5',
        );
    });
});

describe('UnsavedChangesProvider', () => {
    it('names unsaved matches and promos together before leaving', async () => {
        window.history.pushState({}, '', '/gm/saves/1/shows/3');
        const user = userEvent.setup();
        inertia.visit.mockReset();

        render(
            <UnsavedChangesProvider>
                <Slot name="Match 2" />
                <Slot name="Promo 1" />
            </UnsavedChangesProvider>,
        );

        // Nothing unsaved: the visit goes through.
        expect(navigate('/gm/saves/1/rosters')).not.toContain(false);

        await user.click(screen.getByRole('button', { name: 'Edit Match 2' }));
        await user.click(screen.getByRole('button', { name: 'Edit Promo 1' }));

        let results: unknown[] = [];
        act(() => {
            results = navigate('/gm/saves/1/rosters');
        });

        expect(results).toContain(false);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent(
            'Match 2 and Promo 1 have unsaved changes. Leave this page and discard them?',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Discard and leave' }),
        );
        expect(inertia.visit).toHaveBeenCalledWith(
            new URL('/gm/saves/1/rosters', window.location.origin),
            expect.objectContaining({ method: 'get' }),
        );
    });

    it('only names what is still unsaved', async () => {
        window.history.pushState({}, '', '/gm/saves/1/shows/3');
        const user = userEvent.setup();

        render(
            <UnsavedChangesProvider>
                <Slot name="Match 2" />
                <Slot name="Promo 1" />
            </UnsavedChangesProvider>,
        );

        await user.click(screen.getByRole('button', { name: 'Edit Match 2' }));
        await user.click(screen.getByRole('button', { name: 'Edit Promo 1' }));
        await user.click(screen.getByRole('button', { name: 'Edit Match 2' }));

        act(() => {
            navigate('/gm/saves/1/rosters');
        });

        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Promo 1 has unsaved changes.',
        );
    });
});
