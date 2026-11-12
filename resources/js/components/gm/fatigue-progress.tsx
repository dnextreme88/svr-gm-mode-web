import { RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { InjurySeverity, RosterType, WeightClass } from '@/types/enums';
import type { FatigueConfig } from '@/types/gm';

/** One wrestler's fatigue through a resolved week. */
export type FatigueStep = {
    id: number;
    name: string;
    roster_type: RosterType;
    weight_class: WeightClass;
    start: number;
    after_shows: number;
    /** Null until the week is over: a PPV night's fatigue before recovery. */
    end: number | null;
    recovered_bars: number;
    /** Got an Inactive Superstar promo's extra recovery. */
    rested: boolean;
    /** Hurt this week: shown instead of the after-recovery fatigue. */
    injury?: { severity: InjurySeverity; label: string } | null;
};

const STAGES = ['Going in', 'After the show', 'After recovery'] as const;

const serious = (w: FatigueStep) => w.injury?.severity === 'serious';

function InjuryNote({
    injury,
    id,
}: {
    injury: NonNullable<FatigueStep['injury']>;
    id: number;
}) {
    return (
        <span
            data-testid={`injury-${id}`}
            className={cn(
                'font-condensed text-sm font-bold tracking-wide uppercase',
                injury.severity === 'serious'
                    ? 'text-zone-serious'
                    : 'text-zone-minor',
            )}
        >
            {injury.severity === 'serious' ? 'Serious Injury' : 'Minor Injury'}:{' '}
            {injury.label}!
        </span>
    );
}
/** How long each stage stays on screen before the next one. */
const STEP_MS = 1100;

/**
 * Animates each wrestler's fatigue through the week, alphabetically: going in, after the
 * show (matches and promos), then after end-of-week recovery. `framed`
 * draws it as the "Fatigue this week" panel; without it (the fatigue modal,
 * house rule 2026-11-12) it's just the content, with Replay on top.
 */
export function FatigueProgress({
    wrestlers,
    config,
    framed = true,
}: {
    wrestlers: FatigueStep[];
    config: FatigueConfig;
    framed?: boolean;
}) {
    const sorted = useMemo(
        () => [...wrestlers].sort((a, b) => a.name.localeCompare(b.name)),
        [wrestlers],
    );
    const [stage, setStage] = useState(0);
    const [run, setRun] = useState(0);
    // After a PPV night (house rule, 2026-11-12) the week isn't over, so
    // nobody has recovered yet: two stages only.
    const beforeRecovery = wrestlers.some((w) => w.end === null);
    const stages = beforeRecovery ? STAGES.slice(0, 2) : STAGES;
    const last = stages.length - 1;

    useEffect(() => {
        setStage(0);
        const timers = (beforeRecovery ? [1] : [1, 2]).map((next) =>
            setTimeout(() => setStage(next), next * STEP_MS),
        );

        return () => timers.forEach(clearTimeout);
    }, [run, wrestlers, beforeRecovery]);

    const valueAt = (w: FatigueStep) =>
        stage === 0
            ? w.start
            : stage === 1
              ? w.after_shows
              : (w.end ?? w.after_shows);

    const replay = (
        <button
            type="button"
            className="gm-btn gm-btn-ghost gm-btn-sm"
            onClick={() => setRun((r) => r + 1)}
        >
            <RotateCcw aria-hidden className="size-3.5" />
            Replay
        </button>
    );
    const frame = (children: ReactNode) =>
        framed ? (
            <Panel title="Fatigue this week" actions={replay}>
                {children}
            </Panel>
        ) : (
            <div data-testid="fatigue-progress">
                <div className="mb-3 flex justify-end">{replay}</div>
                {children}
            </div>
        );

    return frame(
        <>
            <ol
                className="mb-3 flex flex-wrap gap-2 font-condensed text-xs tracking-wider uppercase"
                aria-label="Fatigue stage"
            >
                {stages.map((label, i) => (
                    <li
                        key={label}
                        aria-current={i === stage ? 'step' : undefined}
                        className={cn(
                            'px-2 py-0.5 transition-colors',
                            i === stage
                                ? 'bg-brand text-white'
                                : i < stage
                                  ? 'bg-white/10 text-ink'
                                  : 'bg-white/5 text-ink-faint',
                        )}
                    >
                        {label}
                    </li>
                ))}
            </ol>
            {beforeRecovery && (
                <p
                    className="mb-3 text-sm text-ink-faint"
                    data-testid="recovery-pending"
                >
                    Everyone recovers at the end of the week, once the weekly
                    show has aired.
                </p>
            )}
            {wrestlers.length === 0 ? (
                <p className="text-sm text-ink-faint">
                    Nobody wrestled or cut a promo on this show.
                </p>
            ) : (
                <ul className="space-y-2.5">
                    {sorted.map((w) => (
                        <li
                            key={w.id}
                            data-testid={`fatigue-${w.id}`}
                            className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] items-center gap-3"
                        >
                            <WrestlerName
                                wrestler={w}
                                className="truncate font-semibold"
                            />
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                {/* A serious injury takes the place of the
                                    after-recovery fatigue; a minor one still
                                    recovers, with the injury beside it. */}
                                {stage === 2 &&
                                w.injury?.severity === 'serious' ? (
                                    <InjuryNote injury={w.injury} id={w.id} />
                                ) : (
                                    <FatigueMeter
                                        fatigue={valueAt(w)}
                                        config={config}
                                        showLabel
                                    />
                                )}
                                {/* Before recovery, any injury shows
                                    beside the fatigue after the show. */}
                                {beforeRecovery &&
                                    stage === last &&
                                    w.injury && (
                                        <InjuryNote
                                            injury={w.injury}
                                            id={w.id}
                                        />
                                    )}
                                {stage === 2 &&
                                    w.injury?.severity === 'minor' && (
                                        <InjuryNote
                                            injury={w.injury}
                                            id={w.id}
                                        />
                                    )}
                                <span className="font-condensed text-sm tracking-wide text-ink-faint uppercase">
                                    {w.start}% → {w.after_shows}%
                                    {w.end !== null &&
                                        ` → ${serious(w) ? 'Injured' : `${w.end}%`}`}
                                    {stage === 2 && !serious(w) && (
                                        <span
                                            className={cn(
                                                'ml-2',
                                                w.rested
                                                    ? 'text-zone-safe'
                                                    : 'text-ink-dim',
                                            )}
                                        >
                                            Recovered: −
                                            {pluralize(w.recovered_bars, 'bar')}
                                            {w.rested &&
                                                ' (Inactive Superstar)'}
                                        </span>
                                    )}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </>,
    );
}
