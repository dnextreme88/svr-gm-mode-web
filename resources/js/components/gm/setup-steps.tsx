import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A row of numbered steps joined by lines, the current one in the brand
 * colour with the Schedule's current-week border and steps before it ticked.
 * Shared by the career setup and the week (2026-11-21).
 */
export function StepTrail({
    label,
    steps,
    current,
    testIdPrefix,
    compact = false,
}: {
    /** The nav's accessible name ("Career setup", "This week"). */
    label: string;
    steps: readonly string[];
    /** 1-based. */
    current: number;
    testIdPrefix: string;
    /** Shorter lines, so five steps fit on one row on a laptop. */
    compact?: boolean;
}) {
    return (
        <nav aria-label={label} className="mb-6">
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-2">
                {steps.map((stepLabel, i) => {
                    const step = i + 1;
                    const done = step < current;
                    const active = step === current;

                    return (
                        <li
                            key={stepLabel}
                            aria-current={active ? 'step' : undefined}
                            data-testid={`${testIdPrefix}-step-${step}`}
                            className="flex items-center gap-3"
                        >
                            {i > 0 && (
                                <span
                                    aria-hidden
                                    data-testid={`${testIdPrefix}-line-${step}`}
                                    className={cn(
                                        'gm-step-line',
                                        compact
                                            ? 'w-4 sm:w-6 xl:w-12'
                                            : 'w-12 sm:w-20',
                                    )}
                                >
                                    {(done || active) && (
                                        <span className="gm-step-line-fill" />
                                    )}
                                </span>
                            )}
                            <span
                                className={cn(
                                    'flex items-center gap-2 px-3 py-1.5 font-condensed text-sm font-semibold tracking-wider uppercase',
                                    active &&
                                        'gm-current-week animate-gm-rise bg-brand text-white',
                                    done && 'text-ink',
                                    !active && !done && 'text-ink-dim',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'gm-num flex size-6 shrink-0 items-center justify-center rounded-full text-xs',
                                        active &&
                                            'gm-step-ping bg-white text-brand',
                                        done && 'bg-brand text-white',
                                        !active &&
                                            !done &&
                                            'ring-1 ring-white/40',
                                    )}
                                >
                                    {done ? (
                                        <Check className="size-3.5" />
                                    ) : (
                                        step
                                    )}
                                </span>
                                <span className="sr-only">
                                    Step {step} of {steps.length}
                                    {done ? ', done' : ''}:{' '}
                                </span>
                                {stepLabel}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

const SETUP_STEPS = ['Draft your roster', 'Crown your champions'] as const;

/**
 * Where a new career stands while it's set up (2026-11-21): the initial
 * draft is step 1, crowning the first champions step 2. The menu bar is
 * hidden on both pages, so this is the way to tell where you are. A step
 * before the current one is done (with Use WWE roster the draft is skipped,
 * but it reads as done all the same).
 */
export function SetupSteps({ current }: { current: 1 | 2 }) {
    return (
        <StepTrail
            label="Career setup"
            steps={SETUP_STEPS}
            current={current}
            testIdPrefix="setup"
        />
    );
}

export type WeekStep = 1 | 2 | 3 | 4 | 5;

/**
 * Where the week stands (house rule, 2026-11-21): book it, decide the
 * results, then Financials, News and the inbox, the order the end of the
 * week walks through. On a PPV night the first three name the PPV.
 */
export function weekStepLabels(ppv?: string | null): string[] {
    return ppv
        ? [
              `Book ${ppv}`,
              `${ppv} results`,
              `${ppv} financials`,
              'News',
              'Emails',
          ]
        : [
              'Book the week',
              'Show results',
              'Weekly financials',
              'News',
              'Emails',
          ];
}

export function WeekSteps({
    current,
    ppv = null,
}: {
    current: WeekStep;
    /** The PPV on a PPV night ("Backlash"); null for the weekly shows. */
    ppv?: string | null;
}) {
    return (
        <StepTrail
            label="This week"
            steps={weekStepLabels(ppv)}
            current={current}
            testIdPrefix="week"
            compact
        />
    );
}
