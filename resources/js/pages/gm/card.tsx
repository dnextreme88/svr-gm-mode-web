import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { CardBuilder } from '@/components/gm/card-builder';
import { PageTitle } from '@/components/gm/panel';
import { TitleTools } from '@/components/gm/title-tools';
import gm from '@/routes/gm';
import type {
    CardShow,
    CardSlot,
    GamePageProps,
    MatchTypeOption,
    RosterEntry,
    TitleContext,
} from '@/types/gm';

type Props = GamePageProps & {
    show: CardShow;
    slots: CardSlot[];
    match_types: MatchTypeOption[];
    roster: RosterEntry[];
    title_context: TitleContext;
};

export default function CardPage() {
    const { game, show, slots, match_types, roster, title_context } =
        usePage<Props>().props;
    const types = useMemo(
        () => new Map(match_types.map((t) => [t.id, t])),
        [match_types],
    );
    const rosterById = useMemo(
        () => new Map(roster.map((w) => [w.id, w])),
        [roster],
    );
    const pendingShots = title_context.title_shots.filter((s) => !s.booked);
    const briefcase = title_context.briefcase;

    return (
        <>
            <Head title={`${show.name} card`} />
            <PageTitle
                kicker={`Week ${game.season_week} · ${show.show_type === 'ppv' ? 'PPV card' : 'Weekly show'}`}
                title={
                    <span className="flex flex-wrap items-center gap-4">
                        {show.brand && (
                            <BrandBug brand={show.brand} size="lg" />
                        )}
                        {show.name}
                    </span>
                }
            >
                {briefcase &&
                    show.status === 'booking' &&
                    !briefcase.cashed_in_here && (
                        <button
                            type="button"
                            className="gm-btn gm-btn-gold gm-btn-sm"
                            title={`Expires week ${briefcase.expires_week}`}
                            onClick={() => {
                                if (
                                    confirm(
                                        `Cash in ${briefcase.holder}'s Money in the Bank briefcase on this card?`,
                                    )
                                ) {
                                    router.post(
                                        gm.card.cashIn.url({
                                            save: game.id,
                                            show: show.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            💼 Cash in: {briefcase.holder}
                        </button>
                    )}
                <Link
                    href={gm.schedule.url(game.id)}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    Schedule
                </Link>
            </PageTitle>

            {pendingShots.length > 0 && (
                <div
                    role="alert"
                    className="mb-4 border-l-4 border-gold bg-gold/10 px-4 py-3 text-sm"
                >
                    <div className="gm-label text-gold-hot">
                        Required title match
                    </div>
                    {pendingShots.map((shot) => (
                        <p key={shot.id} className="mt-1">
                            {shot.contenders} earned a{' '}
                            <strong>{shot.championship}</strong> match. Book it
                            before this card can air.
                        </p>
                    ))}
                </div>
            )}

            <CardBuilder
                key={`${show.id}-${slots.map((s) => `${s.position}:${s.match_type_id}:${s.title_championship_id}:${s.stipulation}`).join(',')}`}
                show={show}
                slots={slots}
                matchTypes={match_types}
                roster={roster}
                fatigueConfig={game.fatigue_config}
                onSave={(position, payload) =>
                    router.put(
                        gm.card.book.url({
                            save: game.id,
                            show: show.id,
                            position,
                        }),
                        payload,
                        {
                            preserveScroll: true,
                        },
                    )
                }
                onClear={(position) =>
                    router.delete(
                        gm.card.clear.url({
                            save: game.id,
                            show: show.id,
                            position,
                        }),
                        {
                            preserveScroll: true,
                        },
                    )
                }
                renderSlotTools={(draft, update) => (
                    <TitleTools
                        draft={draft}
                        type={
                            draft.matchTypeId
                                ? types.get(draft.matchTypeId)
                                : undefined
                        }
                        context={title_context}
                        roster={rosterById}
                        readOnly={show.status !== 'booking' || draft.fixed}
                        onChange={update}
                    />
                )}
            />
        </>
    );
}
