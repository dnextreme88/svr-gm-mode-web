import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const STEPS = ['Draft your roster', 'Crown your champions'] as const;

/**
 * Where a new career stands while it's set up (2026-11-21): the initial
 * draft is step 1, crowning the first champions step 2. The menu bar is
 * hidden on both pages, so this is the way to tell where you are. A step
 * before the current one is done (with Use WWE roster the draft is skipped,
 * but it reads as done all the same).
 */
export function SetupSteps({ current }: { current: 1 | 2 }) {
    return (
        <nav aria-label="Career setup" className="mb-6">
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-2">
                {STEPS.map((label, i) => {
                    const step = i + 1;
                    const done = step < current;
                    const active = step === current;

                    return (
                        <li
                            key={label}
                            aria-current={active ? 'step' : undefined}
                            data-testid={`setup-step-${step}`}
                            className="flex items-center gap-3"
                        >
                            {i > 0 && (
                                <span
                                    aria-hidden
                                    data-testid={`setup-line-${step}`}
                                    className="gm-step-line w-12 sm:w-20"
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
                                    Step {step} of {STEPS.length}
                                    {done ? ', done' : ''}:{' '}
                                </span>
                                {label}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
