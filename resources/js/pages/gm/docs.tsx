import { Head, Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { FatigueCost } from '@/components/gm/fatigue-cost';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PageTitle, Panel } from '@/components/gm/panel';
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
    win_condition: string | null;
};

type Injury = {
    label: string;
    severity: 'minor' | 'serious';
    min_weeks: number;
    max_weeks: number;
    escalates_to: string | null;
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
        minor_chance: number;
        escalation_chance: number;
    };
    specialists: Array<{
        name: string;
        roster_type: RosterType;
        weight_class: WeightClass;
        specialties: MatchFamily[];
    }>;
    stipulations: Array<{ value: Stipulation; label: string }>;
};

const tabs = [
    { key: 'matches', label: 'Match types' },
    { key: 'injuries', label: 'Fatigue & injuries' },
    { key: 'specialties', label: 'Specialties' },
    { key: 'stipulations', label: 'Stipulations' },
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
        rule: 'Ladder retrieval',
        detail: 'Climb a ladder and grab the item hanging above the ring.',
    },
    {
        rule: 'Briefcase retrieval',
        detail: 'Climb a ladder and grab the Money in the Bank briefcase.',
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
                        cost; resting a week recovers{' '}
                        {fatigue.rest_recovery_bars} bars.
                    </li>
                    <li>
                        <ZoneMeter config={meterConfig} at={fatigue.safe_max} />
                        <span className="font-semibold text-gm-orange">
                            Safe (0–{fatigue.safe_max}%)
                        </span>
                        : no injury risk.
                    </li>
                    <li>
                        <ZoneMeter
                            config={meterConfig}
                            at={fatigue.serious_at - fatigue.bar_size}
                        />
                        <span className="font-semibold text-zone-minor">
                            Minor risk ({fatigue.safe_max + fatigue.bar_size}–
                            {fatigue.serious_at - fatigue.bar_size}%)
                        </span>
                        : a {percent(fatigue.minor_chance)} chance of a minor
                        injury each match.
                    </li>
                    <li>
                        <ZoneMeter
                            config={meterConfig}
                            at={fatigue.serious_at}
                        />
                        <span className="font-semibold text-zone-serious">
                            Serious risk ({fatigue.serious_at}%)
                        </span>
                        : any match that adds fatigue causes a serious injury.
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
            </div>
        </>
    );
}
