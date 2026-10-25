import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const listeners = vi.hoisted(() => [] as ((event: Event) => void)[]);
const toastSuccess = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: {
        on: (name: string, cb: (event: Event) => void) => {
            if (name === 'flash') {
                listeners.push(cb);
            }

            return () => listeners.splice(listeners.indexOf(cb), 1);
        },
    },
    usePage: () => ({ props: {}, url: '/gm' }),
    Link: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock('sonner', async (original) => ({
    ...(await original<typeof import('sonner')>()),
    toast: { success: toastSuccess, error: vi.fn(), info: vi.fn() },
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'dark' }),
}));

const { Toaster } = await import('@/components/ui/sonner');
const { default: GmLayout } = await import('@/layouts/gm-layout');

describe('flash toasts', () => {
    beforeEach(() => {
        listeners.length = 0;
        toastSuccess.mockClear();
    });

    it('shows one toast per flash on a GM page', () => {
        render(
            <>
                <GmLayout>
                    <p>Free Agency</p>
                </GmLayout>
                <Toaster />
            </>,
        );

        const event = new CustomEvent('flash', {
            detail: {
                flash: {
                    toast: {
                        type: 'success',
                        message:
                            'Big Show signed. They join the roster next week.',
                    },
                },
            },
        });
        listeners.forEach((cb) => cb(event));

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(toastSuccess).toHaveBeenCalledWith(
            'Big Show signed. They join the roster next week.',
        );
    });
});
