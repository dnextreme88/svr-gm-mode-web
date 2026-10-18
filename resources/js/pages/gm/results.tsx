import { Head, Link, usePage } from '@inertiajs/react';
import { PageTitle, Panel } from '@/components/gm/panel';
import gm from '@/routes/gm';
import type { Brand, ShowType } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type ShowResult = {
    id: number;
    name: string;
    show_type: ShowType;
    brand: Brand | null;
    outcomes: unknown[];
};

type Props = GamePageProps & {
    week_number: number;
    shows: ShowResult[];
    new_emails: number;
};

export default function Results() {
    const { game, week_number, shows, new_emails } = usePage<Props>().props;

    return (
        <>
            <Head title={`Week ${week_number} results`} />
            <PageTitle kicker={`Week ${week_number}`} title="That’s a wrap">
                <Link href={gm.schedule.url(game.id)} className="gm-btn">
                    Continue
                </Link>
            </PageTitle>

            <div className="grid gap-4 md:grid-cols-2">
                {shows.map((show) => (
                    <Panel key={show.id} title={show.name}>
                        <p className="text-sm text-ink-dim">
                            {show.show_type === 'ppv' ? 'PPV' : 'Weekly show'}{' '}
                            aired.
                        </p>
                    </Panel>
                ))}
            </div>

            {new_emails > 0 && (
                <p className="mt-6 text-sm text-ink-dim">
                    {new_emails} new email(s).{' '}
                    <Link
                        href={gm.email.url(game.id)}
                        className="text-brand-hot underline underline-offset-4"
                    >
                        Open inbox
                    </Link>
                </p>
            )}
        </>
    );
}
