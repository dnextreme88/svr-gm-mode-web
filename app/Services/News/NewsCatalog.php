<?php

namespace App\Services\News;

use App\Enums\NewsEventType;

/**
 * Player-facing description of every news event for `/gm/docs`: what it's
 * about, which card it lands on and how big it plays. The tier and card are
 * decided where each event is recorded; this keeps a readable summary of
 * them, and a test makes sure every event type is listed.
 */
class NewsCatalog
{
    /**
     * @var list<string>
     */
    public const GROUPS = [
        'Matches',
        'Titles',
        'Stipulations',
        'Pay-per-views',
        'Rivalries',
        'Roster and contracts',
        'Promos',
        'Injuries and morale',
        'Brand battle',
        'Factions',
        'Quiet weeks',
    ];

    /**
     * Card: `brand` (that brand's own cards), `wwe` (the neutral card) or
     * `either` (brand cards, or WWE when the news is cross-brand).
     *
     * @var array<string, array{group: string, card: string, tier: string, active?: bool}>
     */
    private const ENTRIES = [
        'match_result' => ['group' => 'Matches', 'card' => 'either', 'tier' => 'Story, ranked PPV → title → main event → gimmick (DQ and count-out wins lower). The biggest match leads a brand with no headline. A DQ or count-out win, or a decisive win by someone who went in with a minor injury, has its own wording'],
        'main_event_hype_result' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Headline (worded by the Main Event Hype promo story)'],
        'interference_finish' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Story'],
        'win_streak' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Headline at 10, 15 and 20 wins'],
        'streak_snapped' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Headline: the upset win, when someone beats a superstar on a 10+ win streak (Upset Win merged into it, 2026-11-21)'],
        'losing_streak' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Story'],

        'title_change' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'title_retained' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'retained_by_dq' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'title_vacated' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'title_awarded' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'reign_milestone' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'record_broken' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'mitb_cash_in' => ['group' => 'Titles', 'card' => 'wwe', 'tier' => 'Headline'],
        'mitb_expiring' => ['group' => 'Titles', 'card' => 'wwe', 'tier' => 'Headline, the week after the Royal Rumble, while a briefcase is still held (it runs out at WrestleMania)'],

        'contender_earned_shot' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],
        'last_chance_result' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],
        'champion_survived_challenge' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Story, when the champion wins a Champion vs. Contender match'],
        'winner_takes_all' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline: the winner celebrates (80%) or the losers vow revenge (20%)'],
        'number_one_contender' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline: fired up, a staredown with the champion, or celebrating with the fans (a third each)'],
        'contendership_voided' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Story, when a #1 contender leaves the brand, is seriously injured or forfeits'],
        'contender_awarded_title' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline, when a vacated title is awarded to its #1 contender(s)'],
        'loser_leaves' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],

        'attendance' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline: the biggest crowd of the week. Takes the WWE headline in a week without a PPV, Slander or Interference result'],
        'ppv_upcoming' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story 2 weeks out, Headline 1 week out (every PPV, brand or shared)'],
        'ppv_main_event_set' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],
        'ppv_results' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline: the PPV\'s main event (who won, the title, the gimmick) or the Royal Rumble winner. Always the WWE headline in a PPV week'],
        'survivor_series_teams' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story'],
        'mitb_qualified' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story (now from a Money in the Bank Qualifying Match)'],
        'survivor_series_qualified' => ['group' => 'Pay-per-views', 'card' => 'brand', 'tier' => 'Story (from a Survivor Series Qualifying Match)'],
        'mitb_winner' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],
        'interbrand_series_result' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],

        'rivalry_started' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Story'],
        'rivalry_heating_up' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Story'],
        'rivalry_milestone' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Headline'],
        'rivalry_concluded' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Headline, the week after the rivalry reaches 100 heat'],
        'rivalry_blowoff' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Headline (old news only: before 2026-11-12, rivals at 70+ heat ended it by meeting at a PPV)'],

        'free_agent_signed' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'legend_signed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'debut' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'released' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'contract_expiring' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'trade_completed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'redraft_pick' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline, always the WWE headline after the WrestleMania re-draft, before week 1 of the next season: every pick, the tags and the season closing'],
        'defection_attempt' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'defection_blocked' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'defection_completed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'season_reset' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'redraft_results' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline (old news only: before 2026-11-21, now Redraft pick)'],

        'promo_result' => ['group' => 'Promos', 'card' => 'brand', 'tier' => 'Story (worded by the promo story, see Promo stories)'],
        'call_out_face' => ['group' => 'Promos', 'card' => 'brand', 'tier' => 'Story (a face Call-out, worded by the promo story)'],
        'call_out_heel' => ['group' => 'Promos', 'card' => 'brand', 'tier' => 'Story (a heel Call-out, worded by the promo story)'],
        'slander_result' => ['group' => 'Promos', 'card' => 'wwe', 'tier' => 'Headline (always takes the WWE card)'],
        'interference_promo_result' => ['group' => 'Promos', 'card' => 'wwe', 'tier' => 'Headline (always takes the WWE card)'],

        'injury' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story, Headline when a champion is seriously hurt'],
        'injury_escalated' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'injury_return' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'contract_demand' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'title_demand' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'morale_unrest' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],

        'ratings_war' => ['group' => 'Brand battle', 'card' => 'brand', 'tier' => 'Story', 'active' => false],
        'ratings_streak' => ['group' => 'Brand battle', 'card' => 'brand', 'tier' => 'Story', 'active' => false],

        'faction_formed' => ['group' => 'Factions', 'card' => 'brand', 'tier' => 'Headline, the week 2 or 3 superstars win together for the 3rd time and become a faction'],
        'faction_member_added' => ['group' => 'Factions', 'card' => 'brand', 'tier' => 'Story (ready for when factions can grow; nothing adds members yet)'],
        'faction_title_won' => ['group' => 'Factions', 'card' => 'brand', 'tier' => 'Headline: replaces the title change when the new tag champions are a faction'],
        'faction_title_lost' => ['group' => 'Factions', 'card' => 'brand', 'tier' => 'Story, when tag champions who are a faction lose the title'],
        'faction_interbrand_win' => ['group' => 'Factions', 'card' => 'wwe', 'tier' => 'Headline, when a faction wins a match on an interbrand card (Royal Rumble, Survivor Series, WrestleMania)'],
        'faction_disbanded' => ['group' => 'Factions', 'card' => 'brand', 'tier' => 'Story, the week the GM disbands a faction'],

        'tickets_on_sale' => ['group' => 'Quiet weeks', 'card' => 'wwe', 'tier' => 'Headline, only when the WWE card has nothing else'],
        'brand_spotlight' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Headline, only when a brand has nothing else'],
        'superstar_recap' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Story, filling an empty story slot'],
        'injury_report' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Story, filling the second empty story slot when the locker room has nothing to report'],
        'locker_room_note' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Story, filling the second empty story slot: unhappy superstars, else contracts about to run out'],
        'welcome' => ['group' => 'Quiet weeks', 'card' => 'wwe', 'tier' => 'Headline from Vince McMahon, always and only in season 1, week 1'],
    ];

    public function __construct(private NewsTemplates $templates) {}

    /**
     * @return list<array{type: string, label: string, group: string, card: string, tier: string, active: bool, priority: int, priority_note: string|null, headline: string, summary: string, summary_variants: list<array{variant: string, summary: string}>}>
     */
    public function entries(): array
    {
        $entries = [];
        $priorities = $this->priorities();

        foreach (self::ENTRIES as $value => $entry) {
            $type = NewsEventType::from($value);

            $entries[] = [
                'type' => $value,
                'label' => $type->label(),
                'group' => $entry['group'],
                'card' => $entry['card'],
                'tier' => $entry['tier'],
                'active' => $entry['active'] ?? true,
                // House rule (2026-11-21): how much weight it carries when
                // headlines and stories are drawn (see `priorities()`).
                'priority' => $priorities[$value][0] ?? 0,
                'priority_note' => $priorities[$value][1] ?? null,
                'headline' => $this->readable($this->templates->template($type)),
                'summary' => $this->readable($this->templates->summaryTemplate($type)),
                // Every other way the summary can read (house rule, 2026-11-11).
                'summary_variants' => array_map(
                    fn (string $variant, string $summary): array => ['variant' => ucfirst(str_replace('_', ' ', $variant)), 'summary' => $this->readable($summary)],
                    array_keys($variants = $this->templates->summaryVariants($type)),
                    array_values($variants),
                ),
            ];
        }

        return $entries;
    }

    /**
     * Each event's priority as recorded: its weight when headlines and
     * stories are drawn, and the order bigger news wins in. Read from the
     * same config the game uses, with a note where it varies. A test checks
     * the plain numbers against the code that records them.
     *
     * @return array<string, array{0: int, 1?: string}>
     */
    public function priorities(): array
    {
        $news = (array) config('gm.news');
        $stip = (array) $news['stipulation_priority'];
        $faction = (array) $news['faction_priority'];
        $morale = (array) $news['morale_priority'];
        $result = (array) $news['match_result_priority'];
        $ppvMain = (array) $news['ppv_main_event_priority'];
        $promo = (int) $news['promo_result_priority'];
        $other = (int) $result['other'];
        $main = (int) $result['main_event'];
        $biggest = $main + (int) $result['ppv_bonus'] + (int) $result['title_bonus'] + (int) $result['gimmick_bonus'];
        $filler = 'Filler: always the lowest';

        return [
            'match_result' => [$other, sprintf('%d for an undercard match, %d for the main event; +%d at a PPV, +%d with a title on the line, +%d for a gimmick match, −%d for a DQ or count-out win (up to %d)', $other, $main, $result['ppv_bonus'], $result['title_bonus'], $result['gimmick_bonus'], $result['not_decisive_penalty'], $biggest)],
            'main_event_hype_result' => [(int) $result['hyped_main_event']],
            'interference_finish' => [30],
            'win_streak' => [40, '10 + 3 per win in the streak: 40 at 10 wins, 70 at 20'],
            'streak_snapped' => [60],
            'losing_streak' => [(int) $morale['losing_streak']],

            'title_change' => [100],
            'title_retained' => [35],
            'retained_by_dq' => [35],
            'title_vacated' => [80],
            'title_awarded' => [40],
            'reign_milestone' => [30],
            'record_broken' => [65],
            'mitb_cash_in' => [90],
            'mitb_expiring' => [(int) $news['mitb_expiring_priority']],

            'contender_earned_shot' => [(int) $stip['contender_earned_shot']],
            'last_chance_result' => [70],
            'champion_survived_challenge' => [(int) $stip['champion_survived_challenge']],
            'winner_takes_all' => [(int) $stip['winner_takes_all']],
            'number_one_contender' => [(int) $stip['number_one_contender']],
            'contendership_voided' => [(int) $stip['contendership_voided']],
            'contender_awarded_title' => [(int) $stip['contender_awarded_title']],
            'loser_leaves' => [80],

            'attendance' => [(int) $news['attendance_priority']],
            'ppv_upcoming' => [55, '55 as the headline 1 week out, 15 as a story 2 weeks out'],
            'ppv_main_event_set' => [80],
            'ppv_results' => [(int) $ppvMain['interbrand'], sprintf("%d for an interbrand PPV's main event, %d for a brand PPV's, 90 for the Royal Rumble winner", $ppvMain['interbrand'], $ppvMain['brand'])],
            'survivor_series_teams' => [40],
            'mitb_qualified' => [30, '30 from a Qualifying Match, 10 when a spot is filled on the card'],
            'survivor_series_qualified' => [30],
            'mitb_winner' => [75],
            'interbrand_series_result' => [95],

            'rivalry_started' => [25],
            'rivalry_heating_up' => [30],
            'rivalry_milestone' => [60],
            'rivalry_concluded' => [70],
            'rivalry_blowoff' => [70],

            'free_agent_signed' => [(int) $news['free_agent_signed_priority']],
            'legend_signed' => [60],
            'debut' => [30],
            'released' => [20],
            'contract_expiring' => [(int) $news['contract_expiring_priority']],
            'trade_completed' => [85],
            'redraft_pick' => [100],
            'defection_attempt' => [40],
            'defection_blocked' => [45],
            'defection_completed' => [85],
            'season_reset' => [100],
            'redraft_results' => [100],

            'promo_result' => [$promo],
            'call_out_face' => [$promo + 5],
            'call_out_heel' => [$promo + 5],
            'slander_result' => [75],
            'interference_promo_result' => [75],

            'injury' => [50, '50 for a serious injury, 20 for a minor one'],
            'injury_escalated' => [50],
            'injury_return' => [25],
            'contract_demand' => [(int) $morale['contract_demand']],
            'title_demand' => [(int) $morale['title_demand']],
            'morale_unrest' => [(int) $morale['morale_unrest']],

            'ratings_war' => [0],
            'ratings_streak' => [0],

            'faction_formed' => [(int) $faction['formed']],
            'faction_member_added' => [(int) $faction['member_added']],
            'faction_title_won' => [(int) $faction['title_won']],
            'faction_title_lost' => [(int) $faction['title_lost']],
            'faction_interbrand_win' => [(int) $faction['interbrand_win']],
            'faction_disbanded' => [(int) $faction['disbanded']],

            'tickets_on_sale' => [1, $filler],
            'brand_spotlight' => [1, $filler],
            'superstar_recap' => [1, $filler],
            'injury_report' => [1, $filler],
            'locker_room_note' => [1, $filler],
            'welcome' => [100],
        ];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::ENTRIES);
    }

    /**
     * Grammar helpers aren't worth showing as placeholders.
     */
    private function readable(string $template): string
    {
        return str_replace(['{defeats}', '{by}', '{ppv_how}'], ['defeats', '', ' {how}'], $template);
    }
}
