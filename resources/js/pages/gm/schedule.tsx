import { Head, Link, usePage } from '@inertiajs/react';
import { PageTitle } from '@/components/gm/panel';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, BrandScope, ShowStatus, ShowType } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type WeekCell = {
    week: number;
    season_week: number;
    /** The week's Sunday, when PPVs air. */
    date: string;
    /** Each brand's weekly show night (Raw Monday, SmackDown Friday). */
    show_dates: Record<Brand, string>;
    ppvs: Array<{ key: string; name: string; brand_scope: BrandScope }>;
};

type ShowSummary = {
    id: number;
    name: string;
    show_type: ShowType;
    status: ShowStatus;
};

type Props = GamePageProps & {
    season: number;
    seasons: number[];
    months: Array<{ month: string; weeks: WeekCell[] }>;
    shows: Record<string, ShowSummary[]>;
};

export default function Schedule() {
    const { game, season, seasons, months, shows } = usePage<Props>().props;

    return (
        <>
            <Head title="Schedule" />
            <PageTitle kicker={`Season ${season}`} title="Schedule">
                {seasons.length > 1 && (
                    <nav
                        aria-label="Seasons"
                        className="flex flex-wrap items-center gap-1"
                    >
                        <span className="gm-label mr-1">Season</span>
                        {seasons.map((n) => (
                            <Link
                                key={n}
                                href={gm.schedule.url(game.id, {
                                    query:
                                        n === game.season ? {} : { season: n },
                                })}
                                aria-current={n === season ? 'page' : undefined}
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    n !== season && 'gm-btn-ghost',
                                )}
                            >
                                {n}
                            </Link>
                        ))}
                    </nav>
                )}
            </PageTitle>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {months.map(({ month, weeks }) => (
                    <section key={weeks[0]?.week ?? month} className="gm-panel">
                        <h2 className="gm-heading border-b border-white/6 px-4 py-2 text-lg text-ink">
                            {month}
                        </h2>
                        <ol className="divide-y divide-white/5">
                            {weeks.map((cell) => {
                                const isCurrent = cell.week === game.week;
                                const isPast = cell.week < game.week;
                                const yourPpv = cell.ppvs.find(
                                    (p) =>
                                        p.brand_scope === 'shared' ||
                                        p.brand_scope === game.player_brand,
                                );
                                const rivalPpv = cell.ppvs.find(
                                    (p) => p.brand_scope === game.rival_brand,
                                );
                                const aired = (shows[cell.week] ?? []).some(
                                    (s) => s.status === 'aired',
                                );

                                return (
                                    <li
                                        key={cell.week}
                                        aria-current={
                                            isCurrent ? 'date' : undefined
                                        }
                                        className={cn(
                                            'flex items-center gap-3 px-4 py-2 text-sm',
                                            isCurrent &&
                                                'bg-brand/15 shadow-[inset_3px_0_0_var(--gm-brand)]',
                                            isPast && !aired && 'opacity-55',
                                            isPast && aired && 'text-ink-dim',
                                        )}
                                    >
                                        <span className="gm-num w-10 text-ink-faint">
                                            W{cell.season_week}
                                        </span>
                                        <span className="w-20 font-condensed text-xs text-ink-dim uppercase">
                                            {cell.show_dates[game.player_brand]}
                                        </span>
                                        <span className="flex-1">
                                            <span className="text-ink-dim">
                                                Weekly show
                                            </span>
                                            {yourPpv && (
                                                <span className="block font-condensed font-bold tracking-wide text-gold-hot uppercase">
                                                    {yourPpv.name}
                                                    <span className="ml-1.5 text-xs font-normal tracking-wider text-ink-dim">
                                                        {cell.date}
                                                    </span>
                                                </span>
                                            )}
                                            {rivalPpv && (
                                                <span className="block font-condensed text-xs tracking-wider text-ink-faint uppercase">
                                                    rival: {rivalPpv.name} ·{' '}
                                                    {cell.date}
                                                </span>
                                            )}
                                        </span>
                                        {isCurrent &&
                                            (shows[cell.week] ?? []).map(
                                                (show) => (
                                                    <Link
                                                        key={show.id}
                                                        href={gm.card.show.url({
                                                            save: game.id,
                                                            show: show.id,
                                                        })}
                                                        className="gm-btn gm-btn-sm"
                                                    >
                                                        {show.show_type ===
                                                        'ppv'
                                                            ? 'Book PPV'
                                                            : 'Book show'}
                                                    </Link>
                                                ),
                                            )}
                                        {aired && !isCurrent && (
                                            <Link
                                                href={gm.results.url({
                                                    save: game.id,
                                                    week: cell.week,
                                                })}
                                                className="font-condensed text-xs font-semibold tracking-wider text-ink-dim uppercase underline-offset-4 hover:text-ink hover:underline"
                                                aria-label={`Results for week ${cell.season_week}`}
                                            >
                                                ✓ Results
                                            </Link>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                ))}
            </div>
        </>
    );
}
