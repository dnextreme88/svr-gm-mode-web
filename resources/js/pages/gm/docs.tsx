import { Head, Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { FatigueCost } from '@/components/gm/fatigue-cost';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PageTitle, Panel } from '@/components/gm/panel';
import { successLabel } from '@/components/gm/promo-slots';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { brandName, formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type {
    Brand,
    MatchFamily,
    RosterType,
    Stipulation,
    WeightClass,
} from '@/types/enums';
import type { FatigueConfig, GameShell } from '@/types/gm';

type Variant = {
    id: number;
    variant: string;
    sides: number[];
    competitors: number;
    managers: number;
    fatigue_bars: number;
    cost: number;
    dq: boolean;
    countout: boolean;
    rope_breaks: boolean;
    ring_out: boolean;
    captain_fall: boolean;
    timed: boolean;
    titles_allowed: boolean;
    divas_only: boolean;
    divas_allowed: boolean;
    elimination: boolean;
    ppv_only: boolean;
    ppv_exclusive: string | null;
    brand: Brand | null;
    gm_bookable: boolean;
    special_referee: boolean;
    win_condition: string | null;
};

type Promo = {
    key: string;
    name: string;
    amount: number;
    variable_amount: boolean;
    success_rate: number;
    success_range?: [number, number] | null;
    fatigue_bars: number;
    /** Extra end-of-week recovery for the target (Inactive Superstar). */
    recovery_bars: number;
    targets_rival: boolean;
    requirement: string | null;
    effect: string;
};

type Injury = {
    label: string;
    severity: 'minor' | 'serious';
    min_weeks: number;
    max_weeks: number;
    escalates_to: string | null;
};

type NewsEvent = {
    type: string;
    label: string;
    group: string;
    card: 'brand' | 'wwe' | 'either';
    tier: string;
    active: boolean;
    headline: string;
    summary: string;
};

type GmEmail = { email: string; from: string; when: string };

/** A story catalog group: a promo, or a quiet-week card. */
type StoryGroup = {
    key: string;
    name: string;
    situations: Array<{
        situation: string;
        label: string;
        extra: boolean;
        variants: Array<{ headline: string; summary: string }>;
    }>;
};

type Props = {
    match_types: Array<{
        family: MatchFamily;
        label: string;
        variants: Variant[];
    }>;
    injuries: { minor: Injury[]; serious: Injury[] };
    fatigue: {
        bar_size: number;
        max: number;
        safe_max: number;
        serious_at: number;
        rest_recovery_bars: number;
        serious_share: number;
        escalation_chance: number;
    };
    specialists: Array<{
        name: string;
        roster_type: RosterType;
        weight_class: WeightClass;
        specialties: MatchFamily[];
    }>;
    rosters: {
        cap: number;
        starting_budget: number;
        contract_weeks: number;
        legends_draftable: boolean;
        ai_roll_max: number;
        ai_focus_from: number;
        wwe: Record<
            'raw' | 'smackdown',
            Array<{
                name: string;
                roster_type: RosterType;
                weight_class: WeightClass;
            }>
        >;
    };
    stipulations: Array<{ value: Stipulation; label: string }>;
    promos: {
        slots: number;
        types: Promo[];
        merchandise_tiers: Array<{ amount: number; chance: number }>;
    };
    news: { stories_per_brand: number; groups: string[]; events: NewsEvent[] };
    promo_stories: StoryGroup[];
    quiet_stories: StoryGroup[];
    emails: GmEmail[];
    results: { booked_non_decisive_percent: number };
    calendar: {
        weekly_show_days: Record<'raw' | 'smackdown', string>;
        weeks_per_season: number;
        season_start: string;
        season_end: string;
        ppvs: Array<{
            key: string;
            name: string;
            season_week: number;
            date: string;
            brand_scope: string;
        }>;
    };
};

const tabs = [
    { key: 'matches', label: 'Match types' },
    { key: 'injuries', label: 'Fatigue & injuries' },
    { key: 'specialties', label: 'Specialties' },
    { key: 'stipulations', label: 'Stipulations' },
    { key: 'promos', label: 'Promos' },
    { key: 'news', label: 'News & email' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'results', label: 'Results & titles' },
    { key: 'rosters', label: 'Rosters & draft' },
] as const;

type TabKey = (typeof tabs)[number]['key'];

/** Rule summaries from spec §22. */
const stipulationRules: Record<Stipulation, string[]> = {
    champion_vs_contender: [
        'Always 1 vs 1 (singles title) or 2 vs 2 (tag title), with the title not on the line.',
        'If the contender wins, they earn a title match on the next weekly show or PPV, whichever comes first. The card can’t air until that match is booked.',
        'A seriously injured contender gets the shot on the first show after they recover. Minor injuries don’t delay it.',
        'If the champion wins, nothing changes.',
    ],
    loser_leaves_show: [
        'The loser is released to Free Agency right after the show. In a tag match the whole losing team leaves. Only 1v1 and 2v2 matches can take it.',
        'The loser’s remaining contract is refunded to their brand’s budget.',
        'Any title the loser holds is vacated, and any Money in the Bank briefcase is voided.',
        'Neither brand can sign them for 4 weeks.',
    ],
    last_chance: [
        'Only bookable once the same champion(s) and contender(s) have met in at least 2 title matches this reign, with the champion winning every one.',
        'If the contender wins, they become champion as normal.',
        'If they lose, they can’t face the same champion(s) in a title match until the title changes hands.',
    ],
};

function sidesLabel(sides: number[]): string {
    return sides.join(' vs ');
}

function Badge({ children }: { children: string }) {
    return (
        <span className="inline-flex px-1.5 py-px font-condensed text-xs font-semibold tracking-wider text-ink uppercase ring-1 ring-white/25">
            {children}
        </span>
    );
}

/** The rule badges that apply to a variant, alphabetically for easy comparison. */
function variantBadges(v: Variant): string[] {
    const badges: Array<[boolean, string]> = [
        [v.rope_breaks, 'Rope breaks'],
        [v.dq, 'DQ'],
        [v.ring_out, 'Ring out'],
        [v.titles_allowed, 'Titles'],
        [v.elimination, 'Elimination'],
        [v.timed, 'Timed'],
        [v.special_referee, 'Special referee'],
        [v.divas_only, 'Divas only'],
        [!v.divas_only && !v.divas_allowed, 'No Divas'],
        [v.ppv_only && v.ppv_exclusive === null, 'PPV only'],
        [v.ppv_exclusive !== null, `${v.ppv_exclusive} only`],
        [v.brand !== null, v.brand ? `${brandName(v.brand)} only` : ''],
    ];

    return badges
        .filter(([applies]) => applies)
        .map(([, label]) => label)
        .sort((a, b) => a.localeCompare(b));
}

/** What each rule badge means (house rules, spec §10 Match rules). */
const matchRules: Array<{ rule: string; detail: string }> = [
    {
        rule: 'Rope breaks',
        detail: 'Superstars can grab the ropes to break a submission hold.',
    },
    {
        rule: 'DQ',
        detail: 'Hitting the referee, using a weapon, or repeating dirty moves after the referee steps in is a loss by disqualification.',
    },
    {
        rule: 'Ring out',
        detail: 'Superstars can leave the ring.',
    },
    {
        rule: 'Titles',
        detail: 'A championship can be booked on the line in the match.',
    },
    {
        rule: 'Elimination',
        detail: 'Competitors are eliminated one at a time. The last superstar or team left wins.',
    },
    {
        rule: 'Timed',
        detail: 'The match ends once a set amount of time has passed.',
    },
    {
        rule: 'Special referee',
        detail: 'A wrestler from your roster referees the match. They can’t compete in or manage it, can’t be seriously injured, and take no fatigue.',
    },
];

/** Display names for seeded win conditions that read better shortened. */
const winConditionLabels: Record<string, string> = {
    'Put through a table': 'Table',
};

/** What each win condition means. */
const winConditions: Array<{ rule: string; detail: string }> = [
    {
        rule: 'Pinfall',
        detail: 'Pin the opponent’s shoulders to the mat for a three-count.',
    },
    {
        rule: 'Submission',
        detail: 'Make the opponent give up to a hold.',
    },
    {
        rule: 'Count-out',
        detail: 'The referee counts to 10 when an active competitor is outside the ring. A superstar counted out loses the match for themselves or their team.',
    },
    {
        rule: 'Captain fall',
        detail: 'Pin, submit or count out the other team’s captain (the first wrestler on their side). Their whole team loses.',
    },
    {
        rule: 'Table',
        detail: 'Put an opponent through a table.',
    },
    {
        rule: 'Briefcase retrieval',
        detail: 'Climb a ladder and grab the briefcase hanging above the ring (Ladder matches). In Money in the Bank, the briefcase is a world title shot.',
    },
    {
        rule: 'Cage escape',
        detail: 'Escape the steel cage, through the door or over the top.',
    },
    {
        rule: 'Last one in the ring',
        detail: 'Throw opponents over the top rope to the floor. The last superstar left in the ring wins.',
    },
    {
        rule: 'Over the top rope',
        detail: 'Throw opponents over the top rope to eliminate them. The last superstar left wins.',
    },
    {
        rule: 'Buried alive',
        detail: 'Put the opponent in the grave and bury them.',
    },
    {
        rule: 'First blood',
        detail: 'Be the first to make the opponent bleed.',
    },
    {
        rule: 'Bra & Panties',
        detail: 'Strip the opponent down to her bra and panties.',
    },
    {
        rule: 'Most falls',
        detail: 'Score the most pinfalls and submissions before time runs out.',
    },
    {
        rule: '10-count',
        detail: 'Knock the opponent down so they can’t answer the referee’s 10-count.',
    },
    {
        rule: 'Most opponents beaten',
        detail: 'Beat as many opponents in a row as possible before losing.',
    },
];

/** The ways a variant can be won, alphabetically. */
function variantWinConditions(v: Variant): string[] {
    const conditions = v.captain_fall
        ? ['Captain fall']
        : v.win_condition === null
          ? ['Pinfall', 'Submission']
          : [winConditionLabels[v.win_condition] ?? v.win_condition];

    if (v.countout && !v.captain_fall) {
        conditions.push('Count-out');
    }

    return conditions.sort((a, b) => a.localeCompare(b));
}

function RuleList({
    title,
    rules,
}: {
    title: string;
    rules: Array<{ rule: string; detail: string }>;
}) {
    return (
        <Panel title={title}>
            <dl className="space-y-4 text-sm">
                {[...rules]
                    .sort((a, b) => a.rule.localeCompare(b.rule))
                    .map((r) => (
                        <div key={r.rule}>
                            <dt className="font-semibold text-ink">{r.rule}</dt>
                            <dd className="mt-0.5 text-ink-dim">{r.detail}</dd>
                        </div>
                    ))}
            </dl>
        </Panel>
    );
}

function MatchTypes({ families }: { families: Props['match_types'] }) {
    return (
        <div className="space-y-6">
            <div className="grid items-start gap-6 md:grid-cols-2">
                <RuleList title="Match rules" rules={matchRules} />
                <RuleList title="Win conditions" rules={winConditions} />
            </div>
            {families.map((family) => (
                <Panel
                    key={family.family}
                    title={
                        <span className="text-xl tracking-[0.08em]">
                            {family.label}
                            <span className="ml-2 text-ink-faint">
                                {family.variants.length}
                            </span>
                        </span>
                    }
                    bodyClassName="p-0 overflow-x-auto"
                >
                    <table className="gm-table">
                        <thead>
                            <tr>
                                <th>Variant</th>
                                <th>Sides</th>
                                <th>Fatigue</th>
                                <th className="text-right">Cost</th>
                                <th>Rules</th>
                                <th>Win condition</th>
                            </tr>
                        </thead>
                        <tbody>
                            {family.variants.map((v) => (
                                <tr key={v.id}>
                                    <td className="font-semibold">
                                        {v.variant}
                                        {!v.gm_bookable && (
                                            <span className="ml-2 font-condensed text-xs tracking-wider text-ink-faint uppercase">
                                                Not bookable
                                            </span>
                                        )}
                                    </td>
                                    <td className="gm-num whitespace-nowrap">
                                        {sidesLabel(v.sides)}
                                        <span className="ml-1.5 text-ink-dim">
                                            ({v.competitors})
                                        </span>
                                        {v.managers > 0 && (
                                            <span className="ml-1.5 text-ink-dim">
                                                +{v.managers} mgr
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <FatigueCost bars={v.fatigue_bars} />
                                    </td>
                                    <td className="gm-num text-right">
                                        {v.cost > 0 ? formatMoney(v.cost) : '—'}
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap gap-1">
                                            {variantBadges(v).map((label) => (
                                                <Badge key={label}>
                                                    {label}
                                                </Badge>
                                            ))}
                                        </div>
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap gap-1">
                                            {variantWinConditions(v).map(
                                                (label) => (
                                                    <Badge key={label}>
                                                        {label}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>
            ))}
        </div>
    );
}

function InjuryTable({ title, rows }: { title: string; rows: Injury[] }) {
    const escalates = rows.some((r) => r.escalates_to !== null);

    return (
        <Panel title={title} bodyClassName="p-0">
            <table className="gm-table">
                <thead>
                    <tr>
                        <th>Injury</th>
                        <th>Duration</th>
                        {escalates && <th>Escalates to</th>}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.label}>
                            <td className="font-semibold">{r.label}</td>
                            <td className="gm-num">
                                {r.min_weeks === r.max_weeks
                                    ? r.min_weeks
                                    : `${r.min_weeks}–${r.max_weeks}`}{' '}
                                weeks
                            </td>
                            {escalates && (
                                <td className="text-ink-dim">
                                    {r.escalates_to ?? '—'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </Panel>
    );
}

function ZoneMeter({ at, config }: { at: number; config: FatigueConfig }) {
    return (
        <span className="mr-3 inline-block align-middle">
            <FatigueMeter fatigue={at} config={config} size="md" />
        </span>
    );
}

function Injuries({ injuries, fatigue }: Pick<Props, 'injuries' | 'fatigue'>) {
    const percent = (n: number) => `${Math.round(n * 100)}%`;
    const meterConfig = {
        bar_size: fatigue.bar_size,
        safe_max: fatigue.safe_max,
        serious_at: fatigue.serious_at,
        max: fatigue.max,
    };

    return (
        <div className="space-y-6">
            <Panel title="Fatigue zones">
                <ul className="space-y-3 text-sm text-ink">
                    <li>
                        Fatigue runs from 0% to {fatigue.max}%, shown as bars of{' '}
                        {fatigue.bar_size}%. Each match type adds its fatigue
                        cost. At the end of every week, everyone recovers{' '}
                        {fatigue.rest_recovery_bars} bars, whether they wrestled
                        or not, and never drops below 0%. A successful Inactive
                        Superstar promo adds 2 more for its target.
                    </li>
                    <li>
                        Injuries are decided by the fatigue a superstar ends a
                        match (or a tiring promo) on: fatigue going in plus the
                        match’s cost, before it’s capped at {fatigue.max}%.
                    </li>
                    <li>
                        <ZoneMeter config={meterConfig} at={fatigue.safe_max} />
                        <span className="font-semibold text-gm-orange">
                            Ends at 0–{fatigue.safe_max}%
                        </span>
                        : no injury.
                    </li>
                    <li>
                        <ZoneMeter config={meterConfig} at={fatigue.max} />
                        <span className="font-semibold text-zone-minor">
                            Ends at {fatigue.safe_max + fatigue.bar_size}–
                            {fatigue.max}%
                        </span>
                        : always an injury, {percent(1 - fatigue.serious_share)}{' '}
                        minor and {percent(fatigue.serious_share)} serious.
                    </li>
                    <li>
                        <ZoneMeter config={meterConfig} at={fatigue.max} />
                        <span className="font-semibold text-zone-serious">
                            Would end over {fatigue.max}%
                        </span>{' '}
                        (e.g. {fatigue.safe_max}% going into a 4-bar match):
                        always a serious injury. The results show all bars full
                        with the real total, like “110% Injury”.
                    </li>
                    <li>
                        On the results, a seriously injured superstar shows the
                        injury instead of their after-recovery fatigue, e.g.
                        “Serious Injury: Torn ACL!”. A minor injury still
                        recovers as usual, with “Minor Injury: …!” beside it.
                    </li>
                    <li>
                        A minor-injured wrestler who competes has a{' '}
                        {percent(fatigue.escalation_chance)} chance of the
                        injury becoming serious. Seriously injured wrestlers
                        can’t compete or manage.
                    </li>
                </ul>
            </Panel>
            <div className="grid gap-6 lg:grid-cols-2">
                <InjuryTable
                    title={`Minor injuries · ${injuries.minor.length}`}
                    rows={injuries.minor}
                />
                <InjuryTable
                    title={`Serious injuries · ${injuries.serious.length}`}
                    rows={injuries.serious}
                />
            </div>
        </div>
    );
}

function Specialties({ specialists }: Pick<Props, 'specialists'>) {
    return (
        <Panel title="Match specialties" bodyClassName="p-0">
            <p className="border-b border-white/6 px-4 py-3 text-sm text-ink-dim">
                A specialist gets a bonus win weight in every variant of their
                specialty’s match type. If both sides have the specialty, the
                bonuses cancel out. Money in the Bank counts as a Ladder match.
            </p>
            <table className="gm-table">
                <thead>
                    <tr>
                        <th>Wrestler</th>
                        <th>Specialties</th>
                    </tr>
                </thead>
                <tbody>
                    {specialists.map((w) => (
                        <tr key={w.name}>
                            <td className="font-semibold">
                                <WrestlerName wrestler={w} />
                            </td>
                            <td>
                                <div className="flex flex-wrap gap-1">
                                    {w.specialties.map((s) => (
                                        <SpecialtyBadge key={s} family={s} />
                                    ))}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Panel>
    );
}

function Stipulations({ stipulations }: Pick<Props, 'stipulations'>) {
    return (
        <div className="space-y-6">
            <p className="max-w-3xl text-sm text-ink-dim">
                A stipulation is an optional clause on a match. Max one per
                match. A contender who wins by DQ or count-out counts as losing
                to the champion(s).
            </p>
            <div className="grid gap-6 lg:grid-cols-3">
                {stipulations.map((s) => (
                    <Panel key={s.value} title={s.label}>
                        <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                            {stipulationRules[s.value].map((rule) => (
                                <li key={rule}>{rule}</li>
                            ))}
                        </ul>
                    </Panel>
                ))}
            </div>
        </div>
    );
}

function promoAmount(p: Promo): string {
    if (p.variable_amount) {
        return '+ variable';
    }

    if (p.amount === 0) {
        return '$0';
    }

    return `${p.amount > 0 ? '+' : '−'}${formatMoney(Math.abs(p.amount))}`;
}

function Promos({ promos }: Pick<Props, 'promos'>) {
    return (
        <div className="space-y-6">
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="How promos work">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Weekly shows have {promos.slots} promo slots. PPVs
                            have none.
                        </li>
                        <li>
                            Each promo rolls against its success probability.
                            The cost is paid whether it works or not. Every
                            promo succeeds except Advertising, Interview Space,
                            Interference, Slander and Merchandise Sponsorship.
                        </li>
                        <li data-testid="merchandise-tiers">
                            Merchandise Sponsorship rolls one deal when it airs,
                            with equal odds:{' '}
                            {promos.merchandise_tiers
                                .map(
                                    (t) =>
                                        `${formatMoney(t.amount)} (${Math.round(t.chance * 100)}% success)`,
                                )
                                .join(', ')}
                            . A success pays that amount; a failure pays
                            nothing.
                        </li>
                        <li>
                            A failed Interview Space for someone not booked on
                            the card gets crashed: a random healthy male
                            superstar from the same brand decks them. If neither
                            is in a rivalry yet, that starts one.
                        </li>
                        <li>
                            Promos nobody on your roster can do are greyed out
                            on the card screen.
                        </li>
                        <li>
                            A wrestler can be in only one of each promo per
                            card: with two Interview Spaces or two Diva Bikini
                            Contests, anyone already in the other one is greyed
                            out (“In Promo 1”). Different promos are fine.
                        </li>
                        <li>
                            Save promo greys out once the promo is saved, and
                            lights up again when you change something, with
                            Unsaved changes beside it. Discard puts the promo
                            back to what’s saved.
                        </li>
                        <li>
                            Interference and Slander always make the neutral
                            news headline, success or fail, unless it’s a PPV
                            week: then the PPV’s main event takes it.
                        </li>
                    </ul>
                </Panel>
                <Panel title="When promos air">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Each promo airs before a match you pick: Before
                            match 1 through Before main event. The default is
                            Before main event.
                        </li>
                        <li>
                            Two promos can’t air back to back: each spot holds
                            one promo. Once a promo is saved in a spot, the
                            other promo slot shows that spot crossed out, and a
                            new promo defaults to the latest spot still free.
                        </li>
                        <li>
                            Promos with a fatigue cost tire every wrestler in
                            them right there, success or fail, so that fatigue
                            counts going into their later matches: Interference
                            and Start a Rivalry 2 bars, Diva Bikini Contest 2
                            bars (both Divas), Continue Rivalry 1 bar (both
                            rivals).
                        </li>
                        <li>
                            Promo fatigue rolls injuries like a match, on the
                            fatigue it leaves them on: 80–100% is always an
                            injury, over 100% always a serious one.
                        </li>
                        <li>
                            A Title Match Hype whose title match is gone by air
                            time fizzles. Its cost is still paid.
                        </li>
                    </ul>
                </Panel>
            </div>
            <Panel title="Promo list" bodyClassName="p-0 overflow-x-auto">
                <table className="gm-table text-sm">
                    <thead>
                        <tr>
                            <th>Promo</th>
                            <th>Cost / profit</th>
                            <th>Success probability</th>
                            <th>Fatigue</th>
                            <th>Needs</th>
                            <th>Effect</th>
                        </tr>
                    </thead>
                    <tbody>
                        {promos.types.map((p) => (
                            <tr key={p.key} data-testid={`promo-${p.key}`}>
                                <td className="font-semibold whitespace-nowrap">
                                    {p.name}
                                </td>
                                <td
                                    className={cn(
                                        'gm-num whitespace-nowrap',
                                        (p.amount > 0 || p.variable_amount) &&
                                            'text-zone-safe',
                                    )}
                                >
                                    {promoAmount(p)}
                                </td>
                                <td className="gm-num">{successLabel(p)}</td>
                                <td>
                                    {p.recovery_bars > 0 ? (
                                        <FatigueCost
                                            bars={p.recovery_bars}
                                            recovers
                                        />
                                    ) : p.fatigue_bars > 0 ? (
                                        <FatigueCost bars={p.fatigue_bars} />
                                    ) : (
                                        <span className="text-ink-faint">
                                            None
                                        </span>
                                    )}
                                </td>
                                <td className="text-ink-dim">
                                    {p.requirement}
                                </td>
                                <td className="text-ink-dim">{p.effect}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Panel>
        </div>
    );
}

const cardLabel: Record<NewsEvent['card'], string> = {
    brand: 'Raw / Smackdown',
    wwe: 'WWE',
    either: 'Raw / Smackdown or WWE',
};

/** Counts read as N and M ("N-match winning streak"); the rest as words. */
function placeholderLabel(name: string): string {
    return name.length === 1 ? name.toUpperCase() : name.replaceAll('_', ' ');
}

/** A news template with its `{placeholders}` picked out. */
function Template({ text }: { text: string }) {
    return (
        <>
            {text.split(/(\{\w+\})/).map((part, i) =>
                /^\{\w+\}$/.test(part) ? (
                    <span
                        key={i}
                        data-testid="template-placeholder"
                        className="font-semibold text-gold-hot"
                    >
                        {placeholderLabel(part.slice(1, -1))}
                    </span>
                ) : (
                    part
                ),
            )}
        </>
    );
}

/**
 * Every situation of a story catalog with all its versions, one collapsible
 * group each.
 */
function StoryCatalogPanel({
    title,
    intro,
    groups,
    testIdPrefix,
}: {
    title: string;
    intro: React.ReactNode;
    groups: StoryGroup[];
    testIdPrefix: string;
}) {
    return (
        <Panel title={title} bodyClassName="p-0">
            <div className="space-y-2 border-b border-white/6 px-4 py-3 text-sm text-ink-dim">
                {intro}
            </div>
            <div className="divide-y divide-white/6">
                {groups.map((group) => (
                    <details
                        key={group.key}
                        data-testid={`${testIdPrefix}-${group.key}`}
                        className="group px-4 py-3"
                    >
                        <summary className="cursor-pointer font-semibold text-ink">
                            {group.name}
                            <span className="ml-2 text-sm font-normal text-ink-dim">
                                {group.situations.length} situations
                            </span>
                        </summary>
                        <div className="mt-3 space-y-4">
                            {group.situations.map((s) => (
                                <div key={s.situation}>
                                    <div className="gm-label mb-1">
                                        {s.label} ({s.variants.length})
                                    </div>
                                    <ul className="space-y-2 text-sm">
                                        {s.variants.map((v, i) => (
                                            <li key={i}>
                                                {v.headline && (
                                                    <div className="text-ink">
                                                        <Template
                                                            text={v.headline}
                                                        />
                                                    </div>
                                                )}
                                                {v.summary && (
                                                    <div className="text-ink-dim">
                                                        <Template
                                                            text={v.summary}
                                                        />
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </details>
                ))}
            </div>
        </Panel>
    );
}

function PromoStoriesPanel({ promoStories }: { promoStories: StoryGroup[] }) {
    return (
        <StoryCatalogPanel
            title="Promo stories"
            testIdPrefix="promo-stories"
            groups={promoStories}
            intro={
                <>
                    <p>
                        Every promo writes its own story once the show has
                        aired, based on what happened around it: how the
                        wrestler’s match went, how the hyped main event or title
                        match ended, or the wrestler’s form.
                    </p>
                    <p>
                        Each situation has at least 4 versions, and one is
                        picked with equal odds. Extras join the pool when they
                        fit (a streak, a long wait for a title shot, holding a
                        title).
                    </p>
                </>
            }
        />
    );
}

function QuietWeekStoriesPanel({
    quietStories,
}: {
    quietStories: StoryGroup[];
}) {
    return (
        <StoryCatalogPanel
            title="Quiet-week stories"
            testIdPrefix="quiet-stories"
            groups={quietStories}
            intro={
                <>
                    <p>
                        The News page is never empty. When a week airs, any card
                        its news left empty gets a quiet-week story, written
                        then, so an old week still shows that week’s champions,
                        budget and injuries.
                    </p>
                    <ul className="list-disc space-y-1 pl-4">
                        <li>
                            WWE headline: tickets on sale for whatever airs
                            next, the PPV by name or the weekly show with its
                            date.
                        </li>
                        <li>
                            Brand headline: the brand’s top champion (world,
                            then second tier, tag, special) spent the week with
                            the fans in a city, or with no champions the roster
                            hung out at WWE HQ. Then a line on the budget, a
                            warning at $500,000 or less.
                        </li>
                        <li>
                            Brand stories: a random superstar’s last match, then
                            the injury report for the last 6 months, with
                            booking advice if anyone got hurt.
                        </li>
                        <li>
                            Ticker: unhappy wrestlers and contracts about to run
                            out, or all quiet.
                        </li>
                    </ul>
                    <p>
                        Quiet-week stories rank lowest and never take a slot
                        from real news. One version is picked with equal odds.
                    </p>
                </>
            }
        />
    );
}

function NewsAndEmail({
    news,
    emails,
    promoStories,
    quietStories,
}: Pick<Props, 'news' | 'emails'> & {
    promoStories: StoryGroup[];
    quietStories: StoryGroup[];
}) {
    return (
        <div className="space-y-6">
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="How the news works">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            The News page is a weekly report: what happened,
                            why, and what’s coming next.
                        </li>
                        <li>
                            Everything newsworthy in a week goes into one pool:
                            match results, titles, stipulations, promos,
                            rivalries, signings, contracts, injuries and
                            upcoming PPVs.
                        </li>
                        <li>
                            Headlines and stories are drawn from the pool at
                            random. Bigger news is more likely to be picked, and
                            a week always lays out the same way.
                        </li>
                        <li>
                            A plain match result gives way to bigger news from
                            the same match, like a title change or a snapped
                            streak.
                        </li>
                        <li>
                            Match results rank by size, in this order: a PPV
                            match, then a title match, then the main event, then
                            a gimmick match (anything but a plain singles, tag,
                            6-man tag or handicap match; Table tag matches count
                            as gimmicks). A win by DQ or count-out ranks a
                            little lower.
                        </li>
                    </ul>
                </Panel>
                <Panel title="The page">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Three headlines: Raw, WWE and Smackdown. Each
                            brand’s card only shows that brand’s news. The WWE
                            card shows cross-brand news, PPV news included.
                        </li>
                        <li>
                            The WWE headline, in order: in a PPV week, the PPV’s
                            main event (who won, any title on the line, and the
                            gimmick, e.g. “Backlash main event: John Cena
                            defeats Edge in a Ladder match to win the WWE
                            Championship!”), or the Royal Rumble winner. Then a
                            Slander or Interference result. Then other
                            cross-brand news (trades, defections, the re-draft,
                            “Backlash is 1 week away.”…). Last, tickets on sale
                            for whatever airs next.
                        </li>
                        <li>
                            {news.stories_per_brand} stories per brand under the
                            headlines. A title change always takes its brand’s
                            headline. A brand with no headline-sized news gets
                            its biggest match as the headline instead (by the
                            order above), or the bigger news that replaced that
                            match’s result.
                        </li>
                        <li>
                            Every card shows its headline and a short summary of
                            why it happened. Click a story to read all of it.
                        </li>
                        <li>
                            Whatever isn’t picked runs in the ticker. Older and
                            Newer (or ← / →) step through every week played,
                            quiet weeks included.
                        </li>
                    </ul>
                </Panel>
            </div>

            <Panel title="News events" bodyClassName="p-0 overflow-x-auto">
                <table className="gm-table text-sm">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Card</th>
                            <th>Plays as</th>
                            <th>Headline and summary</th>
                        </tr>
                    </thead>
                    {news.groups.map((group) => (
                        <tbody key={group}>
                            <tr>
                                <th
                                    colSpan={4}
                                    className="bg-white/[0.04] font-condensed tracking-[0.15em] text-ink-dim uppercase"
                                >
                                    {group}
                                </th>
                            </tr>
                            {news.events
                                .filter((e) => e.group === group)
                                .map((e) => (
                                    <tr
                                        key={e.type}
                                        data-testid={`news-event-${e.type}`}
                                        className={cn(
                                            !e.active && 'opacity-60',
                                        )}
                                    >
                                        <td className="font-semibold whitespace-nowrap">
                                            {e.label}
                                            {!e.active && (
                                                <div className="mt-1">
                                                    <Badge>
                                                        Not in play yet
                                                    </Badge>
                                                </div>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap text-ink-dim">
                                            {cardLabel[e.card]}
                                        </td>
                                        <td className="text-ink-dim">
                                            {e.tier}
                                        </td>
                                        <td>
                                            <div className="text-ink">
                                                <Template text={e.headline} />
                                            </div>
                                            <div className="mt-1 text-ink-dim">
                                                <Template text={e.summary} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    ))}
                </table>
            </Panel>

            <PromoStoriesPanel promoStories={promoStories} />

            <QuietWeekStoriesPanel quietStories={quietStories} />

            <Panel title="Emails" bodyClassName="p-0 overflow-x-auto">
                <p className="border-b border-white/6 px-4 py-3 text-sm text-ink-dim">
                    Your inbox holds private notices for your brand only. Most
                    come from your announcer; the rest come from the rival GM,
                    Vince McMahon, the Trainer or the superstars themselves.
                </p>
                <table className="gm-table text-sm">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>From</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        {emails.map((m) => (
                            <tr key={m.email} data-testid="email-row">
                                <td className="font-semibold whitespace-nowrap">
                                    {m.email}
                                </td>
                                <td className="whitespace-nowrap text-ink-dim">
                                    {m.from}
                                </td>
                                <td className="text-ink-dim">{m.when}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Panel>
        </div>
    );
}

const scopeLabel: Record<string, string> = {
    raw: 'Raw',
    smackdown: 'Smackdown',
    shared: 'Raw & Smackdown',
};

function RostersDocs({ rosters }: Pick<Props, 'rosters'>) {
    return (
        <div className="space-y-6">
            <p className="max-w-3xl text-sm text-ink-dim">
                A new game picks how both rosters are built. Either way each
                brand starts with a {formatMoney(rosters.starting_budget)}{' '}
                budget, granted once, and can hold up to {rosters.cap} wrestlers
                (Superstars, Divas and Legends all count).
            </p>
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="Manual draft pick">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            You and the rival GM alternate picks from the whole
                            pool. Who picks first is random.
                        </li>
                        <li>
                            Up to {rosters.cap} picks each. You can stop early
                            and fill spots from Free Agency later.
                        </li>
                        <li>
                            {rosters.legends_draftable
                                ? 'Legends can be drafted.'
                                : 'Legends can’t be drafted. They wait in Free Agency.'}
                        </li>
                        <li>
                            Each pick signs a {rosters.contract_weeks}-week
                            contract, and its signing fee comes out of the
                            budget.
                        </li>
                        <li>
                            The rival GM rolls 1–{rosters.ai_roll_max} on every
                            pick. On {rosters.ai_focus_from}+ it goes after its
                            brand’s division (Raw:{' '}
                            <span className="gm-name-diva font-semibold">
                                Divas
                            </span>
                            ; Smackdown:{' '}
                            <span className="gm-name-cruiser font-semibold">
                                Cruiserweights and Light Heavyweights
                            </span>
                            ), otherwise anyone affordable.
                        </li>
                    </ul>
                </Panel>
                <Panel title="Use WWE roster">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            No draft. Each brand signs its real WWE roster from
                            November 14, 2005, when Smackdown vs Raw 2006 came
                            out, trimmed to {rosters.cap} each.
                        </li>
                        <li>
                            Signings work like draft picks: a{' '}
                            {rosters.contract_weeks}-week contract, with its
                            signing fee out of the budget.
                        </li>
                        <li>
                            Everyone else, Legends included, starts in Free
                            Agency.
                        </li>
                        <li>
                            The game goes straight to crowning your champions.
                        </li>
                    </ul>
                </Panel>
            </div>

            <div className="grid items-start gap-6 md:grid-cols-2">
                {(['raw', 'smackdown'] as const).map((brand) => (
                    <Panel
                        key={brand}
                        title={`WWE roster: ${brandName(brand)} (${rosters.wwe[brand].length})`}
                        bodyClassName="p-0"
                    >
                        <ul className="grid grid-cols-2 gap-x-4 gap-y-1 px-4 py-3 text-sm">
                            {rosters.wwe[brand].map((w) => (
                                <li key={w.name}>
                                    <WrestlerName wrestler={w} />
                                </li>
                            ))}
                        </ul>
                    </Panel>
                ))}
            </div>
        </div>
    );
}

function ScheduleDocs({ calendar }: Pick<Props, 'calendar'>) {
    const days = calendar.weekly_show_days;

    return (
        <div className="space-y-6">
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="The season">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            A season is {calendar.weeks_per_season} weeks, from{' '}
                            {calendar.season_start} to WrestleMania on{' '}
                            {calendar.season_end}. Every season repeats the same
                            calendar.
                        </li>
                        <li>
                            Weeks start on Sunday. PPVs air on the Sunday, then
                            Raw on {days.raw} and Smackdown on {days.smackdown}.
                        </li>
                        <li>
                            The Schedule shows both brands on a month calendar.
                            Click any show that has aired, yours or the rival’s,
                            to see its results.
                        </li>
                    </ul>
                </Panel>
                <Panel title="Booking a week">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Cards are booked in date order: your PPV card first,
                            then the interbrand card (Royal Rumble or Survivor
                            Series entrants, or WrestleMania), then your weekly
                            show.
                        </li>
                        <li>
                            “Continue to …” locks a card and opens the next one.
                            Unlock it to make changes until the week airs.
                        </li>
                        <li>
                            Air week on your weekly show starts the week’s live
                            results, where you decide your matches (see Results
                            &amp; titles). While the week airs the button reads
                            Resume results.
                        </li>
                        <li>Both buttons ask you to confirm first.</li>
                    </ul>
                </Panel>
            </div>

            <Panel title="Simulate to a date">
                <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                    <li>
                        Click one of your own later show nights on the Schedule
                        (your weekly show, your brand’s PPVs or a shared PPV) to
                        simulate up to that week. The rival’s shows can’t be
                        picked.
                    </li>
                    <li>
                        The computer books your cards for every week in between
                        the way the rival GM does: Preset matches, random promos
                        and random Royal Rumble entrants.
                    </li>
                    <li>
                        Those weeks air for real: results, fatigue, injuries,
                        title changes and history, contracts, morale, news and
                        email all happen as if you had booked them.
                    </li>
                    <li>
                        Simulation stops at WrestleMania, and the week you
                        picked is left for you to book. It can’t be undone.
                    </li>
                </ul>
            </Panel>

            <Panel title="Pay-per-views" bodyClassName="p-0 overflow-x-auto">
                <table className="gm-table text-sm">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>PPV</th>
                            <th>Date</th>
                            <th>Brand</th>
                        </tr>
                    </thead>
                    <tbody>
                        {calendar.ppvs.map((p) => (
                            <tr key={p.key} data-testid={`ppv-${p.key}`}>
                                <td className="gm-num text-ink-dim">
                                    {p.season_week}
                                </td>
                                <td className="font-semibold">{p.name}</td>
                                <td className="whitespace-nowrap text-ink-dim">
                                    {p.date}
                                </td>
                                <td className="text-ink-dim">
                                    {scopeLabel[p.brand_scope] ?? p.brand_scope}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Panel>
        </div>
    );
}

function ResultsDocs({ results }: Pick<Props, 'results'>) {
    return (
        <div className="space-y-6">
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="Live results">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Air week opens the results with your matches still
                            to be decided. Shows air in order: PPVs, then the
                            interbrand card, then the weekly shows.
                        </li>
                        <li>
                            The rival GM’s own shows always simulate on their
                            own when their turn comes. Your shows and the
                            interbrand cards wait for you.
                        </li>
                        <li>
                            Matches are decided one at a time, in card order.
                            The next one has Manual book to win: its card lists
                            each side with Book to Win, plus Simulate match.
                            Later matches wait their turn.
                        </li>
                        <li>
                            Simulate all, beside Continue, decides every match
                            left. Promos air just before the match they were
                            placed in front of.
                        </li>
                        <li>
                            After the last match the rest of the week runs
                            (recovery, contracts, morale, news, email) and
                            Continue opens the News.
                        </li>
                        <li>
                            Signings, releases and trades wait until the week’s
                            results are in. Simulate to a date finishes a
                            half-aired week as it stands.
                        </li>
                    </ul>
                </Panel>
                <Panel title="Book to Win">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>The side you book always wins.</li>
                        <li>
                            A booked title match always ends with a decisive
                            finish: a pinfall, a submission or the match’s own
                            win condition, never a DQ or count-out. The title
                            changes hands when the challenger wins.
                        </li>
                        <li>
                            Any other booked match ends by DQ or count-out{' '}
                            <span data-testid="booked-non-decisive">
                                {results.booked_non_decisive_percent}%
                            </span>{' '}
                            of the time, where the match type allows them.
                            Otherwise it’s a decisive finish.
                        </li>
                        <li>
                            Simulate match and Simulate all use the normal odds:
                            weights, specialties and finish events.
                        </li>
                        <li>
                            A booked result shows Booked to win on its card.
                        </li>
                    </ul>
                </Panel>
            </div>

            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="The results screen">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Every result is listed in air order, and each opens
                            its full outcome card, with arrows to step through.
                        </li>
                        <li>
                            Promos sit before the match they aired in front of:
                            Promo 1, Promo 2…, the promo and its wrestlers, with
                            the result on the right.{' '}
                            <span className="font-bold text-zone-safe">
                                Succeeded
                            </span>{' '}
                            is green;{' '}
                            <span className="font-bold text-zone-serious">
                                Failed
                            </span>
                            , Fizzled and Backfired are red.
                        </li>
                        <li>
                            In a title match the champion going in is tagged
                            <span className="ml-1 text-sm text-gold">(C)</span>.
                        </li>
                        <li>
                            The week’s fatigue (going in, after the show, after
                            recovery) appears and plays once every match is
                            decided.
                        </li>
                    </ul>
                </Panel>
                <Panel title="Title History">
                    <ul className="list-disc space-y-2 pl-4 text-sm text-ink">
                        <li>
                            Every title keeps its full lineage across seasons,
                            vacancies included, plus records.
                        </li>
                        <li>
                            Won is the season and week, with the PPV’s name
                            under it when the title changed hands at one.
                        </li>
                        <li>
                            Reign counts the holder’s own reigns with that title
                            (a third reign shows 3).
                        </li>
                        <li>
                            How is Awarded, or the match the title was won in. A
                            vacant row says why the title was vacated.
                        </li>
                        <li>
                            Under each reign, every match it was defended in, a
                            DQ or count-out loss included, newest first, then
                            the match the title was won in (marked Won). Click
                            one to open that match’s results.
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    );
}

export default function Docs() {
    const props = usePage<Props & { game?: GameShell }>().props;
    const [tab, setTab] = useState<TabKey>('matches');
    const [direction, setDirection] = useState<'left' | 'right'>('right');
    const tabRefs = useRef(new Map<TabKey, HTMLButtonElement>());
    const [indicator, setIndicator] = useState({ left: 0, top: 0, width: 0 });

    // Slide the active-tab underline under the selected tab, and keep it
    // there when the tabs reflow (window resize, wrapping on phones).
    useLayoutEffect(() => {
        const measure = () => {
            const el = tabRefs.current.get(tab);

            if (el) {
                setIndicator({
                    left: el.offsetLeft,
                    top: el.offsetTop + el.offsetHeight - 2,
                    width: el.offsetWidth,
                });
            }
        };

        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [tab]);

    const selectTab = (next: TabKey) => {
        const order = tabs.map((t) => t.key);
        setDirection(
            order.indexOf(next) < order.indexOf(tab) ? 'left' : 'right',
        );
        setTab(next);
    };
    // Inside a career the GM header and nav are on screen; from the save
    // list there's no career, so offer the way back instead.
    const inCareer = props.game !== undefined;

    return (
        <>
            <Head title="Documentation" />
            <PageTitle kicker="Reference" title="Documentation">
                {!inCareer && (
                    <Link
                        href={gm.saves.index.url()}
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                    >
                        GM Mode home
                    </Link>
                )}
            </PageTitle>

            <div
                role="tablist"
                aria-label="Documentation sections"
                className="relative mb-6 flex flex-wrap gap-1 border-b border-white/8"
            >
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        ref={(el) => {
                            if (el) {
                                tabRefs.current.set(t.key, el);
                            }
                        }}
                        type="button"
                        role="tab"
                        aria-selected={tab === t.key}
                        data-active={tab === t.key || undefined}
                        onClick={() => selectTab(t.key)}
                        className={cn(
                            'relative px-3 py-2.5 font-condensed text-sm font-semibold tracking-[0.12em] uppercase transition-colors',
                            tab === t.key
                                ? 'text-white'
                                : 'text-ink-dim hover:text-ink',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
                <span
                    aria-hidden
                    data-testid="tab-indicator"
                    className="absolute h-0.5 bg-brand shadow-[0_0_10px_var(--gm-brand)] transition-[left,top,width] duration-300 ease-out motion-reduce:transition-none"
                    style={indicator}
                />
            </div>

            <div
                role="tabpanel"
                key={tab}
                className={
                    direction === 'right'
                        ? 'animate-gm-slide-from-right'
                        : 'animate-gm-slide-from-left'
                }
            >
                {tab === 'matches' && (
                    <MatchTypes families={props.match_types} />
                )}
                {tab === 'injuries' && (
                    <Injuries
                        injuries={props.injuries}
                        fatigue={props.fatigue}
                    />
                )}
                {tab === 'specialties' && (
                    <Specialties specialists={props.specialists} />
                )}
                {tab === 'stipulations' && (
                    <Stipulations stipulations={props.stipulations} />
                )}
                {tab === 'promos' && <Promos promos={props.promos} />}
                {tab === 'news' && (
                    <NewsAndEmail
                        news={props.news}
                        emails={props.emails}
                        promoStories={props.promo_stories}
                        quietStories={props.quiet_stories}
                    />
                )}
                {tab === 'schedule' && (
                    <ScheduleDocs calendar={props.calendar} />
                )}
                {tab === 'results' && <ResultsDocs results={props.results} />}
                {tab === 'rosters' && <RostersDocs rosters={props.rosters} />}
            </div>
        </>
    );
}
