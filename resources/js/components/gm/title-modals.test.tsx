import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NoticeModal } from '@/components/gm/notice-modal';
import { TitleShotReminder } from '@/components/gm/title-shot-reminder';
import { TitleVacancyModal } from '@/components/gm/title-vacancy-modal';

const router = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => ({ props: {} }),
}));

beforeEach(() => router.post.mockClear());

const shot = {
    id: 4,
    championship_id: 1,
    championship: 'WWE Championship',
    contender_ids: [2],
    contenders: 'Kane',
    booked: false,
};

describe('Title shot reminder (house rule)', () => {
    it('reminds you of an earned shot and books it on request', async () => {
        const user = userEvent.setup();
        const onBook = vi.fn();
        render(<TitleShotReminder shots={[shot]} canBook onBook={onBook} />);

        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent(
            'Kane earned a WWE Championship match by winning a Champion vs. Contender match',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Book it now' }),
        );

        expect(onBook).toHaveBeenCalled();
    });

    it('closes on Later, and shows nothing without a shot', async () => {
        const user = userEvent.setup();
        const { unmount } = render(
            <TitleShotReminder shots={[shot]} canBook onBook={vi.fn()} />,
        );

        await user.click(screen.getByRole('button', { name: 'Later' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        unmount();

        render(<TitleShotReminder shots={[]} canBook onBook={vi.fn()} />);
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});

describe('Vacant title with a #1 contender (house rule)', () => {
    const prompt = {
        id: 9,
        title: 'Intercontinental Championship',
        contenders: 'Vito',
        contender_count: 1,
    };

    it('awards the title after a confirmation', async () => {
        const user = userEvent.setup();
        render(<TitleVacancyModal saveId={3} prompt={prompt} />);

        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Vito was waiting as #1 contender',
        );
        await user.click(
            screen.getByRole('button', { name: 'Award the title' }),
        );
        await user.click(screen.getByRole('button', { name: 'Award it' }));

        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/3/title-prompts/9/award',
            {},
            expect.anything(),
        );
    });

    it('forfeits the contendership after a confirmation', async () => {
        const user = userEvent.setup();
        render(<TitleVacancyModal saveId={3} prompt={prompt} />);

        await user.click(screen.getByRole('button', { name: 'Forfeit' }));
        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Forfeit',
            }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/3/title-prompts/9/forfeit',
            {},
            expect.anything(),
        );
    });
});

describe('GM notices (house rule)', () => {
    it('lists voided contenderships and marks them seen on OK', async () => {
        const user = userEvent.setup();
        render(
            <NoticeModal
                saveId={3}
                notices={[
                    {
                        id: 1,
                        kind: 'contendership_voided',
                        week: 5,
                        title: 'WWE Championship',
                        contenders: 'Kane',
                        reason: 'Kane suffered a serious injury',
                    },
                ]}
            />,
        );

        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Kane won’t get their WWE Championship match: Kane suffered a serious injury.',
        );
        await user.click(screen.getByRole('button', { name: 'OK' }));

        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/3/notices/seen',
            {},
            expect.anything(),
        );
    });
});
