<?php

namespace App\Services\News;

use App\Enums\NewsEventType;

/**
 * Headline templates from the §19 catalog. `{placeholders}` are filled from
 * the event payload.
 */
class NewsTemplates
{
    /**
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'title_change' => 'NEW CHAMPION! {winner} defeats {loser} for the {title} at {show}!',
        'title_retained' => '{champion} retains the {title} against {challenger}.',
        'retained_by_dq' => '{challenger} wins, but {champion} keeps the {title} on a {method}!',
        'contender_earned_shot' => '{contender} beats {champion} and earns a {title} match at {next_show}!',
        'last_chance_result' => '{contender} {won_or_lost} their last chance at the {title}.',
        'title_vacated' => 'The {title} has been vacated: {reason}.',
        'title_awarded' => '{wrestler} named the new {title} holder.',
        'reign_milestone' => '{champion} marks {weeks} weeks as {title}.',
        'record_broken' => 'History made: {wrestler} sets a new {record} record!',
        'ppv_upcoming' => '{ppv} is {n} weeks away.',
        'ppv_main_event_set' => 'Main event confirmed for {ppv}: {wrestler_a} vs {wrestler_b}!',
        'ppv_results' => '{ppv} results: {top_result} headlines the night.',
        'survivor_series_teams' => 'Team {captain_a} vs Team {captain_b} set for Survivor Series.',
        'mitb_qualified' => '{wrestler} enters the Money in the Bank ladder match.',
        'mitb_winner' => '{wrestler} wins Money in the Bank!',
        'mitb_cash_in' => 'CASH-IN! {wrestler} cashes in on {champion}: {result}.',
        'mitb_expiring' => "Time is running out on {wrestler}'s briefcase.",
        'win_streak' => '{wrestler} is on a {n}-match winning streak.',
        'streak_snapped' => "{loser}'s {n}-match streak is over. {winner} ends it at {show}.",
        'losing_streak' => 'Rough stretch for {wrestler}: {n} straight losses.',
        'interference_finish' => '{interferer} costs {loser} the match against {winner}!',
        'interbrand_series_result' => '{brand} wins the WrestleMania brand battle, {n}–{m}!',
        'upset_win' => 'Shocker! {winner} upsets {loser}.',
        'rivalry_started' => 'Bad blood brewing between {wrestler_a} and {wrestler_b}.',
        'rivalry_heating_up' => 'The {wrestler_a}–{wrestler_b} feud is boiling over.',
        'rivalry_blowoff' => '{winner} settles the score with {loser} at {ppv}.',
        'debut' => '{wrestler} makes their {brand} debut!',
        'legend_signed' => '{legend} is coming to {brand}!',
        'free_agent_signed' => '{brand} signs {wrestler}.',
        'released' => '{brand} has released {wrestler}.',
        'loser_leaves' => '{loser} is gone from {brand} after losing to {winner}!',
        'trade_completed' => 'Blockbuster trade: {wrestlers_out} to {brand_b} for {wrestlers_in}.',
        'redraft_pick' => '{brand} drafts {wrestler} from {source}.',
        'injury' => '{wrestler} suffers a {severity} injury and is expected out {weeks} weeks.',
        'injury_escalated' => "{wrestler}'s injury has worsened. Now out {weeks} weeks.",
        'injury_return' => '{wrestler} is cleared to return!',
        'contract_demand' => '{wrestler} wants a new deal after {n} straight wins.',
        'title_demand' => '{wrestler} demands a shot at the {title}.',
        'morale_unrest' => 'Sources say {wrestler} is frustrated with {brand} management.',
        'defection_attempt' => '{wrestler} is trying to leave {brand}!',
        'defection_completed' => '{wrestler} jumps ship to {rival_brand}!',
        'defection_blocked' => "{wrestler}'s move is blocked. They refuse to compete for 2 weeks.",
        'ratings_war' => '{brand} wins the ratings war this week.',
        'ratings_streak' => '{brand} has won {n} straight weeks.',
        'slander_result' => "{brand}'s smear campaign against {rival_brand} {outcome}!",
        'interference_promo_result' => "{brand} {outcome} {rival_brand}'s show!",
        'season_reset' => 'A new year begins. Ratings reset and the re-draft opens.',
    ];

    public function template(NewsEventType $type): string
    {
        return self::TEMPLATES[$type->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(NewsEventType $type, array $payload): string
    {
        $text = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($payload): string {
            $value = $payload[$m[1]] ?? null;

            return is_scalar($value) ? (string) $value : $m[0];
        }, $this->template($type));

        return (string) preg_replace('/1 weeks/', '1 week', $text);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
