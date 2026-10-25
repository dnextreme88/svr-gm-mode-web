import { Fragment } from 'react';
import { SideNames } from '@/components/gm/champion-tag';
import { cn } from '@/lib/utils';
import type { MatchOutcome } from '@/types/gm';

/**
 * One result flashed on screen after a show airs (§20).
 */
export function OutcomeCard({
    outcome,
    showName,
    isMainEvent,
}: {
    outcome: MatchOutcome;
    showName: string;
    isMainEvent: boolean;
}) {
    return (
        <article
            key={`${showName}-${outcome.position}`}
            className="gm-panel relative overflow-hidden px-6 py-10 text-center md:px-12"
        >
            <div className="gm-label">
                {showName} ·{' '}
                {isMainEvent ? 'Main event' : `Match ${outcome.position}`}
            </div>
            <div className="mt-1 font-condensed text-sm tracking-wider text-ink-dim uppercase">
                {outcome.match_type}
                {outcome.title && (
                    <span className="ml-2 text-gold-hot">
                        · {outcome.title}
                    </span>
                )}
            </div>

            {outcome.title_banner && (
                <div
                    className={cn(
                        'mx-auto mt-6 inline-block animate-gm-slam px-6 py-2 font-display text-3xl font-bold tracking-wide uppercase md:text-5xl',
                        outcome.title_banner === 'new_champion'
                            ? 'bg-gradient-to-b from-gold-hot to-gold text-arena shadow-[0_0_60px_-10px_var(--color-gold)]'
                            : 'bg-white/10 text-ink',
                    )}
                >
                    {outcome.title_banner === 'new_champion'
                        ? 'New champion!'
                        : 'And still…'}
                </div>
            )}

            <div className="mt-8 animate-gm-rise">
                <div className="gm-label text-zone-safe">Winner</div>
                <h2 className="gm-heading mt-1 text-3xl font-bold text-ink md:text-5xl">
                    <SideNames
                        names={outcome.winners}
                        champions={outcome.champions}
                        captains={outcome.captains}
                    />
                </h2>
                <div className="mt-4 font-condensed text-lg tracking-wide text-ink-dim uppercase">
                    def.{' '}
                    {outcome.loser_sides.map((side, i) => (
                        <Fragment key={side.join()}>
                            {i > 0 && ', '}
                            <SideNames
                                names={side}
                                champions={outcome.champions}
                                captains={outcome.captains}
                            />
                        </Fragment>
                    ))}
                </div>
            </div>

            <div className="mx-auto mt-8 flex max-w-2xl flex-wrap items-center justify-center gap-3">
                <span className="gm-chyron-both bg-brand py-1 font-condensed text-sm font-bold tracking-[0.15em] text-white uppercase">
                    {outcome.method_label}
                </span>
                {outcome.booked && (
                    <span className="gm-chyron-both bg-gold/20 py-1 font-condensed text-sm font-semibold tracking-[0.15em] text-gold-hot uppercase">
                        Booked to win
                    </span>
                )}
                {outcome.finish_label && (
                    <span className="gm-chyron-both bg-white/10 py-1 font-condensed text-sm font-semibold tracking-[0.15em] text-ink uppercase">
                        {outcome.finish_label}
                    </span>
                )}
            </div>
            <p className="mx-auto mt-4 max-w-2xl text-ink-dim">
                {outcome.summary}
            </p>

            {outcome.bouts && outcome.bouts.length > 0 && (
                <ol
                    data-testid="gauntlet-bouts"
                    className="mx-auto mt-6 max-w-2xl divide-y divide-white/10 border-y border-white/10 text-left"
                >
                    {outcome.bouts.map((bout) => (
                        <li key={bout.round} className="py-2">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="font-semibold text-ink">
                                    <span className="gm-label mr-2 text-ink-dim">
                                        Bout {bout.round}
                                    </span>
                                    {bout.winner}
                                    <span className="mx-1.5 text-ink-faint">
                                        def.
                                    </span>
                                    {bout.loser}
                                </span>
                                <span className="gm-label shrink-0">
                                    {bout.method_label}
                                    {bout.booked && (
                                        <span className="ml-2 text-gold-hot">
                                            Booked
                                        </span>
                                    )}
                                </span>
                            </div>
                            <p className="mt-0.5 text-sm text-ink-dim">
                                {bout.summary}
                            </p>
                        </li>
                    ))}
                </ol>
            )}

            {outcome.stipulation_outcome && (
                <p className="mx-auto mt-4 max-w-2xl font-condensed text-lg font-semibold tracking-wide text-brand-hot uppercase">
                    {outcome.stipulation_outcome}
                </p>
            )}

            <div className="mt-8 flex justify-center gap-8 text-xs text-ink-faint">
                <span title="Match Rating system is deferred">Rating —</span>
                <span title="Popularity system is deferred">Fan support —</span>
            </div>
        </article>
    );
}
