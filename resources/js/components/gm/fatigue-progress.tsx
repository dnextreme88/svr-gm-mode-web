import { RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import type { RosterType, WeightClass } from '@/types/enums';
import type { FatigueConfig } from '@/types/gm';

/** One wrestler's fatigue through a resolved week. */
export type FatigueStep = {
    id: number;
    name: string;
    roster_type: RosterType;
    weight_class: WeightClass;
    start: number;
    after_shows: number;
    end: number;
    recovered_bars: number;
    /** Got an Inactive Superstar promo's extra recovery. */
    rested: boolean;
};

const STAGES = ['Going in', 'After the show', 'After recovery'] as const;
/** How long each stage stays on screen before the next one. */
const STEP_MS = 1100;

/**
 * Animates each wrestler's fatigue through the week, alphabetically: going in, after the
 * show (matches and promos), then after end-of-week recovery.
 */
export function FatigueProgress({
    wrestlers,
    config,
}: {
    wrestlers: FatigueStep[];
    config: FatigueConfig;
}) {
    const sorted = useMemo(
        () => [...wrestlers].sort((a, b) => a.name.localeCompare(b.name)),
        [wrestlers],
    );
    const [stage, setStage] = useState(0);
    const [run, setRun] = useState(0);

    useEffect(() => {
        setStage(0);
        const timers = [1, 2].map((next) =>
            setTimeout(() => setStage(next), next * STEP_MS),
        );

        return () => timers.forEach(clearTimeout);
    }, [run, wrestlers]);

    const valueAt = (w: FatigueStep) =>
        stage === 0 ? w.start : stage === 1 ? w.after_shows : w.end;

    return (
        <Panel
            title="Fatigue this week"
            actions={
                <button
                    type="button"
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                    onClick={() => setRun((r) => r + 1)}
                >
                    <RotateCcw aria-hidden className="size-3.5" />
                    Replay
                </button>
            }
        >
            <ol
                className="mb-3 flex flex-wrap gap-2 font-condensed text-xs tracking-wider uppercase"
                aria-label="Fatigue stage"
            >
                {STAGES.map((label, i) => (
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
                                <FatigueMeter
                                    fatigue={valueAt(w)}
                                    config={config}
                                    showLabel
                                />
                                <span className="font-condensed text-sm tracking-wide text-ink-faint uppercase">
                                    {w.start}% → {w.after_shows}% → {w.end}%
                                    {stage === 2 && (
                                        <span
                                            className={cn(
                                                'ml-2',
                                                w.rested
                                                    ? 'text-zone-safe'
                                                    : 'text-ink-dim',
                                            )}
                                        >
                                            Recovered: −{w.recovered_bars} bars
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
        </Panel>
    );
}
