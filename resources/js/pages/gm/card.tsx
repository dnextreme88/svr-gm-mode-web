import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    BookingAction,
    BookingSteps,
    nightSteps,
} from '@/components/gm/booking-steps';
import { WeekSteps } from '@/components/gm/setup-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import type { RepeatWarning } from '@/components/gm/air-week-button';
import { BrandBug } from '@/components/gm/brand-bug';
import {
    LOADING_OVERLAY_DELAY_MS,
    LoadingOverlay,
} from '@/components/gm/loading-overlay';
import { CardBuilder } from '@/components/gm/card-builder';
import { CashInButton } from '@/components/gm/cash-in-button';
import { PageTitle } from '@/components/gm/panel';
import { PromoSlots } from '@/components/gm/promo-slots';
import type {
    PromoEligibilityView,
    PromoSlotView,
    PromoTypeOption,
} from '@/components/gm/promo-slots';
import { ActiveEffects } from '@/components/gm/active-effects';
import type {
    ActiveRivalry,
    BuriedAlive,
} from '@/components/gm/active-effects';
import { ProposalLauncher } from '@/components/gm/proposal-launcher';
import { TitleShotReminder } from '@/components/gm/title-shot-reminder';
import type { ProposalResult } from '@/components/gm/proposal-launcher';
import type { ProposalView } from '@/components/gm/proposal-modal';
import { StipulationPicker, TitleCycler } from '@/components/gm/title-tools';
import { UnsavedChangesProvider } from '@/components/gm/unsaved-changes';
import type { PromoFatigue } from '@/lib/card';
import { MainEventTypeButton } from '@/components/gm/wrestlemania-tools';
import type { SeriesOption } from '@/components/gm/wrestlemania-tools';
import { QualifierRandomize } from '@/components/gm/qualifier-randomize';
import { requestJson } from '@/lib/http';
import { saveVisit, slotSaveKey } from '@/lib/save-status';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
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
    buried_alive: BuriedAlive[];
    /** Active Effects (2026-11-20): sitting out after a blocked defection. */
    sitting_out?: BuriedAlive[];
    rivalries?: ActiveRivalry[];
    promo_slots: PromoSlotView[];
    promo_types: PromoTypeOption[];
    promo_eligibility: Record<string, PromoEligibilityView>;
    booking_order: BookingOrder;
    /** A brand's SummerSlam card: whether it has the night's main event. */
    summerslam: { main_event: boolean; headliner: string } | null;
    /** Slots in the same match category as last week's (house rule, 2026-11-08). */
    repeats?: RepeatWarning;
    /** The player's WrestleMania interbrand series spots (house rule, 2026-11-15). */
    series?: Record<number, SeriesOption>;
    /** Match types the WrestleMania main event can be, when the player has it. */
    main_event_types?: number[];
};

export default function CardPage() {
    const {
        game,
        show,
        slots,
        match_types,
        roster,
        title_context,
        buried_alive,
        sitting_out = [],
        rivalries,
        promo_slots,
        promo_types,
        promo_eligibility,
        booking_order,
        summerslam,
        repeats,
        series,
        main_event_types,
    } = usePage<Props>().props;
    const [rearranging, setRearranging] = useState(false);
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
    // Where "Book it now" puts an owed title match: the first empty slot of
    // the player's, else the last one.
    const ownSlots = slots.filter((s) => !s.fixed_kind && !s.locked);
    // The Main Event is the card's last match, never a cash-in placed after it.
    const mainEventPosition = Math.max(
        0,
        ...slots
            .filter((s) => s.fixed_kind !== 'mitb_cash_in')
            .map((s) => s.position),
    );
    // House rule (2026-11-17): on an interbrand card the rival's own title
    // and stipulation show on its slots, in the rival's brand colour.
    const slotAt = (position: number) =>
        slots.find((s) => s.position === position);
    const rivalOf = (brand?: Brand | null) =>
        brand && brand !== game.player_brand ? brand : null;
    const rivalStipulationAt = (position: number) => {
        const slot = slotAt(position);
        const brand = rivalOf(slot?.stipulation_brand);

        return slot?.stipulation && brand
            ? { titles: slot.stipulation_title_names ?? [], brand }
            : null;
    };
    const reminderSlot =
        ownSlots.find((s) => s.match_type_id === null) ?? ownSlots.at(-1);
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
            {rearranging && (
                <LoadingOverlay
                    text="Re-arranging your matches. Please wait…"
                    testId="reorder-overlay"
                />
            )}
            {/* Where the week stands (house rule, 2026-11-21): this
                week's cards are step 1. */}
            {booking_order.airs_this_week && (
                <WeekSteps
                    current={1}
                    ppv={
                        booking_order.night === 'ppv'
                            ? (nightSteps(booking_order)[0]?.name ?? 'the PPV')
                            : null
                    }
                />
            )}
            <PageTitle
                kicker={`Week ${game.season_week} · ${show.show_type === 'ppv' ? 'PPV card' : 'Weekly show'}`}
                title={
                    <span className="my-3 flex flex-wrap items-center gap-4">
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
                        mainEventPosition={mainEventPosition}
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
                    <CashInButton
                        briefcase={briefcase}
                        slots={slots}
                        url={gm.card.cashIn.url(ids)}
                    />
                )}
                <BookingAction
                    game={game}
                    showId={show.id}
                    showName={show.name}
                    order={booking_order}
                    repeats={repeats}
                />
            </PageTitle>

            <BookingSteps game={game} order={booking_order} />

            {summerslam && (
                <p
                    data-testid="summerslam-main-event"
                    className={cn(
                        'mb-4 border px-4 py-2.5 text-sm',
                        summerslam.main_event
                            ? 'border-gold/40 bg-gold/8 text-gold-hot'
                            : 'border-white/8 bg-white/4 text-ink-dim',
                    )}
                >
                    {summerslam.main_event
                        ? `Your weekly shows drew more fans than your rival prior to Summerslam, so you book matches 1, 3, 5, and the main event. Your rival books matches 2, 4, and 6.`
                        : `${summerslam.headliner}’s weekly shows drew more fans than you prior to Summerslam, so they book matches 1, 3, 5, and the main event. You book matches 2, 4, and 6.`}
                </p>
            )}

            <TitleShotReminder
                key={pendingShots.map((s) => s.id).join(',')}
                shots={pendingShots}
                canBook={booking && reminderSlot !== undefined}
                onBook={async () => {
                    if (reminderSlot === undefined) {
                        return;
                    }

                    // Randomize proposes the owed title match first.
                    const { proposal } = await requestJson<{
                        proposal: ProposalView;
                    }>(
                        'POST',
                        gm.card.randomize.url({
                            ...ids,
                            position: reminderSlot.position,
                        }),
                        { roll: 0 },
                    );
                    await saveProposals([proposal]);
                }}
            />

            <ActiveEffects
                context={title_context}
                buriedAlive={buried_alive}
                sittingOut={sitting_out}
                rivalries={rivalries}
            />

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
                onReorder={(order) => {
                    // 2026-11-15: "Re-arranging your matches" covers the card
                    // while the new order saves, for at least 2.5 seconds.
                    const started = Date.now();
                    setRearranging(true);

                    router.put(
                        gm.card.reorder.url(ids),
                        { order },
                        {
                            preserveScroll: true,
                            onFinish: () => {
                                window.setTimeout(
                                    () => setRearranging(false),
                                    Math.max(
                                        0,
                                        LOADING_OVERLAY_DELAY_MS -
                                            (Date.now() - started),
                                    ),
                                );
                            },
                        },
                    );
                }}
                // WrestleMania's interbrand series (house rule, 2026-11-15):
                // your spots are ordinary pickers on the match; Save sends them.
                series={series}
                onSaveSeries={(position, picks) =>
                    router.put(
                        gm.card.series.url({ ...ids, position }),
                        { ids: picks },
                        {
                            preserveScroll: true,
                            ...saveVisit(
                                slotSaveKey('match', show.id, position),
                            ),
                        },
                    )
                }
                promoFatigue={promoFatigue}
                titleContext={title_context}
                renderTitle={(draft, update, drafts) => (
                    <TitleCycler
                        takenTitleIds={[
                            ...drafts
                                .filter((d) => d.position !== draft.position)
                                .map((d) => d.titleChampionshipId)
                                .filter((id): id is number => id !== null),
                            ...slots
                                .filter((s) => s.position !== draft.position)
                                .flatMap((s) => s.contested_title_ids ?? []),
                        ]}
                        draft={draft}
                        type={
                            draft.matchTypeId
                                ? types.get(draft.matchTypeId)
                                : undefined
                        }
                        context={title_context}
                        roster={rosterById}
                        bookedTitleName={slotAt(draft.position)?.title_name}
                        rivalBrand={rivalOf(
                            slotAt(draft.position)?.title_brand,
                        )}
                        readOnly={!booking || draft.fixed}
                        onChange={update}
                    />
                )}
                renderButtons={(draft, update) => {
                    const slot = slots.find(
                        (s) => s.position === draft.position,
                    );

                    // House rule (2026-11-17): the Qualifying Match's 3 men.
                    if (slot?.fixed_kind === 'qualifying_match') {
                        return (
                            booking && (
                                <QualifierRandomize
                                    url={gm.card.randomize.url({
                                        ...ids,
                                        position: draft.position,
                                    })}
                                    onPick={(picks) =>
                                        update({
                                            ...draft,
                                            sides: picks.map((id) => [id]),
                                        })
                                    }
                                />
                            )
                        );
                    }

                    // House rule (2026-11-15): the player picks the main
                    // event's match type (series spots are picked on the
                    // match itself, see `series` above).

                    // The Royal Rumble or the Survivor Series 5 vs 5: the
                    // entrants page picks the player's side.
                    if (
                        slot?.fixed_kind === 'royal_rumble' ||
                        slot?.fixed_kind === 'survivor_series_main_event'
                    ) {
                        return (
                            <Link
                                href={gm.ppv.entrants.url(ids)}
                                data-testid="pick-entrants"
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    booking ? 'gm-btn-gold' : 'gm-btn-ghost',
                                )}
                            >
                                {!booking
                                    ? 'View entrants'
                                    : slot.fixed_kind === 'royal_rumble'
                                      ? 'Pick entrants'
                                      : 'Pick your team'}
                            </Link>
                        );
                    }

                    if (
                        booking &&
                        slot?.fixed_kind === 'wrestlemania_main_event' &&
                        (main_event_types?.length ?? 0) > 0
                    ) {
                        return (
                            <MainEventTypeButton
                                ids={ids}
                                types={match_types.filter((t) =>
                                    main_event_types?.includes(t.id),
                                )}
                                current={draft.matchTypeId}
                                fatigueConfig={game.fatigue_config}
                            />
                        );
                    }

                    return (
                        booking &&
                        !draft.fixed && (
                            <ProposalLauncher
                                label="Randomize"
                                mode="randomize"
                                titleNames={titleNames}
                                mainEventPosition={mainEventPosition}
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
                    );
                }}
                renderActions={(draft, update, drafts) => (
                    <StipulationPicker
                        rivalStipulation={rivalStipulationAt(draft.position)}
                        takenTitleIds={[
                            ...drafts
                                .filter((d) => d.position !== draft.position)
                                .map((d) => d.titleChampionshipId)
                                .filter((id): id is number => id !== null),
                            ...slots
                                .filter((s) => s.position !== draft.position)
                                .flatMap((s) => s.contested_title_ids ?? []),
                        ]}
                        numberOneTitleIds={drafts
                            .filter(
                                (d) =>
                                    d.position !== draft.position &&
                                    d.stipulation === 'number_one_contender',
                            )
                            .map((d) => d.stipulationChampionshipId)
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
                        fatigueConfig={game.fatigue_config}
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
