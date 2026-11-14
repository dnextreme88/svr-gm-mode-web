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
});
