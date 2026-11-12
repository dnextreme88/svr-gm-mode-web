import { Head, Link, usePage } from '@inertiajs/react';
import { PageTitle, Panel } from '@/components/gm/panel';
import gm from '@/routes/gm';

type Props = { status: number };

const COPY: Record<number, { title: string; body: string }> = {
    403: {
        title: 'Not allowed',
        body: 'That page isn’t open to you right now.',
    },
    404: {
        title: 'Page not found',
        body: 'There’s no page here. The career or show may have been deleted, or the link is out of date.',
    },
    500: {
        title: 'Something went wrong',
        body: 'The server hit a problem with that request. Your career is saved as it was before it.',
    },
    503: {
        title: 'Back soon',
        body: 'GM Mode is down for maintenance. Try again in a few minutes.',
    },
};

/**
 * The error page (UI audit, 2026-11-12): the GM Mode look and a way back to
 * the saves, instead of Laravel's bare white page.
 */
export default function ErrorPage() {
    const { status } = usePage<Props>().props;
    const copy = COPY[status] ?? COPY[500];

    return (
        <>
            <Head title={copy.title} />
            <PageTitle kicker={`Error ${status}`} title={copy.title} />
            <Panel>
                <p className="text-ink-dim">{copy.body}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <Link href={gm.saves.index.url()} className="gm-btn">
                        Back to GM Mode
                    </Link>
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost"
                        onClick={() => window.history.back()}
                    >
                        Go back
                    </button>
                </div>
            </Panel>
        </>
    );
}
