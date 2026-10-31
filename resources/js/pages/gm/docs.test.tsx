import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Docs from '@/pages/gm/docs';

const variant = (overrides: Record<string, unknown>) => ({
    id: 1,
    variant: 'No Manager',
    sides: [1, 1],
    competitors: 2,
    managers: 0,
    fatigue_bars: 1,
    cost: 0,
    dq: true,
    countout: true,
    rope_breaks: true,
    ring_out: true,
    captain_fall: false,
    timed: false,
    titles_allowed: true,
    divas_only: false,
    divas_allowed: true,
    elimination: false,
    ppv_only: false,
    ppv_exclusive: null,
    brand: null,
    gm_bookable: true,
    special_referee: false,
    win_condition: null,
    ...overrides,
});

const props = {
    match_types: [
        {
            family: 'single',
            label: 'Normal Matches',
            variants: [
                variant({}),
                variant({
                    id: 2,
                    variant: 'Normal Triple Threat',
                    sides: [1, 1, 1],
                    dq: false,
                    countout: false,
                }),
            ],
        },
        {
            family: 'six_man_tag',
            label: '6-Man Tag',
            variants: [
                variant({
                    id: 3,
                    variant: 'Captain Fall Tornado 6-Man Tag Elimination',
                    sides: [3, 3],
                    captain_fall: true,
                    titles_allowed: false,
                    elimination: true,
                }),
            ],
        },
        {
            family: 'table',
            label: 'Table',
            variants: [
                variant({
                    id: 4,
                    variant: 'Single',
                    dq: false,
                    countout: false,
                    rope_breaks: false,
                    win_condition: 'Put through a table',
                }),
            ],
        },
    ],
    injuries: { minor: [], serious: [] },
    fatigue: {
        bar_size: 10,
        max: 100,
        safe_max: 70,
        serious_at: 100,
        rest_recovery_bars: 2,
        serious_share: 0.2,
        escalation_chance: 0.2,
    },
    specialists: [],
    quiet_stories: [
        {
            key: 'tickets',
            name: 'WWE headline: tickets on sale',
            situations: [
                {
                    situation: 'tickets.ppv',
                    label: 'A PPV airs next (named)',
                    extra: false,
                    variants: [
                        {
                            headline: 'Tickets on sale now for {event}!',
                            summary: '{event} is next on {date}.',
                        },
                    ],
                },
            ],
        },
        {
            key: 'spotlight',
            name: 'Brand headline: a champion on tour, or WWE HQ',
            situations: [
                {
                    situation: 'budget.good',
                    label: 'Budget line, above the $500,000 low-cash mark',
                    extra: false,
                    variants: [
                        {
                            headline: '',
                            summary: 'The {brand} books look healthy.',
                        },
                    ],
                },
            ],
        },
    ],
    promo_stories: [
        {
            key: 'interview_space',
            name: 'Interview Space',
            situations: [
                {
                    situation: 'success_won',
                    label: 'Success, then won their match',
                    extra: false,
                    variants: [
                        {
                            headline: '{wrestler} talks the talk',
                            summary: '{wrestler} beat {opponent} {how}.',
                        },
                    ],
                },
            ],
        },
    ],
    rosters: {
        cap: 30,
        starting_budget: 15000000,
        weekly_show_income: 250000,
        contract_weeks: 52,
        legends_draftable: false,
        ai_roll_max: 100,
        ai_focus_from: 60,
        wwe: {
            raw: [
                {
                    name: 'John Cena',
                    roster_type: 'superstar',
                    weight_class: 'heavyweight',
                },
                {
                    name: 'Trish Stratus',
                    roster_type: 'diva',
                    weight_class: 'featherweight',
                },
            ],
            smackdown: [
                {
                    name: 'Rey Mysterio',
                    roster_type: 'superstar',
                    weight_class: 'cruiserweight',
                },
            ],
        },
    },
    morale: {
        thresholds: {
            losing_streak: [3, 5, 7],
            contract_extension: [10, 15, 18],
            title_match: [4, 8, 12],
            inactivity: [2, 4, 8],
        },
        champions_exempt: true,
        defection_unsatisfied_percent: 10,
        defection_upset_percent: 25,
        blocked_sit_out_weeks: 2,
    },
    rival: {
        booking: {
            intergender_chance: 0.05,
            min_diva_matches: 1,
            careful_chance: 0.85,
            careful_max_fatigue: 70,
            aggressive_max_fatigue: 110,
            aggressive_min_bars: 3,
        },
        titles: {
            main_event_chance: { weekly: 0.6, ppv: 0.9 },
            other_slot_chance: 0.35,
            champion_vs_contender_chance: 0.1,
        },
        promos: {
            interference_every_weeks: 4,
            slander_gap_weeks: { 3: 0.1, 5: 0.3, 6: 0.6 },
            title_hype_weights: { world: 60, second: 30, other: 10 },
        },
        rivalries: {
            max_per_brand: 7,
            refill_wait_weeks: 2,
            reset_minimum: 4,
            rivalry_match_chance: 0.5,
        },
        roster: {
            renew_at_weeks_left: 4,
            renew_length: 52,
            target_size: 28,
            cash_reserve: 500000,
            save_ahead_weeks: 30,
        },
        trades: {
            base_value: 1,
            legend_value: 2,
            focus_value: 1,
            specialty_value: 1,
            minor_injury_value: -1,
            win_streak_step: 5,
            win_streak_value: 1,
            loss_streak_from: 7,
            loss_streak_value: -1,
            tag_partner_value: 1,
            full_tag_team_value: 1,
            full_stable_value: 2,
            world_reign_bands: { 1: 1, 5: 2, 8: 3 },
            repeat_title_reigns: 2,
            repeat_title_value: 1,
            fair_accept: 0.75,
            fair_ahead_accept: { 1: 0.9, 2: 1 },
            unfair_accept: 0.25,
        },
        mitb: { cash_in_champion_fatigue: 60, cash_in_weeks_left: 4 },
        max_focus_picks: 12,
        teammate_chance: 25,
        low_cash: 500000,
    },
    stipulations: [],
    promos: {
        slots: 2,
        merchandise_tiers: [
            { amount: 100000, chance: 0.8 },
            { amount: 1000000, chance: 0.25 },
        ],
        types: [
            {
                key: 'advertising',
                name: 'Advertising',
                amount: 150000,
                variable_amount: false,
                success_rate: 1,
                fatigue_bars: 0,
                recovery_bars: 0,
                targets_rival: false,
                requirement: 'Nothing.',
                effect: 'Guaranteed flat payout next week.',
            },
            {
                key: 'interference_promotion',
                name: 'Interference Promotion',
                amount: -50000,
                variable_amount: false,
                success_rate: 0.5,
                fatigue_bars: 2,
                recovery_bars: 0,
                targets_rival: true,
                requirement:
                    'A non-Diva superstar who isn’t seriously injured.',
                effect: 'Sabotages the rival show.',
            },
            {
                key: 'inactive_superstar',
                name: 'Inactive Superstar',
                amount: -2000,
                variable_amount: false,
                success_rate: 0.9,
                fatigue_bars: 0,
                recovery_bars: 2,
                targets_rival: false,
                requirement:
                    'A wrestler who isn’t booked anywhere on this card.',
                effect: 'Rests a wrestler who isn’t being used.',
            },
        ],
    },
    news: {
        stories_per_brand: 2,
        groups: ['Matches', 'Brand battle'],
        events: [
            {
                type: 'match_result',
                label: 'Match Result',
                group: 'Matches',
                card: 'either',
                tier: 'Story, ranked PPV → title → main event → gimmick (DQ and count-out wins lower). The biggest match leads a brand with no headline',
                active: true,
                headline: '{winner} defeats {loser} at {show}.',
                summary: '{match}: {winner} got the win over {loser}.',
            },
            {
                type: 'ratings_war',
                label: 'Ratings War',
                group: 'Brand battle',
                card: 'brand',
                tier: 'Story',
                active: false,
                headline: '{brand} wins the ratings war this week.',
                summary: '{brand} drew the bigger audience this week.',
            },
        ],
    },
    results: { buried_alive_weeks: 4, rumble_entrants_per_brand: 15 },
    calendar: {
        weekly_show_days: { raw: 'Monday', smackdown: 'Friday' },
        weeks_per_season: 52,
        season_start: 'Sun Apr 3, 2005',
        season_end: 'Sun Mar 26, 2006',
        ppvs: [
            {
                key: 'backlash',
                name: 'Backlash',
                season_week: 4,
                date: 'Sun Apr 24, 2005',
                brand_scope: 'raw',
            },
            {
                key: 'summerslam',
                name: 'SummerSlam',
                season_week: 21,
                date: 'Sun Aug 21, 2005',
                brand_scope: 'shared',
            },
        ],
    },
    emails: [
        {
            email: 'PPV reminder',
            from: 'Vince McMahon',
            when: '2 weeks before each shared PPV.',
        },
        {
            email: 'Injury report',
            from: 'Trainer',
            when: 'Every injury or escalation.',
        },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props, url: '/gm/docs' }),
}));

const terms = (panel: HTMLElement) =>
    within(panel)
        .getAllByRole('term')
        .map((t) => t.textContent);

const rowBadges = (name: string) => {
    const row = screen.getByRole('row', { name: new RegExp(`^${name}`) });
    const cells = within(row).getAllByRole('cell');

    return {
        rules: [...cells[4].querySelectorAll('span')].map((s) => s.textContent),
        wins: [...cells[5].querySelectorAll('span')].map((s) => s.textContent),
    };
};

describe('Docs page', () => {
    it('lists match rules and win conditions alphabetically, side by side', () => {
        render(<Docs />);

        const rules = screen
            .getByRole('heading', { name: 'Match rules' })
            .closest('section') as HTMLElement;
        const wins = screen
            .getByRole('heading', { name: 'Win conditions' })
            .closest('section') as HTMLElement;

        expect(terms(rules)).toEqual([
            'DQ',
            'Elimination',
            'Ring out',
            'Rope breaks',
            'Special referee',
            'Timed',
            'Titles',
        ]);
        expect(terms(wins)).toContain('Count-out');
        expect(terms(wins)).toEqual(
            [...terms(wins)].sort((a, b) => (a ?? '').localeCompare(b ?? '')),
        );
        expect(rules.parentElement).toBe(wins.parentElement);
    });

    it('shows only the rules that apply, sorted, with count-out as a win condition', () => {
        render(<Docs />);

        expect(rowBadges('No Manager')).toEqual({
            rules: ['DQ', 'Ring out', 'Rope breaks', 'Titles'],
            wins: ['Count-out', 'Pinfall', 'Submission'],
        });
        expect(rowBadges('Normal Triple Threat')).toEqual({
            rules: ['Ring out', 'Rope breaks', 'Titles'],
            wins: ['Pinfall', 'Submission'],
        });
        expect(rowBadges('Captain Fall').wins).toEqual(['Captain fall']);
        expect(rowBadges('Single').wins).toEqual(['Table']);
    });

    it('marks the active tab and slides the panel in from the side moved towards', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        const tab = (name: string) => screen.getByRole('tab', { name });

        expect(tab('Match types')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByTestId('tab-indicator')).toBeInTheDocument();

        await user.click(tab('Stipulations'));
        expect(tab('Stipulations')).toHaveAttribute('data-active', 'true');
        expect(tab('Match types')).not.toHaveAttribute('data-active');
        expect(screen.getByRole('tabpanel')).toHaveClass(
            'animate-gm-slide-from-right',
        );

        await user.click(tab('Fatigue & injuries'));
        expect(screen.getByRole('tabpanel')).toHaveClass(
            'animate-gm-slide-from-left',
        );
    });

    it('documents every promo with its cost, success probability, fatigue and needs', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Promos' }));

        expect(
            screen.getByRole('heading', { name: 'When promos air' }),
        ).toBeInTheDocument();
        const interference = screen.getByTestId('promo-interference_promotion');
        expect(interference).toHaveTextContent('Interference Promotion');
        expect(interference).toHaveTextContent('50%');
        expect(interference).toHaveTextContent('A non-Diva superstar');
        expect(
            interference.querySelectorAll('[data-state="filled"]'),
        ).toHaveLength(2);
        expect(screen.getByTestId('promo-advertising')).toHaveTextContent(
            '+$150,000',
        );
    });

    it('lists every news event by group with its card, tier and templates, then the emails', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'News & email' }));

        expect(
            screen.getByRole('heading', { name: 'How the news works' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /a PPV\s+match, then a title match, then the main event/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /gets\s+its biggest match as the headline instead/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('columnheader', { name: 'Brand battle' }),
        ).toBeInTheDocument();

        const result = screen.getByTestId('news-event-match_result');
        expect(result).toHaveTextContent('Raw / Smackdown or WWE');
        expect(result).toHaveTextContent(
            'ranked PPV → title → main event → gimmick',
        );
        expect(result).toHaveTextContent('winner defeats loser at show.');
        expect(
            within(result)
                .getAllByTestId('template-placeholder')
                .map((p) => p.textContent),
        ).toEqual(['winner', 'loser', 'show', 'match', 'winner', 'loser']);

        const ratings = screen.getByTestId('news-event-ratings_war');
        expect(ratings).toHaveTextContent('Not in play yet');
        expect(ratings).toHaveClass('opacity-60');

        const emails = screen.getAllByTestId('email-row');
        expect(emails).toHaveLength(2);
        expect(emails[0]).toHaveTextContent('PPV reminder');
        expect(emails[0]).toHaveTextContent('Vince McMahon');
    });

    it('explains Sunday-start weeks, booking order, simulation and lists the PPVs', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(
            screen.getByRole('tab', { name: 'Booking and Scheduling' }),
        );

        expect(
            screen.getByText(/Raw on Monday and Smackdown on Friday/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Booking a week' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Simulate to a date' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/The rival’s shows can’t be/),
        ).toBeInTheDocument();
        expect(screen.getByTestId('ppv-backlash')).toHaveTextContent(
            'Sun Apr 24, 2005',
        );
        expect(screen.getByTestId('ppv-summerslam')).toHaveTextContent(
            'Raw & Smackdown',
        );
    });

    it('explains live results, the results screen and Title History under Booking and Scheduling', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        expect(
            screen.queryByRole('tab', { name: 'Results & titles' }),
        ).toBeNull();
        await user.click(
            screen.getByRole('tab', { name: 'Booking and Scheduling' }),
        );

        for (const heading of [
            'Booking a week',
            'Live results',
            'The results screen',
            'Title History',
        ]) {
            expect(
                screen.getByRole('heading', { name: heading }),
            ).toBeInTheDocument();
        }
        expect(
            screen.queryByRole('heading', { name: 'Book to Win' }),
        ).toBeNull();
        expect(screen.getByText('(C)').className).toContain('text-gold');
        expect(screen.getByText('(Cpt)').className).toContain('text-gold');
        expect(screen.getByText(/marked Pending Result/)).toBeInTheDocument();
    });

    it('explains the Royal Rumble, Buried Alive and the Gauntlet, with specialties before the match tables', () => {
        render(<Docs />);

        expect(screen.queryByRole('tab', { name: 'Specialties' })).toBeNull();
        expect(
            screen.queryByRole('heading', { name: 'How finishes read' }),
        ).toBeNull();
        expect(screen.getByTestId('royal-rumble-rules')).toHaveTextContent(
            '15 entrants from Raw and 15 from Smackdown',
        );
        expect(screen.getByTestId('royal-rumble-rules')).toHaveTextContent(
            'world champion, for the world title',
        );
        expect(screen.getByTestId('buried-alive-rules')).toHaveTextContent(
            'can’t be booked as anything',
        );
        expect(screen.getByTestId('buried-alive-rules')).toHaveTextContent(
            'Their contract pauses',
        );
        const headings = screen
            .getAllByRole('heading')
            .map((h) => h.textContent ?? '');
        expect(
            headings.findIndex((h) => h.startsWith('Match specialties')),
        ).toBeLessThan(headings.findIndex((h) => h.startsWith('Normal')));

        expect(screen.getByTestId('gauntlet-rules')).toHaveTextContent(
            'Six entrants in one match slot, fought as five singles bouts',
        );
        expect(screen.getByTestId('gauntlet-rules')).toHaveTextContent(
            'No titles and no stipulations.',
        );
        expect(screen.getByTestId('buried-weeks')).toHaveTextContent('4');
        expect(screen.getByTestId('gauntlet-rules')).toHaveTextContent(
            '1st Participant to 6th Participant',
        );
    });

    it('explains managers: required, their record, and "w/" on results', () => {
        render(<Docs />);

        const rules = screen.getByTestId('manager-rules');
        expect(rules).toHaveTextContent('Every manager slot must be filled');
        expect(rules).toHaveTextContent(
            'A manager wins or loses with their side',
        );
        expect(rules).toHaveTextContent('Snitsky w/ Eugene');
    });

    it('states the weekly show income from config', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Rosters & draft' }));

        expect(screen.getByTestId('weekly-show-income')).toHaveTextContent(
            '$250,000',
        );
    });

    it('explains the rival drafting teammates, from config', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Rosters & draft' }));

        expect(screen.getByTestId('draft-teammates')).toHaveTextContent(
            '25% chance',
        );
    });

    it('explains the three ways to cash in Money in the Bank', () => {
        render(<Docs />);

        const rules = screen.getByTestId('mitb-rules');
        expect(rules).toHaveTextContent('Before the show');
        expect(rules).toHaveTextContent('During a world title match');
        expect(rules).toHaveTextContent('After a world title match');
        expect(rules).toHaveTextContent(
            'a singles match becomes a Triple Threat',
        );
    });

    it('explains morale, its thresholds and defection, from config', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Morale' }));

        const rows = within(screen.getByTestId('morale-thresholds'))
            .getAllByRole('row')
            .slice(1)
            .map((r) => r.textContent ?? '');
        expect(rows[0]).toContain('Losing streak');
        expect(rows[0]).toContain('357');
        expect(rows[3]).toContain('Unused');
        expect(screen.getByTestId('defection-rules')).toHaveTextContent(
            '10% chance and an Upset one a 25% chance',
        );
        expect(screen.getByTestId('defection-rules')).toHaveTextContent(
            'sit out 2 weeks',
        );
    });

    it('lists the Merchandise Sponsorship deals with their odds', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Promos' }));

        const tiers = screen.getByTestId('merchandise-tiers');
        expect(tiers).toHaveTextContent('80% success');
        expect(tiers).toHaveTextContent('25% success');
    });

    it('draws Inactive Superstar’s recovery as green bars', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Promos' }));

        const bars = within(
            screen.getByTestId('promo-inactive_superstar'),
        ).getByRole('img', { name: 'Recovers 2 fatigue bars' });
        const filled = bars.querySelectorAll('[data-state="filled"]');
        expect(filled).toHaveLength(2);
        expect(filled[0].className).toContain('bg-zone-safe');
        expect(
            within(
                screen.getByTestId('promo-interference_promotion'),
            ).getByRole('img', { name: 'Adds 2 fatigue bars' }),
        ).toBeInTheDocument();
    });

    it('explains both ways to build the rosters and lists the WWE rosters', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Rosters & draft' }));

        expect(screen.getByText('Manual draft pick')).toBeInTheDocument();
        expect(screen.getByText('Use WWE roster')).toBeInTheDocument();
        expect(
            screen.getByText(/Legends can’t be drafted/),
        ).toBeInTheDocument();
        expect(screen.getByText('WWE roster: Raw (2)')).toBeInTheDocument();
        expect(
            screen.getByText('WWE roster: Smackdown (1)'),
        ).toBeInTheDocument();
        expect(screen.getByText('Trish Stratus')).toHaveAttribute(
            'data-tone',
            'diva',
        );
        expect(screen.getByText('Rey Mysterio')).toHaveAttribute(
            'data-tone',
            'cruiser',
        );
    });

    it('explains how the rival GM books, promotes, signs and trades', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Rival GM' }));

        expect(screen.getByRole('tab', { name: 'Rival GM' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByText(/nobody ends it above 70%/),
        ).toBeInTheDocument();
        expect(screen.getByText(/in one of 7 shapes only/)).toBeInTheDocument();
        expect(
            screen.getByText(/3 weeks 10%, 5 weeks 30%, 6 weeks 60%/),
        ).toBeInTheDocument();
        expect(screen.getByText(/at most 7 on its/)).toBeInTheDocument();

        // Its draft rules stay with the draft.
        await user.click(screen.getByRole('tab', { name: 'Rosters & draft' }));

        expect(
            screen.getByText(/stops chasing its division after 12/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/nobody ends it above 70%/)).toBeNull();
    });

    it('lists every promo’s story situations and their variants', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'News & email' }));

        const promo = screen.getByTestId('promo-stories-interview_space');
        expect(promo).toHaveTextContent('Interview Space');
        expect(promo).toHaveTextContent('Success, then won their match (1)');
        expect(promo).toHaveTextContent('talks the talk');
    });

    it('explains quiet weeks and lists every quiet-week story', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'News & email' }));

        expect(
            screen.getByText(/The News page is never empty/),
        ).toBeInTheDocument();
        const tickets = screen.getByTestId('quiet-stories-tickets');
        expect(tickets).toHaveTextContent('WWE headline: tickets on sale');
        expect(tickets).toHaveTextContent('A PPV airs next (named) (1)');
        expect(tickets).toHaveTextContent('Tickets on sale now for');
        // A budget line has no headline of its own, only the sentence.
        expect(screen.getByTestId('quiet-stories-spotlight')).toHaveTextContent(
            'books look healthy',
        );
    });
});
