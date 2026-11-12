import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { TitleBeltImage } from '@/components/gm/badges';
import { brandName, pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';

/** A defense of the current reign, as the Title History page sends it. */
export type ReignDefense = {
    kind: 'won' | 'defense';
    number: number | null;
    /** The absolute week it aired. */
    week?: number;
    season: number;
    season_week: number;
    opponents: string;
    ppv: string | null;
    match_type: string | null;
    link: string;
};

/** A title and its current reign (null while vacant). */
export type ReignTitle = {
    id: number;
    name: string;
    brand: Brand;
    current: {
        holder: string;
        won_week: number;
        won_season: number;
        won_season_week: number;
        weeks_held: number;
        defenses: number;
        matches: ReignDefense[];
    } | null;
};

/** One point on a champion's line: weeks into the reign, defenses so far. */
type Step = { x: number; y: number; defense?: ReignDefense };

type Series = {
    title: ReignTitle;
    color: string;
    holder: string;
    weeks: number;
    defenses: ReignDefense[];
    steps: Step[];
    /** A few pixels up or down, so lines sharing a stretch run side by side. */
    offset: number;
};

const WIDTH = 720;
const HEIGHT = 290;
const M = { top: 16, right: 168, bottom: 40, left: 44 };

/**
 * Titles keep one colour each, in title order (world, second tier, tag,
 * special), on both brands. The dataviz reference palette's first four
 * dark-mode slots, validated on the panel surface (#121722).
 */
const COLORS = ['#3987e5', '#d95926', '#199e70', '#c98500'];

/** The defenses of a reign, oldest first, with the week each aired. */
export function reignDefenses(title: ReignTitle): ReignDefense[] {
    return (title.current?.matches ?? [])
        .filter((m) => m.kind === 'defense' && m.week !== undefined)
        .sort((a, b) => (a.week ?? 0) - (b.week ?? 0));
}

/**
 * A champion's line as a step: 0 defenses when the title was won, up one
 * at each defense's week, then flat to this week.
 */
export function reignSteps(title: ReignTitle): Step[] {
    const current = title.current;

    if (current === null) {
        return [];
    }

    const steps: Step[] = [{ x: 0, y: 0 }];

    reignDefenses(title).forEach((d, i) => {
        const x = Math.max(0, (d.week ?? current.won_week) - current.won_week);
        steps.push({ x, y: i }, { x, y: i + 1, defense: d });
    });

    const last = steps.at(-1)!;
    steps.push({ x: Math.max(current.weeks_held, last.x), y: last.y });

    return steps;
}

/** A tick step that gives about five ticks up to `max`. */
function tickStep(max: number): number {
    return [1, 2, 4, 5, 10, 20, 26, 52].find((s) => max / s <= 6) ?? 52;
}

/** One sentence: the longest current reign and the most defenses. */
export function reignSummary(series: Series[]): string {
    const longest = [...series].sort((a, b) => b.weeks - a.weeks)[0];
    const most = [...series].sort(
        (a, b) => b.defenses.length - a.defenses.length,
    )[0];

    if (!longest) {
        return '';
    }

    const parts = [
        `Longest current reign: ${longest.holder} (${longest.title.name}), ${pluralize(longest.weeks, 'week')}`,
    ];

    if (most && most.defenses.length > 0) {
        parts.push(
            `Most defenses: ${most.holder} (${most.title.name}), ${most.defenses.length}`,
        );
    }

    return `${parts.join('. ')}.`;
}

const short = (name: string, max = 24) =>
    name.length > max ? `${name.slice(0, max - 1)}…` : name;

/**
 * The current champions' reigns, one brand at a time (house rule,
 * 2026-11-12), drawn like the Reports attendance chart: each champion is a
 * step line from the week they won (0) to this week, rising one at each
 * defense. Its length is their tenure, its height their defenses. Hovering
 * or focusing a defense shows the match; the same numbers are in a table.
 */
export function ReignChart({
    titles,
    defaultBrand,
}: {
    titles: ReignTitle[];
    defaultBrand: Brand;
}) {
    const [brand, setBrand] = useState<Brand>(defaultBrand);
    const [active, setActive] = useState<{
        series: Series;
        step: Step;
    } | null>(null);

    const brandTitles = titles.filter((t) => t.brand === brand);
    const series: Series[] = brandTitles.flatMap((title, i) =>
        title.current === null
            ? []
            : [
                  {
                      title,
                      color: COLORS[i % COLORS.length],
                      holder: title.current.holder,
                      weeks: title.current.weeks_held,
                      defenses: reignDefenses(title),
                      steps: reignSteps(title),
                      offset: 0,
                  },
              ],
    );
    series.forEach((s, i) => {
        s.offset = (i - (series.length - 1) / 2) * 3;
    });
    const vacant = brandTitles.filter((t) => t.current === null);

    const xMax = Math.max(4, ...series.map((s) => s.steps.at(-1)?.x ?? 0));
    const yMax = Math.max(3, ...series.map((s) => s.defenses.length));
    const x = (weeks: number) =>
        M.left + (weeks / xMax) * (WIDTH - M.left - M.right);
    const y = (defenses: number) =>
        M.top + (1 - defenses / yMax) * (HEIGHT - M.top - M.bottom);
    const xStep = tickStep(xMax);
    const yStep = tickStep(yMax);
    const xTicks = Array.from(
        { length: Math.floor(xMax / xStep) + 1 },
        (_, i) => i * xStep,
    );
    const yTicks = Array.from(
        { length: Math.floor(yMax / yStep) + 1 },
        (_, i) => i * yStep,
    );

    const sy = (s: Series, defenses: number) => y(defenses) + s.offset;

    // Direct labels in the right margin at each line's end height, nudged
    // apart when they'd overlap and kept above the axis.
    const ends = series
        .map((s) => ({ s, ly: sy(s, s.steps.at(-1)?.y ?? 0) }))
        .sort((a, b) => a.ly - b.ly);

    for (let i = 1; i < ends.length; i++) {
        ends[i].ly = Math.max(ends[i].ly, ends[i - 1].ly + 16);
    }

    const floor = HEIGHT - M.bottom;
    const overflow = (ends.at(-1)?.ly ?? 0) - floor;

    if (overflow > 0) {
        ends.forEach((e) => (e.ly -= overflow));
    }

    const tooltipBelow =
        active !== null && sy(active.series, active.step.y) < HEIGHT * 0.4;

    return (
        <div className="space-y-3">
            {/* The legend, then the brand buttons below it. */}
            <div className="flex flex-col items-start gap-3">
                <ul
                    aria-label="Legend"
                    className="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-ink-dim"
                >
                    {series.map((s) => (
                        <li
                            key={s.title.id}
                            className="flex items-center gap-2"
                        >
                            <svg width="28" height="10" aria-hidden>
                                <line
                                    x1="0"
                                    y1="5"
                                    x2="28"
                                    y2="5"
                                    strokeWidth="2.5"
                                    stroke={s.color}
                                />
                            </svg>
                            <TitleBeltImage name={s.title.name} decorative />
                            {s.title.name}
                        </li>
                    ))}
                </ul>
                <div role="group" aria-label="Brand" className="flex gap-1">
                    {(['raw', 'smackdown'] as Brand[]).map((b) => (
                        <button
                            key={b}
                            type="button"
                            aria-pressed={b === brand}
                            onClick={() => {
                                setBrand(b);
                                setActive(null);
                            }}
                            className={cn(
                                'gm-btn gm-btn-sm',
                                b !== brand && 'gm-btn-ghost',
                            )}
                        >
                            {brandName(b)}
                        </button>
                    ))}
                </div>
            </div>

            {series.length === 0 ? (
                <p className="text-sm text-ink-faint">
                    {brandName(brand)} has no champions right now.
                </p>
            ) : (
                <>
                    <p className="text-sm text-ink" data-testid="reign-summary">
                        {reignSummary(series)}
                        {vacant.length > 0 &&
                            ` Vacant: ${vacant.map((t) => t.name).join(', ')}.`}
                    </p>
                    {/* Phones scroll the chart sideways rather than shrink its labels. */}
                    <div className="overflow-x-auto">
                        <div className="relative min-w-[720px] md:min-w-[560px]">
                            <svg
                                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                                className="h-auto w-full"
                                role="group"
                                aria-label={`${brandName(brand)} current reigns: defenses by weeks as champion`}
                            >
                                {yTicks.map((t) => (
                                    <g key={`y-${t}`}>
                                        <line
                                            x1={M.left}
                                            x2={WIDTH - M.right}
                                            y1={y(t)}
                                            y2={y(t)}
                                            className="stroke-white/8"
                                        />
                                        <text
                                            x={M.left - 8}
                                            y={y(t)}
                                            textAnchor="end"
                                            dominantBaseline="middle"
                                            className="gm-num fill-ink-faint text-[12px]"
                                        >
                                            {t}
                                        </text>
                                    </g>
                                ))}
                                {xTicks.map((t) => (
                                    <text
                                        key={`x-${t}`}
                                        x={x(t)}
                                        y={HEIGHT - M.bottom + 16}
                                        textAnchor="middle"
                                        className="gm-num fill-ink-faint text-[12px]"
                                    >
                                        {t}
                                    </text>
                                ))}
                                <text
                                    x={(M.left + WIDTH - M.right) / 2}
                                    y={HEIGHT - 6}
                                    textAnchor="middle"
                                    className="fill-ink-faint text-[12px]"
                                >
                                    Weeks as champion
                                </text>
                                <text
                                    x={12}
                                    y={(M.top + HEIGHT - M.bottom) / 2}
                                    textAnchor="middle"
                                    transform={`rotate(-90 12 ${(M.top + HEIGHT - M.bottom) / 2})`}
                                    className="fill-ink-faint text-[12px]"
                                >
                                    Defenses
                                </text>

                                {series.map((s) => (
                                    <g
                                        key={s.title.id}
                                        data-series={s.title.name}
                                    >
                                        {s.steps.length > 1 && (
                                            <polyline
                                                fill="none"
                                                strokeWidth="2.5"
                                                strokeLinejoin="round"
                                                stroke={s.color}
                                                points={s.steps
                                                    .map(
                                                        (p) =>
                                                            `${x(p.x)},${sy(s, p.y)}`,
                                                    )
                                                    .join(' ')}
                                            />
                                        )}
                                        {s.steps
                                            .filter(
                                                (p, i) =>
                                                    p.defense !== undefined ||
                                                    i === s.steps.length - 1,
                                            )
                                            .map((p, i) => (
                                                <circle
                                                    key={i}
                                                    cx={x(p.x)}
                                                    cy={sy(s, p.y)}
                                                    r={p.defense ? 4.5 : 3}
                                                    fill={
                                                        p.defense
                                                            ? s.color
                                                            : 'var(--color-arena-2)'
                                                    }
                                                    stroke={
                                                        p.defense
                                                            ? 'var(--color-arena-2)'
                                                            : s.color
                                                    }
                                                    strokeWidth={2}
                                                    tabIndex={0}
                                                    role="img"
                                                    aria-label={
                                                        p.defense
                                                            ? `${s.holder}, ${s.title.name}: defense ${p.y} against ${p.defense.opponents}, week ${p.x} of the reign`
                                                            : `${s.holder}, ${s.title.name}: ${pluralize(s.weeks, 'week')}, ${pluralize(s.defenses.length, 'defense')}`
                                                    }
                                                    onMouseEnter={() =>
                                                        setActive({
                                                            series: s,
                                                            step: p,
                                                        })
                                                    }
                                                    onMouseLeave={() =>
                                                        setActive(null)
                                                    }
                                                    onFocus={() =>
                                                        setActive({
                                                            series: s,
                                                            step: p,
                                                        })
                                                    }
                                                    onBlur={() =>
                                                        setActive(null)
                                                    }
                                                    className="cursor-pointer outline-none focus-visible:stroke-white"
                                                />
                                            ))}
                                    </g>
                                ))}

                                {ends.map((e) => (
                                    <g key={e.s.title.id}>
                                        <circle
                                            cx={WIDTH - M.right + 14}
                                            cy={e.ly}
                                            r={4}
                                            fill={e.s.color}
                                        />
                                        <text
                                            x={WIDTH - M.right + 24}
                                            y={e.ly}
                                            dominantBaseline="middle"
                                            className="fill-ink font-condensed text-[13px] font-semibold tracking-wide"
                                        >
                                            <title>{e.s.holder}</title>
                                            {short(e.s.holder, 22)}
                                        </text>
                                    </g>
                                ))}
                            </svg>

                            {active && (
                                <div
                                    role="status"
                                    className={cn(
                                        'pointer-events-none absolute z-10 -translate-x-1/2 border border-white/10 bg-arena-3 px-3 py-2 text-sm whitespace-nowrap shadow-lg',
                                        tooltipBelow
                                            ? 'translate-y-3'
                                            : '-translate-y-full',
                                    )}
                                    style={{
                                        left: `${(x(active.step.x) / WIDTH) * 100}%`,
                                        top: `calc(${(sy(active.series, active.step.y) / HEIGHT) * 100}% - ${tooltipBelow ? 0 : 10}px)`,
                                    }}
                                >
                                    <div className="font-semibold text-ink">
                                        {active.series.holder}
                                    </div>
                                    <div className="text-xs text-ink-dim">
                                        {active.series.title.name}
                                    </div>
                                    {active.step.defense ? (
                                        <>
                                            <div className="mt-1 text-gold-hot">
                                                Defense {active.step.y} vs.{' '}
                                                {active.step.defense.opponents}
                                            </div>
                                            <div className="text-xs text-ink-dim">
                                                S{active.step.defense.season} W
                                                {
                                                    active.step.defense
                                                        .season_week
                                                }
                                                {active.step.defense.ppv &&
                                                    ` · ${active.step.defense.ppv}`}
                                                {active.step.defense
                                                    .match_type &&
                                                    ` · ${active.step.defense.match_type}`}
                                            </div>
                                        </>
                                    ) : (
                                        <div className="mt-1 text-gold-hot">
                                            {pluralize(
                                                active.series.weeks,
                                                'week',
                                            )}{' '}
                                            ·{' '}
                                            {pluralize(
                                                active.series.defenses.length,
                                                'defense',
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    <details className="text-sm">
                        <summary className="cursor-pointer text-ink-dim hover:text-ink">
                            Show as a table
                        </summary>
                        <table className="gm-table mt-2">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Champion</th>
                                    <th>Won</th>
                                    <th className="text-right">Weeks</th>
                                    <th className="text-right">Defenses</th>
                                </tr>
                            </thead>
                            <tbody>
                                {series.map((s) => (
                                    <tr key={s.title.id}>
                                        <td>{s.title.name}</td>
                                        <td>{s.holder}</td>
                                        <td className="gm-num text-ink-dim">
                                            S{s.title.current?.won_season} W
                                            {s.title.current?.won_season_week}
                                        </td>
                                        <td className="gm-num text-right">
                                            {s.weeks}
                                        </td>
                                        <td className="gm-num text-right">
                                            {s.defenses.length === 0 ? (
                                                0
                                            ) : (
                                                <Link
                                                    href={
                                                        s.defenses.at(-1)!.link
                                                    }
                                                    className="underline-offset-4 hover:underline"
                                                    title="Latest defense"
                                                >
                                                    {s.defenses.length}
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </details>
                </>
            )}
        </div>
    );
}
