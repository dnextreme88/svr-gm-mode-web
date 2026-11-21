import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { GameShell } from '@/types/gm';

export type TourStep = {
    /** The menu bar tab the step is about. */
    tab: string;
    title: string;
    body: string[];
};

/**
 * The guided tour (house rule, 2026-11-21): one step for every tab on the
 * menu bar, in its order.
 */
export const TOUR_STEPS: TourStep[] = [
    {
        tab: 'Schedule',
        title: 'Your season at a glance',
        body: [
            'The Schedule is a calendar of both brands’ shows. PPVs air on Sunday, Raw on Monday and Smackdown on Friday.',
            'Click this week’s show to open its card and book it: 6 matches and 2 promos on a weekly show, 5 matches on a PPV. Pick a match type, then the superstars, then an optional stipulation, and save each match.',
            'Watch the fatigue bars while you book: a superstar who ends a match above 70% gets injured. When the card is ready, Air week puts it on.',
        ],
    },
    {
        tab: 'Email',
        title: 'Your inbox',
        body: [
            'JR or Tazz, Vince McMahon, the Trainer, the rival GM and your own superstars write to you here.',
            'Read it after every week: injuries, expiring contracts, booking tips, trade offers from the rival and unhappy superstars all land here first.',
        ],
    },
    {
        tab: 'Rosters',
        title: 'Your superstars',
        body: [
            'Every superstar on both brands, with their fatigue, record, streak, contract and morale.',
            'Morale runs from Happy to Upset. Losing streaks, sitting unused, waiting too long for a title match or a long win streak without a new contract all upset a superstar, and an Unsatisfied or Upset one may defect to the rival.',
        ],
    },
    {
        tab: 'Reports',
        title: 'How your shows are doing',
        body: [
            'Fan attendance for every night of the season, both brands side by side, and every match result.',
            'The PPV records tab ranks superstars by PPV main events, openers, wins and losses, with the interbrand PPVs’ history.',
        ],
    },
    {
        tab: 'Title History',
        title: 'Every title’s lineage',
        body: [
            'Who holds each title now, every reign before them, and the records: longest reign, most defenses and the Grand Slam champions.',
        ],
    },
    {
        tab: 'Rivalry',
        title: 'Rivalries and heat',
        body: [
            'Rivalries start when two superstars keep meeting, or with a Start a Rivalry promo. Heat rises when the rivals share a match and drops in a quiet week.',
            'Hot rivalries draw bigger crowds, so book your rivals against each other.',
        ],
    },
    {
        tab: 'Factions',
        title: 'Tag teams and stables',
        body: [
            'Real tag teams and stables are already together. Superstars who keep winning as a team can form a new one, and any faction can be disbanded here.',
        ],
    },
    {
        tab: 'Propose Trade',
        title: 'Trading with the rival',
        body: [
            'Offer up to 5 of your superstars for up to 5 of theirs. The bar shows how likely the rival GM is to say yes before you send it.',
            'Every few weeks the rival sends you an offer of its own by email.',
        ],
    },
    {
        tab: 'Free Agency',
        title: 'Signing free agents',
        body: [
            'Anyone without a brand, Legends included, waits here. A contract is paid in full when you sign it, and the superstar joins next week.',
            'Your roster holds 30, so keep an eye on the budget and on contracts that are about to run out.',
        ],
    },
    {
        tab: 'Financials',
        title: 'Your money',
        body: [
            'Every week’s income and costs: the gate from each show, production, match and promo prices, contracts and medical bills.',
            'Stay out of the red: a long deficit forces you to cut costs.',
        ],
    },
    {
        tab: 'News',
        title: 'The weekly news',
        body: [
            'Once a week airs, its headlines and stories land here: title changes, big wins, rivalries, injuries and more, for Raw, Smackdown and WWE.',
        ],
    },
    {
        tab: 'Docs',
        title: 'The rule book',
        body: [
            'Every rule of the game in one place: match types and their rules, stipulations, promos, fatigue and injuries, news and emails.',
            'You can take this tour again any time from the Schedule.',
        ],
    },
];

/**
 * The guided tour on the Schedule (house rule, 2026-11-21): a modal for each
 * menu bar tab over a dimmed page, with Back, Next and Skip tour.
 */
export function GuidedTour({
    open,
    onClose,
    steps = TOUR_STEPS,
}: {
    open: boolean;
    /** Skipped or finished: either way it's done. */
    onClose: () => void;
    steps?: TourStep[];
}) {
    const brand = usePage<{ game?: GameShell }>().props.game?.player_brand;
    const [index, setIndex] = useState(0);
    const step = steps[index];
    const last = index === steps.length - 1;

    const close = () => {
        setIndex(0);
        onClose();
    };

    if (!step) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent
                data-brand-tint={brand ?? 'raw'}
                data-testid="guided-tour"
                className="gm-panel rounded-none border-white/10 bg-arena-2 font-gm text-ink"
            >
                <p className="gm-label" data-testid="tour-progress">
                    {index + 1} of {steps.length} · {step.tab}
                </p>
                <DialogTitle className="gm-heading text-2xl">
                    {step.title}
                </DialogTitle>
                <DialogDescription asChild className="text-ink-dim">
                    <div className="space-y-3">
                        {step.body.map((p) => (
                            <p key={p}>{p}</p>
                        ))}
                    </div>
                </DialogDescription>

                <ol className="mt-2 flex gap-1" aria-label="Tour steps">
                    {steps.map((s, i) => (
                        <li key={s.tab} className="flex-1">
                            <button
                                type="button"
                                aria-label={`Step ${i + 1}: ${s.tab}`}
                                aria-current={i === index ? 'step' : undefined}
                                onClick={() => setIndex(i)}
                                className={cn(
                                    'block h-1.5 w-full',
                                    i <= index ? 'bg-brand' : 'bg-white/15',
                                )}
                            />
                        </li>
                    ))}
                </ol>

                <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={close}
                    >
                        Skip tour
                    </button>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={index === 0}
                            onClick={() => setIndex(index - 1)}
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            className="gm-btn gm-btn-sm"
                            autoFocus
                            onClick={() =>
                                last ? close() : setIndex(index + 1)
                            }
                        >
                            {last ? 'Finish' : 'Next'}
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
