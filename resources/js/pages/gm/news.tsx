import { Head, usePage } from '@inertiajs/react';
import { NewsGrid } from '@/components/gm/news-grid';
import type { NewsPage } from '@/components/gm/news-grid';
import { PageTitle } from '@/components/gm/panel';
import type { GamePageProps } from '@/types/gm';

type Props = GamePageProps & { pages: NewsPage[] };

export default function News() {
    const { pages } = usePage<Props>().props;

    return (
        <>
            <Head title="News" />
            <PageTitle kicker="Around the WWE" title="News" />
            <NewsGrid pages={pages} />
        </>
    );
}
