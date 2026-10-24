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
        'match_result' => '{winner} {defeats} {loser}{by} at {show}.',
        'main_event_hype_result' => "The hype was real: {winner} {defeats} {loser}{by} in {brand}'s main event!",
        'promo_result' => "{brand}'s {promo} promo {outcome}.",
        'contract_expiring' => "{wrestler}'s {brand} contract runs out in {n} weeks.",
        'tickets_on_sale' => 'Tickets on sale now for {event}!',
        'brand_spotlight' => '{champion} takes the {title} on the road.',
        'superstar_recap' => "Looking back at {wrestler}'s last match.",
        'injury_report' => '{brand} injury report: {count} in 6 months.',
        'locker_room_note' => 'News from the {brand} locker room.',
        'rivalry_milestone' => "{wrestler_a} vs {wrestler_b}: {weeks} weeks in, the fans can't get enough.",
    ];

    /**
     * The narrative line under each card: why it happened and what comes
     * next. A `type.variant` key overrides `type` when the payload's
     * `outcome` (or `won_or_lost`) matches the variant.
     *
     * @var array<string, string>
     */
    private const SUMMARIES = [
        'title_change' => '{winner} beat {loser} at {show} and walks out with the {title}. The old reign is over.',
        'title_retained' => '{challenger} came for the {title}, but {champion} found a way to win and keeps the gold.',
        'retained_by_dq' => '{challenger} won the match, but titles only change hands on a decisive win. {champion} leaves with the {title}.',
        'contender_earned_shot' => '{contender} beat champion {champion} in a Champion vs Contender match. The win earns a {title} match {next_show}.',
        'last_chance_result' => '{contender} had already lost to the champion twice in this reign. This was their final shot at the {title}.',
        'last_chance_result.lost' => '{contender} had already lost to the champion twice in this reign and came up short again. They are locked out of {title} matches against this champion.',
        'last_chance_result.won' => '{contender} had lost to the champion twice in this reign, but the third time paid off with the {title}.',
        'title_vacated' => 'Nobody holds the {title} right now: {reason}. A new champion will have to be crowned.',
        'title_awarded' => '{wrestler} has been named the {title} holder and will defend it on upcoming shows.',
        'reign_milestone' => '{champion} has held the {title} for {weeks} weeks and nobody has managed to take it.',
        'record_broken' => '{wrestler} now holds the {record} record in the title history books.',
        'ppv_upcoming' => '{ppv} is {n} weeks away. Expect the card to take shape over the coming shows.',
        'ppv_main_event_set' => '{ppv} has its main event: {wrestler_a} vs {wrestler_b}, with the world title on the line.',
        'ppv_results' => '{top_result} is the moment everyone will be talking about after {ppv}.',
        'survivor_series_teams' => 'Raw and Smackdown settle it in the Traditional 3 vs 3 elimination match. Team {captain_a} and Team {captain_b} are set.',
        'mitb_qualified' => '{wrestler} is in the Money in the Bank ladder match at WrestleMania. Six climb, one briefcase.',
        'mitb_winner' => '{wrestler} climbed the ladder and grabbed the briefcase: a world title shot they can cash in on any card for a year.',
        'mitb_cash_in' => '{wrestler} cashed in the Money in the Bank briefcase on {champion}, and {result}.',
        'mitb_expiring' => "{wrestler}'s Money in the Bank briefcase is close to expiring. Cash it in or lose it.",
        'win_streak' => '{wrestler} has won {n} in a row and the locker room is taking notice.',
        'streak_snapped' => '{loser} had won {n} straight until {winner} stopped them at {show}.',
        'losing_streak' => '{wrestler} has lost {n} straight. Morale will suffer if this keeps up.',
        'interference_finish' => '{interferer} got involved and cost {loser} the match. {winner} takes the win.',
        'interbrand_series_result' => '{brand} won the WrestleMania interbrand series {n}–{m} and gets a manual re-draft pick and a protected superstar.',
        'upset_win' => 'Nobody expected {winner} to beat {loser}, but that is exactly what happened.',
        'rivalry_started' => '{wrestler_a} and {wrestler_b} have a problem with each other. Expect them to cross paths again soon.',
        'rivalry_heating_up' => 'Every meeting between {wrestler_a} and {wrestler_b} makes this feud more personal.',
        'rivalry_blowoff' => '{winner} beat {loser} at {ppv} and finally put their rivalry to rest.',
        'rivalry_milestone' => '{wrestler_a} and {wrestler_b} have been at war for {weeks} straight weeks, and the crowd reaction keeps getting louder.',
        'debut' => '{wrestler} made a first appearance on {brand} thanks to a New Superstar promo.',
        'legend_signed' => '{legend} signed with {brand} out of Free Agency and joins the roster next week.',
        'free_agent_signed' => '{brand} picked up {wrestler} from Free Agency. They join the roster next week.',
        'released' => '{brand} let {wrestler} go. They are back in Free Agency.',
        'loser_leaves' => '{loser} lost a Loser Leaves Show match to {winner} and is gone from {brand}. Nobody can sign them for 4 weeks.',
        'trade_completed' => 'The rosters have changed: {wrestlers_out} head to {brand_b} in exchange for {wrestlers_in}.',
        'redraft_pick' => '{brand} took {wrestler} from {source} in the WrestleMania re-draft.',
        'injury' => '{wrestler} suffered a {label} ({severity}) and is out for {weeks} weeks.',
        'injury_escalated' => '{wrestler} kept competing through an injury and it got worse: now a {label}, out for {weeks} weeks.',
        'injury_return' => '{wrestler} has healed and is available to book again.',
        'contract_demand' => '{wrestler} has won {n} straight and thinks that deserves a new contract.',
        'title_demand' => '{wrestler} wants a shot at the {title} and is getting tired of waiting.',
        'morale_unrest' => '{wrestler} is upset with how {brand} is using them. If nothing changes, they could try to leave.',
        'defection_attempt' => '{wrestler} is unhappy on {brand} and has asked to move to the other show.',
        'defection_completed' => '{wrestler} was unhappy and has moved to {rival_brand}. Any titles they held are vacated.',
        'defection_blocked' => 'The other roster is full, so the move fell through. {wrestler} refuses to compete for 2 weeks.',
        'ratings_war' => '{brand} drew the bigger audience this week.',
        'ratings_streak' => '{brand} has won the ratings war {n} weeks running.',
        'slander_result' => '{brand} ran a smear campaign against {rival_brand} this week.',
        'slander_result.succeeds' => "{brand} went after {rival_brand} with a smear campaign this week, and it stuck. {rival_brand}'s locker room will be answering questions for weeks.",
        'slander_result.fails' => '{brand} tried to smear {rival_brand} this week, but nobody bought it. The money is gone and {rival_brand} is laughing.',
        'slander_result.backfires' => '{brand} ran one smear campaign too many against {rival_brand}. The fans turned on it, and it made {brand} look worse.',
        'interference_promo_result' => "{brand} sent someone to crash {rival_brand}'s show.",
        'interference_promo_result.disrupts' => "{brand} sent someone to crash {rival_brand}'s show, and it worked: the segment fell apart.",
        'interference_promo_result.fails to disrupt' => "{brand} tried to crash {rival_brand}'s show, but security handled it and the show went on.",
        'season_reset' => 'WrestleMania is over. Each brand can re-draft from the rival roster before the new season starts.',
        'match_result' => '{match}: {winner} got the win over {loser}{by} at {show}.',
        'main_event_hype_result' => '{brand} hyped its main event all night, and {winner} delivered against {loser}.',
        'promo_result' => '{brand} put {promo} on this week\'s show, and it {outcome}.',
        'promo_result.succeeds' => '{brand} put {promo} on this week\'s show, and it paid off.',
        'promo_result.fails' => '{brand} put {promo} on this week\'s show, but it fell flat. The money is spent either way.',
        'promo_result.fizzles' => '{brand} planned {promo} for this week, but the card changed and it had nothing to work with.',
        'contract_expiring' => "{wrestler} has {n} weeks left on their {brand} contract. Without a new deal they'll walk into Free Agency.",
        'tickets_on_sale' => '{event} is next on the calendar on {date}.',
        'brand_spotlight' => "{brand}'s top champion spent the week with the fans in {city}, or the roster hung out at WWE HQ. Then a word on the budget.",
        'superstar_recap' => "A look back at a {brand} superstar's last match.",
        'injury_report' => "How many {brand} superstars were hurt in the last 6 months, with advice when it's too many.",
        'locker_room_note' => 'Who is unhappy, whose contract is running out, or all quiet.',
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
        return $this->fill($this->promoStory($payload)[0] ?? $this->template($type), $payload);
    }

    /**
     * The narrative summary shown under a news card.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(NewsEventType $type, array $payload): string
    {
        $story = $this->promoStory($payload);

        if ($story !== null) {
            return $this->fill($story[1], $payload);
        }

        $variant = $payload['outcome'] ?? $payload['won_or_lost'] ?? null;
        $key = is_string($variant) && isset(self::SUMMARIES["{$type->value}.{$variant}"]) ? "{$type->value}.{$variant}" : $type->value;

        return $this->fill(self::SUMMARIES[$key], $payload);
    }

    /**
     * The promo story an event was written from (`story` + `variant` in its
     * payload, see `PromoStories`), if any.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}|null
     */
    private function promoStory(array $payload): ?array
    {
        $key = $payload['story'] ?? null;
        $variant = $payload['variant'] ?? null;

        return is_string($key) && is_int($variant) ? PromoStories::story($key, $variant) ?? FallbackStories::story($key, $variant) : null;
    }

    /**
     * The default (no-variant) summary template for an event type.
     */
    public function summaryTemplate(NewsEventType $type): string
    {
        return self::SUMMARIES[$type->value];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * @return list<string>
     */
    public function summaryTypes(): array
    {
        return array_values(array_filter(array_keys(self::SUMMARIES), fn (string $key): bool => ! str_contains($key, '.')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fill(string $template, array $payload): string
    {
        $text = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($payload): string {
            $value = $payload[$m[1]] ?? null;

            return is_scalar($value) ? (string) $value : $m[0];
        }, $template);

        $text = (string) preg_replace('/\b1 weeks\b/', '1 week', $text);

        // "…at Friday Night Smackdown." reads as a typo.
        return (string) preg_replace('/([!?])\./', '$1', $text);
    }
}
