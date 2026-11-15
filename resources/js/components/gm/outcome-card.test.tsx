import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { OutcomeCard } from '@/components/gm/outcome-card';
import type { MatchOutcome } from '@/types/gm';

const dqRetain = {
    position: 1,
    match_type: 'Submission — Triple Threat',
    title: "Women's Championship",
    title_banner: 'and_still',
    champions: ['Lita'],
    winners: ['Torrie Wilson'],
    loser_sides: [['Lita'], ['Victoria']],
    method_label: 'Disqualification',
    summary: 'Victoria grabs the steel steps and gets disqualified.',
} as unknown as MatchOutcome;

describe('OutcomeCard', () => {
    it('names the champion who kept the title after a DQ win (UI audit)', () => {
        render(
            <OutcomeCard
                outcome={dqRetain}
                showName="Raw"
                isMainEvent={false}
            />,
        );

        expect(screen.getByTestId('kept-title')).toHaveTextContent(
            "Lita keeps the Women's Championship",
        );
    });

    it('says nothing more when the champion won', () => {
        render(
            <OutcomeCard
                outcome={{ ...dqRetain, winners: ['Lita'] }}
                showName="Raw"
                isMainEvent={false}
            />,
        );

        expect(screen.queryByTestId('kept-title')).toBeNull();
    });

    it('says where the match was in one plain line, with no eyebrow labels (2026-11-15)', () => {
        render(
            <OutcomeCard
                outcome={{ ...dqRetain, title: null, title_banner: null }}
                showName="Survivor Series"
                isMainEvent={false}
            />,
        );

        expect(screen.getByTestId('outcome-heading')).toHaveTextContent(
            'Survivor Series · Match 1 · Submission — Triple Threat',
        );
        expect(screen.queryByText('Winner')).toBeNull();
    });
});
