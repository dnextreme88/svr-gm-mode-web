import { Head, usePage } from '@inertiajs/react';
import { PageTitle } from '@/components/gm/panel';
import { cn } from '@/lib/utils';
import type { BrandScope, ShowStatus, ShowType } from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type WeekCell = {
    week: number;
    season_week: number;
    date: string;
    ppvs: Array<{ key: string; name: string; brand_scope: BrandScope }>;
};

type ShowSummary = {
    id: number;
    name: string;
    show_type: ShowType;
    status: ShowStatus;
};

type Props = GamePageProps & {
    months: Array<{ month: string; weeks: WeekCell[] }>;
    shows: Record<string, ShowSummary[]>;
};

export default function Schedule() {
    const { game, months, shows } = usePage<Props>().props;

    return (
        <>
            <Head title="Schedule" />
            <PageTitle kicker={`Season ${game.season}`} title="Schedule" />

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
                                                'bg-brand/15 shadow-[inset_3px_0_0_var(--color-brand)]',
                                            isPast && 'opacity-55',
                                        )}
                                    >
                                        <span className="gm-num w-10 text-ink-faint">
                                            W{cell.season_week}
                                        </span>
                                        <span className="w-14 font-condensed text-xs text-ink-dim uppercase">
                                            {cell.date}
                                        </span>
                                        <span className="flex-1">
                                            {yourPpv ? (
                                                <span className="font-condensed font-bold tracking-wide text-gold-hot uppercase">
                                                    {yourPpv.name}
                                                </span>
                                            ) : (
                                                <span className="text-ink-dim">
                                                    Weekly show
                                                </span>
                                            )}
                                            {rivalPpv && (
                                                <span className="ml-2 font-condensed text-[0.65rem] tracking-wider text-ink-faint uppercase">
                                                    rival: {rivalPpv.name}
                                                </span>
                                            )}
                                        </span>
                                        {isCurrent && (
                                            <span className="font-condensed text-[0.65rem] font-bold tracking-widest text-brand-hot uppercase">
                                                This week
                                            </span>
                                        )}
                                        {aired && !isCurrent && (
                                            <span className="text-[0.65rem] text-ink-faint">
                                                ✓
                                            </span>
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
