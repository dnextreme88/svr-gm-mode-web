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
        'title_change' => 'NEW CHAMPION! {winner} defeats {loser} for the {title} {how}!',
        // News written before the 2026-10-25 wording, without `via`.
        'title_change.legacy' => 'NEW CHAMPION! {winner} defeats {loser} for the {title} at {show}!',
        'title_retained' => '{champion} retains the {title} against {challenger}.',
        'retained_by_dq' => '{challenger} wins, but {champion} keeps the {title} on a {method}!',
        'contender_earned_shot' => '{contender} beats {champion} and earns a {title} match at {next_show}!',
        'last_chance_result' => '{contender} {won_or_lost} their last chance at the {title}.',
        'champion_survived_challenge' => '{champion} survives {contender} and the {title} is safe, for now!',
        'winner_takes_all' => 'WINNER TAKES ALL! {winner} outlasts {losers} and leaves with {titles}!',
        'winner_takes_all.celebrate' => 'WINNER TAKES ALL! {winner} outlasts {losers} and leaves with {titles}!',
        'winner_takes_all.revenge' => '{losers} vow revenge after losing it all to {winner}!',
        'number_one_contender' => '{contender} earns a shot at {champion} and the {title}!',
        'number_one_contender.fired_up' => '{contender} earns a shot at {champion} and the {title}!',
        'number_one_contender.staredown' => '{champion} and new #1 contender {contender} come face to face!',
        'number_one_contender.fans' => '{contender} celebrates a #1 contender win with the fans!',
        'contendership_voided' => 'The {title} #1 contendership is off',
        'contender_awarded_title' => '{contenders} awarded the vacant {title}!',
        'title_vacated' => 'The {title} has been vacated: {reason}.',
        'title_awarded' => '{wrestler} named the new {title} holder.',
        'reign_milestone' => '{champion} marks {weeks} weeks as {title}.',
        'record_broken' => 'History made: {wrestler} sets a new {record} record!',
        'ppv_upcoming' => '{ppv} is {n} weeks away.',
        'attendance' => '{show} draws {crowd} fans!',
        'attendance.packed' => '{crowd} fans pack the arena for {show}!',
        'attendance.biggest' => '{show} draws {crowd} fans, the biggest crowd of the week!',
        'attendance.spoken' => 'The fans have spoken: {crowd} turn up for {show}!',
        'attendance.house' => 'Big house for {show}: {crowd} in attendance!',
        'ppv_main_event_set' => 'Main event confirmed for {ppv}: {wrestler_a} vs {wrestler_b}!',
        'ppv_results' => '{ppv} results: {top_result} headlines the night.',
        'ppv_results.main_event' => '{ppv} main event: {winner} {defeats} {loser}{gimmick}{ppv_how}!',
        'ppv_results.title_change' => '{ppv} main event: {winner} {defeats} {loser}{gimmick} to win the {title}!',
        'ppv_results.title_retained' => '{ppv} main event: {winner} {defeats} {loser}{gimmick} to retain the {title}!',
        'ppv_results.title_kept' => '{ppv} main event: {winner} {defeats} {loser}{gimmick} by {via}, but the {title} stays put!',
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
        'match_result' => '{winner} {defeats} {loser} in decisive fashion. {pronoun} made sure to get the win {how}.',
        'match_result.not_decisive' => '{winner} {defeats} {loser} via {via}. Not the cleanest win, but a win is a win.',
        // House rule: a decisive win by someone who went in with a minor injury
        // that didn't turn serious by the end of the show.
        'match_result.injured' => 'Handicapped and determined, {winner} {defeats} {loser} in a hard-hitting match {how} despite suffering a minor injury. Remember, an injury is not a hindrance to winning the match!',
        'match_result.legacy' => '{winner} {defeats} {loser}{by} at {show}.',
        'main_event_hype_result' => "The hype was real: {winner} {defeats} {loser}{by} in {brand}'s main event!",
        'promo_result' => "{brand}'s {promo} promo {outcome}.",
        'contract_expiring' => "{wrestler}'s {brand} contract runs out in {n} weeks.",
        'tickets_on_sale' => 'Tickets on sale now for {event}!',
        'brand_spotlight' => '{champion} takes the {title} on the road.',
        'superstar_recap' => "Looking back at {wrestler}'s last match.",
        'injury_report' => '{brand} injury report: {count} in 6 months.',
        'locker_room_note' => 'News from the {brand} locker room.',
        'rivalry_milestone' => "{wrestler_a} vs {wrestler_b}: {weeks} weeks in, the fans can't get enough.",
        // Factions (house rule).
        'faction_formed' => 'A new force is born: {members} are now {faction}!',
        'faction_member_added' => '{wrestler} joins {faction}!',
        'faction_title_won' => 'NEW CHAMPIONS! {faction} defeat {loser} for the {title} {how}!',
        'faction_title_lost' => '{faction} lose the {title} to {winner}.',
        'faction_interbrand_win' => '{faction} win one for {brand} at {ppv}!',
        'faction_disbanded' => '{faction} have gone their separate ways.',
    ];

    /**
     * The narrative line under each card: why it happened and what comes
     * next. A `type.variant` key overrides `type` when the payload's
     * `outcome` (or `won_or_lost`) matches the variant.
     *
     * @var array<string, string>
     */
    private const SUMMARIES = [
        'title_change' => '{winner} beat {loser} {how} and {walks} out with the {title}! {the_champion} {looks} forward to meeting new challengers that {champion_pronoun} will dethrone!',
        'title_change.legacy' => '{winner} beat {loser} at {show} and walks out with the {title}. The old reign is over.',
        'title_retained' => '{challenger} came for the {title}, but {champion} found a way to win and keeps the gold.',
        'retained_by_dq' => '{challenger} won the match, but titles only change hands on a decisive win. {champion} leaves with the {title}.',
        'contender_earned_shot' => '{contender} beat champion {champion} in a Champion vs. Contender match and earned a {title} match {next_show}. {pronoun} {is} thrilled about it and determined to do {possessive} best to capture the title.',
        'contender_earned_shot.legacy' => '{contender} beat champion {champion} in a Champion vs Contender match. The win earns a {title} match {next_show}.',
        'champion_survived_challenge' => '{champion} dodged a bullet against {contender} in a Champion vs. Contender match. Feeling lucky, {pronoun_lc} {is} optimistic that the {title} is safe in the meantime.',
        'winner_takes_all' => '{winner} won the Winner Takes All match {how} and now holds {titles}. {pronoun} headed straight to the fans to celebrate the biggest night of {possessive} career with them.',
        'winner_takes_all.celebrate' => '{winner} won the Winner Takes All match {how} and now holds {titles}. {pronoun} headed straight to the fans to celebrate the biggest night of {possessive} career with them.',
        'winner_takes_all.revenge' => '{losers} lost {titles} to {winner} in a Winner Takes All match. Those titles meant the world, and the frustration showed: {loser_pronoun} vowed to get revenge and make sure {winner} loses next time.',
        'number_one_contender' => '{contender} won the #1 Contender\'s Match and can\'t wait for the title match. {champion} had better bring {champion_possessive} A-game and be ready to lose, because {pronoun_lc} {is} coming for the {title}.',
        'number_one_contender.fired_up' => '{contender} won the #1 Contender\'s Match and can\'t wait for the title match. {champion} had better bring {champion_possessive} A-game and be ready to lose, because {pronoun_lc} {is} coming for the {title}.',
        'number_one_contender.staredown' => '{champion} came out after the #1 Contender\'s Match to congratulate {contender}, and it turned into a long staredown. Both sides are looking forward to their {title} match.',
        'number_one_contender.fans' => '{contender} won the #1 Contender\'s Match and celebrated in the crowd. {pronoun} said the win belongs to the fans and promised to bring them the {title}, because this victory is for everyone.',
        'contendership_voided' => '{contenders} won\'t get their {title} match after all: {reason}.',
        'contender_awarded_title' => 'The {title} was vacated with {contenders} waiting as #1 contender. Instead of a vacancy match, the title was awarded on the spot. {pronoun} won\'t forget how it got here.',
        'last_chance_result' => '{contender} had already lost to the champion twice in this reign. This was their final shot at the {title}.',
        'last_chance_result.lost' => '{contender} had already lost to the champion twice in this reign and came up short again. They are locked out of {title} matches against this champion.',
        'last_chance_result.won' => '{contender} had lost to the champion twice in this reign, but the third time paid off with the {title}.',
        'title_vacated' => 'Nobody holds the {title} right now: {reason}. A new champion will have to be crowned.',
        'title_awarded' => '{wrestler} has been named the {title} holder and will defend it on upcoming shows.',
        'reign_milestone' => '{champion} has held the {title} for {weeks} weeks and nobody has managed to take it.',
        'record_broken' => '{wrestler} now holds the {record} record in the title history books.',
        'ppv_upcoming' => '{ppv} is {n} weeks away. Expect the card to take shape over the coming shows.',
        'attendance' => '{show} drew {crowd} fans this week.{others}',
        'attendance.packed' => 'The building was full for {show}: {crowd} fans came out to see it.{others}',
        'attendance.biggest' => 'No show drew more this week than {show}, with {crowd} fans in attendance.{others}',
        'attendance.spoken' => '{crowd} fans bought a ticket for {show}, and the card gave them a reason to.{others}',
        'attendance.house' => '{show} played to {crowd} fans, a house the whole company will notice.{others}',
        'ppv_main_event_set' => '{ppv} has its main event: {wrestler_a} vs {wrestler_b}, with the world title on the line.',
        'ppv_results' => '{top_result} is the moment everyone will be talking about after {ppv}.',
        'ppv_results.main_event' => '{winner} closed out {ppv} with a win over {loser}{gimmick}, and that is the moment everyone will be talking about.{crowd}',
        'ppv_results.title_change' => '{ppv} ended with a new champion: {winner} beat {loser}{gimmick}{ppv_how} and walked out with the {title}.{crowd}',
        'ppv_results.title_retained' => '{winner} beat {loser}{gimmick}{ppv_how} to close {ppv} and is still the {title} holder.{crowd}',
        'ppv_results.title_kept' => '{winner} beat {loser}{gimmick} by {via} to close {ppv}, but titles only change hands on a decisive win, so {loser} keeps the {title}.{crowd}',
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
        'interference_promo_result.embarrasses itself on' => "{brand} sent someone to crash {rival_brand}'s show one time too many, and the fans turned on it.",
        'interference_promo_result.fails to disrupt' => "{brand} tried to crash {rival_brand}'s show, but security handled it and the show went on.",
        'season_reset' => 'WrestleMania is over. Each brand can re-draft from the rival roster before the new season starts.',
        'match_result' => 'In what was a thrilling match, {winner} got the win over {loser} as they competed in a thrilling {match_kind} match!',
        'match_result.legacy' => '{match}: {winner} got the win over {loser}{by} at {show}.',
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
        'faction_formed' => '{members} won {wins} matches together and decided to make it official. From now on they compete as {faction}, a new {type} to watch.',
        'faction_member_added' => '{faction} has a new member: {wrestler} now fights alongside them.',
        'faction_title_won' => '{faction} beat {loser} {how} and walk out with the {title}! The champions look forward to meeting new challengers that they will dethrone!',
        'faction_title_lost' => '{faction} came into the night with the {title}, but {winner} took it from them {how}.',
        'faction_interbrand_win' => '{faction} stood up for {brand} at {ppv} and beat {loser} {how}.',
        'faction_disbanded' => '{members} are no longer {faction}. Each of them goes their own way from here.',
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
        $payload = $this->withHow($payload);
        $variant = $this->variant($type, $payload, self::TEMPLATES);

        return $this->fill($this->promoStory($payload)[0] ?? self::TEMPLATES[$variant], $payload);
    }

    /**
     * The narrative summary shown under a news card.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(NewsEventType $type, array $payload): string
    {
        $payload = $this->withHow($payload);
        // A PPV main event aired before fan attendance has no crowd line.
        $payload['crowd'] ??= '';
        $payload['others'] ??= '';
        $story = $this->promoStory($payload);

        if ($story !== null) {
            return $this->fill($story[1], $payload);
        }

        return $this->fill(self::SUMMARIES[$this->variant($type, $payload, self::SUMMARIES)], $payload);
    }

    /**
     * The template key for this event: `type.variant` when the payload's
     * `outcome` (or `won_or_lost`) names one, `type.legacy` for title change
     * and match result news recorded before they carried `via`, else `type`.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $templates
     */
    private function variant(NewsEventType $type, array $payload, array $templates): string
    {
        $legacy = (in_array($type, [NewsEventType::TitleChange, NewsEventType::MatchResult], true) && ! isset($payload['via']))
            || ($type === NewsEventType::ContenderEarnedShot && ! isset($payload['pronoun']));
        $variant = $legacy
            ? 'legacy'
            : $payload['outcome'] ?? $payload['won_or_lost'] ?? null;

        return is_string($variant) && isset($templates["{$type->value}.{$variant}"]) ? "{$type->value}.{$variant}" : $type->value;
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
     * News recorded before "in a Tables match" (2026-10-28) only has `via`:
     * it keeps reading "via …".
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withHow(array $payload): array
    {
        if (! isset($payload['via']) || ! is_scalar($payload['via'])) {
            return $payload;
        }

        $payload['how'] ??= "via {$payload['via']}";
        $payload['ppv_how'] ??= " via {$payload['via']}";

        return $payload;
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
        return array_values(array_filter(array_keys(self::TEMPLATES), fn (string $key): bool => ! str_contains($key, '.')));
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
