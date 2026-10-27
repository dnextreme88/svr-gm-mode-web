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
        'win_streak' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Story at 3 and 5 wins, Headline at 10'],
        'streak_snapped' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Headline'],
        'losing_streak' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Ticker'],
        'upset_win' => ['group' => 'Matches', 'card' => 'brand', 'tier' => 'Story', 'active' => false],

        'title_change' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'title_retained' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'retained_by_dq' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'title_vacated' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'title_awarded' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'reign_milestone' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Story'],
        'record_broken' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Headline'],
        'mitb_cash_in' => ['group' => 'Titles', 'card' => 'wwe', 'tier' => 'Headline'],
        'mitb_expiring' => ['group' => 'Titles', 'card' => 'brand', 'tier' => 'Ticker'],

        'contender_earned_shot' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],
        'last_chance_result' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],
        'loser_leaves' => ['group' => 'Stipulations', 'card' => 'brand', 'tier' => 'Headline'],

        'ppv_upcoming' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story 2 weeks out, Headline 1 week out (every PPV, brand or shared)'],
        'ppv_main_event_set' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],
        'ppv_results' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline: the PPV\'s main event (who won, the title, the gimmick) or the Royal Rumble winner. Always the WWE headline in a PPV week'],
        'survivor_series_teams' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story'],
        'mitb_qualified' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Story'],
        'mitb_winner' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],
        'interbrand_series_result' => ['group' => 'Pay-per-views', 'card' => 'wwe', 'tier' => 'Headline'],

        'rivalry_started' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Story'],
        'rivalry_heating_up' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Story'],
        'rivalry_milestone' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Headline'],
        'rivalry_blowoff' => ['group' => 'Rivalries', 'card' => 'brand', 'tier' => 'Headline'],

        'free_agent_signed' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'legend_signed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'debut' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'released' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'contract_expiring' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'trade_completed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'redraft_pick' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'defection_attempt' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'defection_blocked' => ['group' => 'Roster and contracts', 'card' => 'brand', 'tier' => 'Story'],
        'defection_completed' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],
        'season_reset' => ['group' => 'Roster and contracts', 'card' => 'wwe', 'tier' => 'Headline'],

        'promo_result' => ['group' => 'Promos', 'card' => 'brand', 'tier' => 'Story (worded by the promo story, see Promo stories)'],
        'slander_result' => ['group' => 'Promos', 'card' => 'wwe', 'tier' => 'Headline (always takes the WWE card)'],
        'interference_promo_result' => ['group' => 'Promos', 'card' => 'wwe', 'tier' => 'Headline (always takes the WWE card)'],

        'injury' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story, Headline when a champion is seriously hurt'],
        'injury_escalated' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'injury_return' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Story'],
        'contract_demand' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Ticker'],
        'title_demand' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Ticker'],
        'morale_unrest' => ['group' => 'Injuries and morale', 'card' => 'brand', 'tier' => 'Ticker'],

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
        'injury_report' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Story, filling the second empty story slot'],
        'locker_room_note' => ['group' => 'Quiet weeks', 'card' => 'brand', 'tier' => 'Ticker, when the ticker is empty'],
    ];

    public function __construct(private NewsTemplates $templates) {}

    /**
     * @return list<array{type: string, label: string, group: string, card: string, tier: string, active: bool, headline: string, summary: string}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach (self::ENTRIES as $value => $entry) {
            $type = NewsEventType::from($value);

            $entries[] = [
                'type' => $value,
                'label' => $type->label(),
                'group' => $entry['group'],
                'card' => $entry['card'],
                'tier' => $entry['tier'],
                'active' => $entry['active'] ?? true,
                'headline' => $this->readable($this->templates->template($type)),
                'summary' => $this->readable($this->templates->summaryTemplate($type)),
            ];
        }

        return $entries;
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
        return str_replace(['{defeats}', '{by}'], ['defeats', ''], $template);
    }
}
