import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { BookingAction, BookingSteps } from '@/components/gm/booking-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import { BrandBug } from '@/components/gm/brand-bug';
import { CardBuilder } from '@/components/gm/card-builder';
import { PageTitle } from '@/components/gm/panel';
import { PromoSlots } from '@/components/gm/promo-slots';
import type {
    PromoEligibilityView,
    PromoSlotView,
    PromoTypeOption,
} from '@/components/gm/promo-slots';
import { ProposalLauncher } from '@/components/gm/proposal-launcher';
import type { ProposalResult } from '@/components/gm/proposal-launcher';
import type { ProposalView } from '@/components/gm/proposal-modal';
import { StipulationPicker, TitleCycler } from '@/components/gm/title-tools';
import { UnsavedChangesProvider } from '@/components/gm/unsaved-changes';
import type { PromoFatigue } from '@/lib/card';
import { requestJson } from '@/lib/http';
import { saveVisit, slotSaveKey } from '@/lib/save-status';
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
    promo_slots: PromoSlotView[];
    promo_types: PromoTypeOption[];
    promo_eligibility: Record<string, PromoEligibilityView>;
    booking_order: BookingOrder;
};

export default function CardPage() {
    const {
        game,
        show,
        slots,
        match_types,
        roster,
        title_context,
        promo_slots,
        promo_types,
        promo_eligibility,
        booking_order,
    } = usePage<Props>().props;
    const types = useMemo(
        () => new Map(match_types.map((t) => [t.id, t])),
        [match_types],
    );
    const rosterById = useMemo(
        () => new Map(roster.map((w) => [w.id, w])),
        [roster],
    );
    const titleNames = useMemo(
        () =>
            Object.fromEntries(
                title_context.championships.map((c) => [c.id, c.name]),
            ),
        [title_context],
    );
    // Saved promos that tire their wrestlers before a match (§5).
    const promoFatigue = useMemo<PromoFatigue[]>(
        () =>
            promo_slots.flatMap((slot) => {
                const type = promo_types.find(
                    (t) => t.id === slot.promo_type_id,
                );
                const bars = type?.fatigue_bars ?? 0;

                if (bars === 0) {
                    return [];
                }

                return [slot.target_wrestler_id, slot.target_wrestler_b_id]
                    .filter((id): id is number => id !== null)
                    .map((wrestlerId) => ({
                        wrestlerId,
                        beforePosition:
                            slot.before_position ?? show.match_slot_count,
                        bars,
                    }));
            }),
        [promo_slots, promo_types, show.match_slot_count],
    );
    const pendingShots = title_context.title_shots.filter((s) => !s.booked);
    const briefcase = title_context.briefcase;
    // Cards are booked in date order (Sunday PPV first), and a confirmed
    // card is read-only until it's unlocked.
    const booking = show.status === 'booking' && booking_order.editable;
    const ids = { save: game.id, show: show.id };

    // Confirming a proposal goes through the normal booking endpoint (§24).
    const saveProposals = async (proposals: ProposalView[]) => {
        for (const p of proposals) {
            await requestJson(
                'PUT',
                gm.card.book.url({ ...ids, position: p.position }),
                {
                    match_type_id: p.match_type_id,
                    sides: p.sides,
                    managers: p.managers ?? {},
                    title_championship_id: p.title_championship_id,
                    referee_id: p.referee_id ?? null,
                },
            );
        }

        router.reload();
    };

    // Preset confirms the whole card at once, so a title or wrestler moving
    // between slots can't trip over its old booking.
    const applyPreset = async (proposals: ProposalView[]) => {
        await requestJson('POST', gm.card.preset.apply.url(ids), {
            proposals: proposals.map((p) => ({
                position: p.position,
                match_type_id: p.match_type_id,
                sides: p.sides,
                managers: p.managers ?? {},
                title_championship_id: p.title_championship_id,
                referee_id: p.referee_id ?? null,
            })),
        });

        router.reload();
    };

    return (
        <UnsavedChangesProvider>
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
                {booking && (
                    <ProposalLauncher
                        label="Preset card"
                        mode="preset"
                        titleNames={titleNames}
                        fetchProposal={(roll) =>
                            requestJson<ProposalResult>(
                                'POST',
                                gm.card.preset.url(ids),
                                { roll },
                            )
                        }
                        onConfirm={applyPreset}
                    />
                )}
                {briefcase && booking && !briefcase.cashed_in_here && (
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
                                    gm.card.cashIn.url(ids),
                                    {},
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        💼 Cash in: {briefcase.holder}
                    </button>
                )}
                <BookingAction
                    game={game}
                    showId={show.id}
                    showName={show.name}
                    order={booking_order}
                />
            </PageTitle>

            <BookingSteps game={game} order={booking_order} />

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
                key={`${show.id}-${slots.map((s) => `${s.position}:${s.match_type_id}:${s.title_championship_id}:${s.stipulation}:${Object.keys(s.names ?? {}).join('.')}`).join(',')}`}
                show={show}
                slots={slots}
                matchTypes={match_types}
                roster={roster}
                lockedByOrder={!booking_order.editable}
                fatigueConfig={game.fatigue_config}
                onSave={(position, payload) =>
                    router.put(
                        gm.card.book.url({ ...ids, position }),
                        payload,
                        {
                            preserveScroll: true,
                            ...saveVisit(
                                slotSaveKey('match', show.id, position),
                            ),
                        },
                    )
                }
                onClear={(position) =>
                    router.delete(gm.card.clear.url({ ...ids, position }), {
                        preserveScroll: true,
                    })
                }
                promoFatigue={promoFatigue}
                championships={title_context.championships}
                renderTitle={(draft, update, drafts) => (
                    <TitleCycler
                        takenTitleIds={drafts
                            .filter((d) => d.position !== draft.position)
                            .map((d) => d.titleChampionshipId)
                            .filter((id): id is number => id !== null)}
                        draft={draft}
                        type={
                            draft.matchTypeId
                                ? types.get(draft.matchTypeId)
                                : undefined
                        }
                        context={title_context}
                        roster={rosterById}
                        readOnly={!booking || draft.fixed}
                        onChange={update}
                    />
                )}
                renderButtons={(draft) =>
                    booking &&
                    !draft.fixed && (
                        <ProposalLauncher
                            label="Randomize"
                            mode="randomize"
                            titleNames={titleNames}
                            fetchProposal={async (roll) => {
                                const { proposal } = await requestJson<{
                                    proposal: ProposalView;
                                }>(
                                    'POST',
                                    gm.card.randomize.url({
                                        ...ids,
                                        position: draft.position,
                                    }),
                                    {
                                        match_type_id: draft.matchTypeId,
                                        roll,
                                    },
                                );

                                return {
                                    proposals: [proposal],
                                    unfilled_slots: [],
                                    total_cost: proposal.cost,
                                    deficit_warning: false,
                                };
                            }}
                            onConfirm={saveProposals}
                        />
                    )
                }
                renderActions={(draft, update) => (
                    <StipulationPicker
                        draft={draft}
                        type={
                            draft.matchTypeId
                                ? types.get(draft.matchTypeId)
                                : undefined
                        }
                        context={title_context}
                        roster={rosterById}
                        readOnly={!booking || draft.fixed}
                        onChange={update}
                    />
                )}
            />

            {show.promo_slot_count > 0 && (
                <section className="mt-8">
                    <h2 className="gm-label mb-3">Promos</h2>
                    <PromoSlots
                        showId={show.id}
                        slots={promo_slots}
                        types={promo_types}
                        eligibility={promo_eligibility}
                        roster={roster}
                        matchSlotCount={show.match_slot_count}
                        titleNames={titleNames}
                        readOnly={!booking}
                        onSave={(position, payload) =>
                            router.put(
                                gm.card.promo.book.url({ ...ids, position }),
                                payload,
                                {
                                    preserveScroll: true,
                                    ...saveVisit(
                                        slotSaveKey('promo', show.id, position),
                                    ),
                                },
                            )
                        }
                        onClear={(position) =>
                            router.delete(
                                gm.card.promo.clear.url({ ...ids, position }),
                                { preserveScroll: true },
                            )
                        }
                    />
                </section>
            )}
        </UnsavedChangesProvider>
    );
}
