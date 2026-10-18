import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { OutcomeCard } from '@/components/gm/outcome-card';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import gm from '@/routes/gm';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type ShowResult = CardShow & { outcomes: MatchOutcome[] };

type Props = GamePageProps & {
    week_number: number;
    shows: ShowResult[];
    new_emails: number;
};

export default function Results() {
    const { game, week_number, shows, new_emails } = usePage<Props>().props;
    const reel = useMemo(
        () =>
            shows.flatMap((show) =>
                show.outcomes.map((outcome, i) => ({
                    show,
                    outcome,
                    isMainEvent:
                        i === show.outcomes.length - 1 &&
                        show.outcomes.length > 1,
                })),
            ),
        [shows],
    );
    const [index, setIndex] = useState(0);
    const done = index >= reel.length;

    const next = useCallback(
        () => setIndex((i) => Math.min(i + 1, reel.length)),
        [reel.length],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowRight') {
                e.preventDefault();
                next();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [next]);

    return (
        <>
            <Head title={`Week ${week_number} results`} />
            <PageTitle
                kicker={`Week ${week_number}`}
                title={done ? 'That’s a wrap' : 'Live results'}
            >
                {!done && reel.length > 0 && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={() => setIndex(reel.length)}
                    >
                        Skip to summary
                    </button>
                )}
                <Link href={gm.schedule.url(game.id)} className="gm-btn">
                    Continue
                </Link>
            </PageTitle>

            {reel.length === 0 && (
                <EmptyState>No matches were booked this week.</EmptyState>
            )}

            {!done && reel[index] && (
                <div className="mx-auto max-w-4xl">
                    <button
                        type="button"
                        onClick={next}
                        className="block w-full text-left"
                        aria-label="Next result"
                    >
                        <OutcomeCard
                            key={index}
                            outcome={reel[index].outcome}
                            showName={reel[index].show.name}
                            isMainEvent={reel[index].isMainEvent}
                        />
                    </button>
                    <div className="mt-4 flex items-center justify-between text-sm text-ink-faint">
                        <span className="gm-num">
                            {index + 1} / {reel.length}
                        </span>
                        <span className="font-condensed tracking-wider uppercase">
                            Press Enter or click to continue
                        </span>
                    </div>
                </div>
            )}

            {done && reel.length > 0 && (
                <div className="grid gap-6 lg:grid-cols-2">
                    {shows.map((show) => (
                        <Panel
                            key={show.id}
                            title={show.name}
                            bodyClassName="p-0"
                        >
                            <ol className="divide-y divide-white/5">
                                {show.outcomes.map((o) => (
                                    <li key={o.position} className="px-4 py-3">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="font-semibold">
                                                {o.winners.join(' & ')}
                                                <span className="mx-1.5 text-ink-faint">
                                                    def.
                                                </span>
                                                {o.losers.join(', ')}
                                            </span>
                                            <span className="gm-label whitespace-nowrap">
                                                {o.method_label}
                                            </span>
                                        </div>
                                        <div className="mt-0.5 text-xs text-ink-dim">
                                            {o.match_type}
                                            {o.title && ` · ${o.title}`}
                                            {o.title_banner ===
                                                'new_champion' && (
                                                <span className="ml-2 font-bold text-gold-hot">
                                                    NEW CHAMPION
                                                </span>
                                            )}
                                            {o.stipulation_outcome &&
                                                ` · ${o.stipulation_outcome}`}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </Panel>
                    ))}
                </div>
            )}

            {done && new_emails > 0 && (
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
