import { Head, Link, usePage } from '@inertiajs/react';
import { NewsGrid } from '@/components/gm/news-grid';
import type { NewsPage } from '@/components/gm/news-grid';
import { PageTitle } from '@/components/gm/panel';
import gm from '@/routes/gm';
import type { GamePageProps } from '@/types/gm';

type Props = GamePageProps & {
    pages: NewsPage[];
    // House rule (2026-11-16): the weekly show after a PPV night, the
    // Schedule after the re-draft, else the inbox.
    continue_to?: { name: string; url: string };
};

export default function News() {
    const { game, pages, continue_to } = usePage<Props>().props;
    const next = continue_to ?? { name: 'Inbox', url: gm.email.url(game.id) };
    const toCard = next.url.includes('/shows/');

    return (
        <>
            <Head title="News" />
            <PageTitle kicker="Around the WWE" title="News">
                <Link href={next.url} className="gm-btn">
                    {toCard ? `Continue to ${next.name}` : 'Continue'}
                </Link>
            </PageTitle>
            <NewsGrid pages={pages} />
        </>
    );
}
