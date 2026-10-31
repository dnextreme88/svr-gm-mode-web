import { competitorIds } from '@/lib/card';
import type { SlotDraft } from '@/lib/card';
import { Stipulation as Stipulations } from '@/types/enums';
import type { Stipulation } from '@/types/enums';
import type {
    MatchTypeOption,
    RosterEntry,
    TitleContext,
    TitleOption,
} from '@/types/gm';

/**
 * Display-side mirror of the server's title booking rules (§4, §14, §22),
 * used to offer only valid choices. The server re-validates every booking.
 */
export function allowsWrestler(
    title: TitleOption,
    wrestler: RosterEntry | undefined,
): boolean {
    if (!wrestler) {
        return false;
    }

    switch (title.eligibility) {
        case 'divas_only':
            return wrestler.roster_type === 'diva';
        case 'cruiserweight_or_light_heavyweight':
            return (
                wrestler.roster_type !== 'diva' &&
                ['cruiserweight', 'light_heavyweight'].includes(
                    wrestler.weight_class,
                )
            );
        default:
            // Open titles: anyone but Divas (house rule).
            return wrestler.roster_type !== 'diva';
    }
}

function shapeFits(title: TitleOption, type: MatchTypeOption): boolean {
    const sizes = new Set(type.sides);

    return title.is_tag
        ? sizes.size === 1 && sizes.has(2)
        : sizes.size === 1 && sizes.has(1);
}

function holdersSide(draft: SlotDraft, holders: number[]): number | null {
    if (holders.length === 0) {
        return null;
    }

    const index = draft.sides.findIndex((side) =>
        holders.every((h) => side.includes(h)),
    );

    return index === -1 ? null : index;
}

/** Titles that could be on the line in this booking. */
export function titleCandidates(
    draft: SlotDraft,
    type: MatchTypeOption | undefined,
    ctx: TitleContext,
    roster: Map<number, RosterEntry>,
    /** Titles already on the line in another match on this card. */
    takenIds: number[] = [],
): TitleOption[] {
    if (!type || !type.titles_allowed) {
        return [];
    }

    const competitors = competitorIds(draft);

    if (competitors.length !== type.sides.reduce((a, b) => a + b, 0)) {
        return [];
    }

    return ctx.championships.filter((title) => {
        // A title can only be defended once per card.
        if (
            takenIds.includes(title.id) &&
            title.id !== draft.titleChampionshipId
        ) {
            return false;
        }

        if (!shapeFits(title, type)) {
            return false;
        }

        if (
            title.holder_ids.length > 0 &&
            holdersSide(draft, title.holder_ids) === null
        ) {
            return false;
        }

        const challengers = competitors.filter(
            (id) => !title.holder_ids.includes(id),
        );
        const locked = ctx.lockouts[title.id] ?? [];
        const contendership = contendershipFor(ctx, title.id);

        // House rule: a #1 contender's title match is theirs alone.
        if (
            contendership &&
            !sameIds(challengers, contendership.contender_ids)
        ) {
            return false;
        }

        return challengers.every(
            (id) =>
                allowsWrestler(title, roster.get(id)) && !locked.includes(id),
        );
    });
}

/** Titles Winner Takes All can contest: never a tag title or the Women's title. */
export function contestable(title: TitleOption): boolean {
    return !title.is_tag && title.eligibility !== 'divas_only';
}

/** A title's pending #1 contendership, if it has one (house rule). */
export function contendershipFor(ctx: TitleContext, titleId: number) {
    return (ctx.contenderships ?? []).find(
        (c) => c.championship_id === titleId,
    );
}

function sameIds(a: number[], b: number[]): boolean {
    const x = [...a].sort((m, n) => m - n);
    const y = [...b].sort((m, n) => m - n);

    return x.length === y.length && x.every((id, i) => id === y[i]);
}

/** Why a wrestler can't challenge for the title on the line, if they can't. */
export function cantChallenge(
    title: TitleOption,
    wrestler: RosterEntry | undefined,
    ctx: TitleContext,
): boolean {
    if (!wrestler || title.holder_ids.includes(wrestler.id)) {
        return false;
    }

    const contendership = contendershipFor(ctx, title.id);

    return (
        !allowsWrestler(title, wrestler) ||
        (ctx.lockouts[title.id] ?? []).includes(wrestler.id) ||
        (contendership !== undefined &&
            !contendership.contender_ids.includes(wrestler.id))
    );
}

export type StipulationOption = {
    value: Stipulation;
    label: string;
    /** Champion vs. Contender and #1 Contender's Match: the titles to pick from. */
    titles?: TitleOption[];
    /** Winner Takes All: the titles on the line. */
    contested?: TitleOption[];
};

/** Other matches on the card, for the stipulations that care. */
export type CardTitles = {
    /** Titles on the line (or contested) in the card's other matches. */
    takenIds?: number[];
    /** Titles another match on the card already holds a #1 Contender's Match for. */
    numberOneIds?: number[];
};

/** "World Heavyweight" from "World Heavyweight Championship". */
export function shortTitle(name: string): string {
    return name.replace(' Championship', '');
}

/**
 * Stipulations available for this booking, mirroring `TitleBookingRules`
 * (§22 and the house rules of 2026-10-31).
 */
export function stipulationOptions(
    draft: SlotDraft,
    type: MatchTypeOption | undefined,
    ctx: TitleContext,
    roster: Map<number, RosterEntry>,
    card: CardTitles = {},
): StipulationOption[] {
    if (!type) {
        return [];
    }

    const allowed = (s: Stipulation) =>
        (type.allowed_stipulations ?? Object.values(Stipulations)).includes(s);
    const sides = type.sides;
    const singles = sides.every((s) => s === 1);
    const teams = sides.every((s) => s === 2);
    const oneOnOne = sides.length === 2 && singles;
    const twoOnTwo = sides.length === 2 && teams;
    const competitors = competitorIds(draft);
    const full = competitors.length === sides.reduce((a, b) => a + b, 0);
    const titleOnLine = draft.titleChampionshipId !== null;
    const holders = new Set(ctx.title_holder_ids ?? []);
    const earned = new Set(ctx.earned_shot_title_ids ?? []);
    const locked = (title: TitleOption, id: number) =>
        (ctx.lockouts[title.id] ?? []).includes(id);
    const options: StipulationOption[] = [];

    if ((oneOnOne || twoOnTwo) && allowed('loser_leaves_show')) {
        options.push({
            value: 'loser_leaves_show',
            label: 'Loser Leaves Show',
        });
    }

    // One side is exactly the champions; the other holds no title at all.
    if (
        full &&
        !titleOnLine &&
        (oneOnOne || twoOnTwo) &&
        allowed('champion_vs_contender')
    ) {
        const titles = ctx.championships.filter((t) => {
            if (t.holder_ids.length === 0 || t.is_tag !== twoOnTwo) {
                return false;
            }

            const side = draft.sides.find((s) =>
                sameIds(
                    s.filter((id): id is number => id !== null),
                    t.holder_ids,
                ),
            );

            if (!side || contendershipFor(ctx, t.id)) {
                return false;
            }

            return competitors
                .filter((id) => !t.holder_ids.includes(id))
                .every(
                    (id) =>
                        allowsWrestler(t, roster.get(id)) &&
                        !locked(t, id) &&
                        !holders.has(id),
                );
        });

        if (titles.length > 0) {
            options.push({
                value: 'champion_vs_contender',
                label: 'Champion vs. Contender',
                titles,
            });
        }
    }

    if (titleOnLine && (oneOnOne || twoOnTwo) && allowed('last_chance')) {
        const title = ctx.championships.find(
            (t) => t.id === draft.titleChampionshipId,
        );
        const contenders = competitors
            .filter((id) => !(title?.holder_ids ?? []).includes(id))
            .sort((a, b) => a - b);
        const eligible = ctx.last_chance.some(
            (entry) =>
                entry.championship_id === draft.titleChampionshipId &&
                entry.contender_ids.length === contenders.length &&
                entry.contender_ids.every((id, i) => id === contenders[i]),
        );

        if (eligible) {
            options.push({ value: 'last_chance', label: 'Last Chance Match' });
        }
    }

    // Every competitor is a champion; their singles titles are all on the line.
    if (
        full &&
        !titleOnLine &&
        singles &&
        sides.length >= 2 &&
        sides.length <= 3 &&
        allowed('winner_takes_all')
    ) {
        const heldBy = (id: number) =>
            ctx.championships.filter(
                (t) => contestable(t) && t.holder_ids.includes(id),
            );
        const contested = ctx.championships.filter(
            (t) =>
                contestable(t) &&
                competitors.some((id) => t.holder_ids.includes(id)),
        );
        const ok =
            competitors.every((id) => heldBy(id).length > 0) &&
            contested.every(
                (t) =>
                    !(card.takenIds ?? []).includes(t.id) &&
                    !earned.has(t.id) &&
                    !contendershipFor(ctx, t.id) &&
                    competitors
                        .filter((id) => !t.holder_ids.includes(id))
                        .every((id) => !locked(t, id)),
            );

        if (ok) {
            options.push({
                value: 'winner_takes_all',
                label: 'Winner Takes All',
                contested,
            });
        }
    }

    // Non-champions fight to be a title's #1 contender(s).
    if (
        full &&
        !titleOnLine &&
        sides.length >= 2 &&
        allowed('number_one_contender')
    ) {
        const titles = ctx.championships.filter((t) => {
            if (t.holder_ids.length === 0) {
                return false;
            }

            const shape = t.is_tag ? teams && sides.length <= 3 : singles;

            return (
                shape &&
                !contendershipFor(ctx, t.id) &&
                !earned.has(t.id) &&
                !(card.numberOneIds ?? []).includes(t.id) &&
                competitors.every(
                    (id) =>
                        !t.holder_ids.includes(id) &&
                        allowsWrestler(t, roster.get(id)) &&
                        !locked(t, id),
                )
            );
        });

        if (titles.length > 0) {
            options.push({
                value: 'number_one_contender',
                label: "#1 Contender's Match",
                titles,
            });
        }
    }

    // Listed alphabetically in the dropdown ("#1 Contender's Match" first).
    return options.sort((a, b) => a.label.localeCompare(b.label));
}

/** The picked stipulation as the dropdown shows it. */
export function stipulationLabel(
    option: StipulationOption | undefined,
    draft: SlotDraft,
    ctx: TitleContext,
): string | null {
    if (!option) {
        return null;
    }

    if (option.contested) {
        return `${option.label}: ${option.contested.map((t) => shortTitle(t.name)).join(', ')}`;
    }

    const title = ctx.championships.find(
        (t) => t.id === draft.stipulationChampionshipId,
    );

    return title ? `${option.label}: ${shortTitle(title.name)}` : option.label;
}
