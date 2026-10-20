import { Head, Link, usePage } from '@inertiajs/react';
import { NewsGrid } from '@/components/gm/news-grid';
import type { NewsPage } from '@/components/gm/news-grid';
import { PageTitle } from '@/components/gm/panel';
import gm from '@/routes/gm';
import type { GamePageProps } from '@/types/gm';

type Props = GamePageProps & { pages: NewsPage[] };

export default function News() {
    const { game, pages } = usePage<Props>().props;

    return (
        <>
            <Head title="News" />
            <PageTitle kicker="Around the WWE" title="News">
                <Link href={gm.email.url(game.id)} className="gm-btn">
                    Continue
                </Link>
            </PageTitle>
            <NewsGrid pages={pages} />
        </>
    );
}
