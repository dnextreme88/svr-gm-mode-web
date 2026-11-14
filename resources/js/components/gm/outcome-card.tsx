import { CashInTag, TitleBeltImage, TitleMark } from '@/components/gm/badges';
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
                {isMainEvent ? 'Main Event' : `Match ${outcome.position}`}
            </div>
            <div className="mt-1 font-condensed text-sm tracking-wider text-ink-dim uppercase">
                {outcome.match_type}
                {outcome.title && (
                    <span className="ml-2 text-gold-hot">
                        ·{' '}
                        <TitleBeltImage
                            name={outcome.title}
                            decorative
                            className="mr-1.5 h-7"
                        />
                        {outcome.title}
                        {!outcome.champions?.length && ' (vacant)'}
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
            {/* "And still…" names who kept the title when the challenger
                won by DQ or count-out, so the big Winner below doesn't read
                as the champion (UI audit, 2026-11-12). */}
            {outcome.title_banner === 'and_still' &&
                outcome.title &&
                (outcome.champions ?? []).length > 0 &&
                !(outcome.champions ?? []).some((c) =>
                    outcome.winners.includes(c),
                ) && (
                    <p
                        className="mt-3 font-condensed text-lg tracking-wide text-gold-hot uppercase"
                        data-testid="kept-title"
                    >
                        {(outcome.champions ?? []).join(' & ')}{' '}
                        {(outcome.champions ?? []).length > 1
                            ? 'keep'
                            : 'keeps'}{' '}
                        the {outcome.title}
                        <span className="block text-sm text-ink-dim normal-case">
                            Titles only change hands on a decisive win.
                        </span>
                    </p>
                )}

            <div className="mt-8 animate-gm-rise">
                <div className="gm-label text-zone-safe">Winner</div>
                <h2 className="gm-heading mt-1 text-3xl font-bold text-ink md:text-5xl">
                    <SideNames
                        names={outcome.winners}
                        champions={outcome.champions}
                        captains={outcome.captains}
                        factions={outcome.factions}
                        manager={outcome.winner_manager}
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
                                factions={outcome.factions}
                                manager={outcome.loser_side_managers?.[i]}
                            />
                        </Fragment>
                    ))}
                </div>
            </div>

            <div className="mx-auto mt-8 flex max-w-2xl flex-wrap items-center justify-center gap-3">
                <span className="gm-chyron-both py-1 font-condensed text-sm font-bold tracking-[0.15em] text-white uppercase">
                    {outcome.method_label}
                </span>
                {outcome.booked && (
                    <span className="gm-chyron-both py-1 font-condensed text-sm font-semibold tracking-[0.15em] text-gold-hot uppercase [--gm-chyron-bg:color-mix(in_srgb,var(--color-gold)_20%,transparent)]">
                        Booked to win
                    </span>
                )}
                {outcome.finish_label && (
                    <span className="gm-chyron-both py-1 font-condensed text-sm font-semibold tracking-[0.15em] text-ink uppercase [--gm-chyron-bg:rgb(255_255_255/0.1)]">
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

            {outcome.mitb_cash_in && (
                <p className="mt-4 text-lg">
                    <CashInTag />
                </p>
            )}

            {outcome.stipulation_outcome && (
                <p className="mx-auto mt-4 max-w-2xl font-condensed text-lg font-semibold tracking-wide text-brand-hot uppercase">
                    {outcome.stipulation_outcome}
                </p>
            )}

            {(outcome.titles_won ?? []).length > 0 && (
                <ul
                    aria-label="Titles won"
                    className="mx-auto mt-3 flex max-w-2xl flex-wrap justify-center gap-2"
                >
                    {(outcome.titles_won ?? []).map((t) => (
                        <li
                            key={t}
                            className="inline-flex items-center gap-1 bg-gradient-to-b from-gold-hot to-gold px-2 py-px font-condensed text-xs font-bold tracking-[0.14em] text-arena uppercase"
                        >
                            <TitleMark name={t} />
                            {t}
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}
