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
        'title_change' => 'NEW CHAMPION! {winner} wins the gold!',
        // News written before the 2026-10-25 wording, without `via`.
        'title_change.legacy' => 'NEW CHAMPION! {winner} defeats {loser} for the {title} at {show}!',
        'title_retained' => '{champion} retains the {title}',
        'retained_by_dq' => '{champion} keeps the gold on a technicality',
        'contender_earned_shot' => '{contender} earns a title shot!',
        'last_chance_result' => 'Last chance for {contender}',
        'champion_survived_challenge' => '{champion} survives the challenge',
        'winner_takes_all' => 'WINNER TAKES ALL: {winner}!',
        'winner_takes_all.celebrate' => 'WINNER TAKES ALL: {winner}!',
        'winner_takes_all.revenge' => 'Revenge vowed after Winner Takes All',
        'number_one_contender' => 'New #1 contender: {contender}!',
        'number_one_contender.fired_up' => 'New #1 contender: {contender}!',
        'number_one_contender.staredown' => 'Champion and contender face off',
        'number_one_contender.fans' => '{contender} celebrates with the fans',
        'contendership_voided' => '#1 contendership called off',
        'contender_awarded_title' => '{contenders} awarded the vacant title',
        'title_vacated' => 'The {title} is vacant',
        'title_awarded' => '{wrestler} named new champion',
        'reign_milestone' => '{champion} hits {weeks} weeks as champion',
        'record_broken' => 'New record for the {title_short}!',
        'ppv_upcoming' => '{ppv} is {n} weeks away',
        'attendance' => '{crowd} fans at {show}!',
        'attendance.packed' => 'A packed house for {show}',
        'attendance.biggest' => 'Biggest crowd of the week!',
        'attendance.spoken' => 'The fans have spoken!',
        'attendance.house' => 'Big house: {crowd} fans!',
        'ppv_main_event_set' => '{ppv} main event is set',
        'ppv_results' => '{ppv}: the results are in',
        'ppv_results.main_event' => '{ppv}: {winner} wins the main event',
        'ppv_results.title_change' => '{ppv}: {winner} wins the gold!',
        'ppv_results.title_retained' => '{ppv}: {winner} retains!',
        'ppv_results.title_kept' => '{ppv}: the title stays put',
        'survivor_series_teams' => 'Survivor Series teams are set',
        'mitb_qualified' => '{wrestler} qualifies for Money in the Bank',
        'mitb_winner' => '{wrestler} wins Money in the Bank!',
        'mitb_cash_in' => 'CASH-IN! {wrestler} cashes in!',
        'mitb_expiring' => "{wrestler}'s briefcase is running out",
        'win_streak' => '{wrestler} wins {n} straight',
        'streak_snapped' => '{winner} snaps the streak!',
        'losing_streak' => '{wrestler} drops {n} straight',
        'interference_finish' => '{interferer} costs {loser} the match',
        'interbrand_series_result' => '{brand} wins the brand battle!',
        'upset_win' => 'Shocker! {winner} pulls the upset',
        'rivalry_started' => 'Bad blood brewing',
        'rivalry_heating_up' => 'A feud is boiling over',
        'rivalry_blowoff' => '{winner} settles the score',
        'rivalry_concluded' => 'A rivalry reaches its end',
        'debut' => '{wrestler} debuts on {brand}!',
        'legend_signed' => 'A Legend joins {brand}!',
        'free_agent_signed' => '{brand} signs {wrestler}',
        'released' => '{brand} releases {wrestler}',
        'loser_leaves' => '{loser} is gone from {brand}!',
        'trade_completed' => 'Blockbuster trade!',
        'redraft_pick' => '{brand} drafts {wrestler}',
        'injury' => '{wrestler} is hurt',
        'injury_escalated' => "{wrestler}'s injury gets worse",
        'injury_return' => '{wrestler} is cleared to return!',
        'contract_demand' => '{wrestler} wants a new deal',
        'title_demand' => '{wrestler} wants a title shot',
        'morale_unrest' => 'Trouble in the {brand} locker room',
        'defection_attempt' => '{wrestler} wants out!',
        'defection_completed' => '{wrestler} jumps ship!',
        'defection_blocked' => "{wrestler}'s move is blocked",
        'ratings_war' => '{brand} wins the ratings war',
        'ratings_streak' => '{brand} keeps on winning',
        'slander_result' => 'Smear campaign {outcome}!',
        'interference_promo_result' => '{brand} {outcome} {rival_brand}',
        'season_reset' => 'A new season begins',
        'redraft_results' => 'The Season {season} re-draft is in',
        'match_result' => '{winner} {defeats} {loser}',
        'match_result.not_decisive' => '{winner} steals a win',
        // House rule: a decisive win by someone who went in with a minor injury
        // that didn't turn serious by the end of the show.
        'match_result.injured' => '{winner} wins through the pain',
        'match_result.legacy' => '{winner} {defeats} {loser}{by} at {show}.',
        'main_event_hype_result' => 'The main event delivers!',
        'promo_result' => '{promo} {outcome}',
        'call_out_face' => '{wrestler} calls out {wrestler_b}',
        'call_out_heel' => '{wrestler} calls out {wrestler_b}',
        'contract_expiring' => "{wrestler}'s contract is almost up",
        'tickets_on_sale' => 'Tickets on sale for {event}!',
        'brand_spotlight' => '{champion} hits the road',
        'superstar_recap' => "{wrestler}'s last match",
        'injury_report' => '{brand} injury report',
        'locker_room_note' => 'Locker room news',
        'rivalry_milestone' => 'A rivalry the fans love',
        // Factions (house rule).
        'faction_formed' => '{faction} is born!',
        'faction_member_added' => '{wrestler} joins {faction}!',
        'faction_title_won' => 'NEW CHAMPIONS: {faction}!',
        'faction_title_lost' => '{faction} lose the gold',
        'faction_interbrand_win' => '{faction} win one for {brand}!',
        'faction_disbanded' => '{faction} split up',
    ];

    /**
     * The narrative line under each card: why it happened and what comes
     * next. A `type.variant` key overrides `type` when the payload's
     * `outcome` (or `won_or_lost`) matches the variant.
     *
     * @var array<string, string>
     */
    private const SUMMARIES = [
        'title_change' => "{winner} beat {loser} {how} and {walks} out with the {title}! The arena came unglued at the three-count, and the celebration spilled up the ramp and into the back. Championships are hard to win and harder to keep, and {loser} will feel this one for a long time. Everybody in the locker room watched it happen on the monitors. Now every one of them knows exactly where the gold is.\n\n{the_champion} {looks} forward to meeting new challengers that {champion_pronoun} will dethrone! A new reign starts with a target on its back, and the line of contenders forms tonight. {loser} will want a rematch, and the rest of the roster will want a turn. The first title defense is always the hardest one. The fans can't wait to see who steps up.",
        'title_change.legacy' => '{winner} beat {loser} at {show} and walks out with the {title}. The old reign is over, and a new one starts tonight. The crowd saw history made, and they let everyone know it. {loser} will have to start over at the back of the line. The title picture on the brand looks very different this morning.',
        'title_retained' => '{challenger} came for the {title}, but {champion} found a way to win and keeps the gold. It was a hard-fought title match from the opening bell. More than once it looked like the belt was about to change hands. The champion dug deep when it mattered most. {challenger} will have to go back to the drawing board. For now, the reign rolls on.',
        'retained_by_dq' => "{challenger} won the match, but titles only change hands on a decisive win. {champion} leaves with the {title}. The crowd didn't like it one bit, and they let the champion hear about it all the way to the back. A disqualification or a count-out keeps the belt where it is, no matter who gets their hand raised. {challenger} has a strong case for another shot. This story is far from over.",
        'contender_earned_shot' => "{contender} beat champion {champion} in a Champion vs. Contender match and earned a {title} match {next_show}. {pronoun} {is} thrilled about it and determined to do {possessive} best to capture the title. Beating the champion once is a statement. Beating the champion with the gold on the line is a whole different story. The champion now has to prepare for a challenger who already knows how to win. The rematch can't come soon enough for the fans.",
        'contender_earned_shot.legacy' => '{contender} beat champion {champion} in a Champion vs Contender match. The win earns a {title} match {next_show}. The champion walked away from this one with plenty to think about. A challenger who has already won once is a dangerous one. The title match is set, and the stakes are clear.',
        'champion_survived_challenge' => '{champion} dodged a bullet against {contender} in a Champion vs. Contender match. Feeling lucky, {pronoun_lc} {is} optimistic that the {title} is safe in the meantime. A loss would have meant a title match against {contender}, and nobody needed reminding of that. The challenger came close and pushed the champion to the limit. For tonight, at least, the champion can breathe a little easier. Others will be lining up for the same chance soon.',
        'winner_takes_all' => "{winner} won the Winner Takes All match {how} and now holds {titles}. {pronoun} headed straight to the fans to celebrate the biggest night of {possessive} career with them. Every title in the match was on the line, and every one of them now belongs to one superstar. Nobody on the roster has more gold right now. The fans in the front row got a close look at all of it.\n\nHolding more than one title means defending more than one title. Every contender on the brand now has a reason to chase {winner}. The losers will want their belts back, and they won't wait politely. It's a lot of gold to carry, and a lot of targets to wear. For tonight, though, it was all celebration.",
        'winner_takes_all.celebrate' => "{winner} won the Winner Takes All match {how} and now holds {titles}. {pronoun} headed straight to the fans to celebrate the biggest night of {possessive} career with them. Every title in the match was on the line, and every one of them now belongs to one superstar. Nobody on the roster has more gold right now. The fans in the front row got a close look at all of it.\n\nHolding more than one title means defending more than one title. Every contender on the brand now has a reason to chase {winner}. The losers will want their belts back, and they won't wait politely. It's a lot of gold to carry, and a lot of targets to wear. For tonight, though, it was all celebration.",
        'winner_takes_all.revenge' => "{losers} lost {titles} to {winner} in a Winner Takes All match. Those titles meant the world, and the frustration showed: {loser_pronoun} vowed to get revenge and make sure {winner} loses next time. The words were heated and the cameras caught every one of them. Losing one title hurts, but losing it in a match like this cuts deeper. There was no rematch clause in anybody's mind, just payback.\n\n{winner} now holds more gold than anyone on the brand. That makes for a fine celebration and a long list of enemies. The fans will be watching to see who strikes first. Nobody walks away quietly from a loss like this. Expect fireworks the next time they cross paths.",
        'number_one_contender' => "{contender} won the #1 Contender's Match and can't wait for the title match. {champion} had better bring {champion_possessive} A-game and be ready to lose, because {pronoun_lc} {is} coming for the {title}. Until that match happens, nobody else can challenge for the title. The champion knows exactly who is next. The crowd is already looking forward to it.",
        'number_one_contender.fired_up' => "{contender} won the #1 Contender's Match and can't wait for the title match. {champion} had better bring {champion_possessive} A-game and be ready to lose, because {pronoun_lc} {is} coming for the {title}. Until that match happens, nobody else can challenge for the title. The champion knows exactly who is next. The crowd is already looking forward to it.",
        'number_one_contender.staredown' => "{champion} came out after the #1 Contender's Match to congratulate {contender}, and it turned into a long staredown. Neither one blinked, and neither one said a word. The crowd stood the whole time, waiting for somebody to throw the first punch. Nobody did, but the message was clear. Both sides are looking forward to their {title} match.",
        'number_one_contender.fans' => "{contender} won the #1 Contender's Match and celebrated in the crowd. {pronoun} said the win belongs to the fans and promised to bring them the {title}, because this victory is for everyone. Hands reached over the barricade from every direction. The title match is next, and nobody else can jump the line. The champion had better be ready.",
        'contendership_voided' => "{contenders} won't get their {title} match after all: {reason}. The #1 contendership was earned in the ring, and now it's gone. The title picture is wide open again. Anyone on the brand can step up and claim a shot. The champion's next challenger is anyone's guess.",
        'contender_awarded_title' => "The {title} was vacated with {contenders} waiting as #1 contender. Instead of a vacancy match, the title was awarded on the spot. {pronoun} won't forget how it got here. It's not the way anyone dreams of becoming champion, but the gold counts all the same. The first defense will show whether the reign is built to last.",
        'last_chance_result' => '{contender} had already lost to the champion twice in this reign. This was their final shot at the {title}. A third loss would lock them out for as long as this champion holds it. Both of them knew what was riding on it from the opening bell. It was the kind of match that decides careers.',
        'last_chance_result.lost' => "{contender} had already lost to the champion twice in this reign and came up short again. They are locked out of {title} matches against this champion. Three tries, three losses, and the door is now shut. It's a bitter pill after everything they put into it. Their only hope is that someone else takes the title first.",
        'last_chance_result.won' => "{contender} had lost to the champion twice in this reign, but the third time paid off with the {title}. Everybody knew it was now or never. One more loss would have ended their title hopes for as long as that champion held the belt. Instead, they finally got it done. It's a win nobody will forget.",
        'title_vacated' => 'Nobody holds the {title} right now: {reason}. A new champion will have to be crowned. The belt sits in the office until somebody earns it. Every contender on the brand smells an opportunity. The race for the vacant title starts now.',
        'title_awarded' => "{wrestler} has been named the {title} holder and will defend it on upcoming shows. The office made the call, and the gold has a new home. Now the work starts. Every challenger on the brand will want to test the new champion. The first defense can't come soon enough.",
        'reign_milestone' => "{champion} has held the {title} for {weeks} weeks and nobody has managed to take it. Challengers have come and gone, and the gold hasn't moved. That kind of reign is rare in this business. The locker room is running out of people who think they can win it. Someone will have to break the streak eventually.",
        'record_broken' => "{wrestler} now holds the {record} record in the title history books. It's the kind of mark that stands for years. The names that held it before are some of the best ever to lace up the boots. Now there's a new name at the top. It will take something special to beat it.",
        'ppv_upcoming' => '{ppv} is {n} weeks away. Expect the card to take shape over the coming shows. Rivalries will boil over and title pictures will come into focus. Every superstar on the roster wants a spot on a pay-per-view. The next few weeks will decide who gets one.',
        'attendance' => "{show} drew {crowd} fans this week. It was the biggest crowd of the week. A good card fills seats, and this one did exactly that. The box office will be happy with the numbers. The fans who came out got their money's worth.{others}",
        'attendance.packed' => "The building was full for {show}: {crowd} fans came out to see it. There wasn't an empty seat in the house. The noise started before the first match and didn't stop until the lights went down. A crowd like that lifts everybody on the card. The box office will be happy with the numbers.{others}",
        'attendance.biggest' => 'No show drew more this week than {show}, with {crowd} fans in attendance. The lines at the box office started early. The card gave people a reason to buy a ticket, and they did. A big house is the best review a show can get. The other shows will want to top it next week.{others}',
        'attendance.spoken' => "{crowd} fans bought a ticket for {show}, and the card gave them a reason to. It was the biggest crowd of the week. The matches delivered and the crowd stayed loud all night. People talk about a show like that on the way home. It's the kind of number that gets noticed in the office.{others}",
        'attendance.house' => '{show} played to {crowd} fans, a house the whole company will notice. It was the biggest crowd of the week by a distance. The arena was buzzing from the moment the doors opened. Shows like this are how brands win the war. The bar is set for next week.{others}',
        'ppv_main_event_set' => "{ppv} has its main event: {wrestler_a} vs {wrestler_b}, with the world title on the line. It's the match the whole card is built around. The two of them will have plenty to say before the night arrives. Both know the biggest prize on the brand is at stake. The fans are already counting down.",
        'ppv_results' => "{top_result} is the moment everyone will be talking about after {ppv}. It was a big night from top to bottom. The crowd got its money's worth, and then some. Pay-per-views are where reputations are made. This one made a few.",
        'ppv_results.main_event' => "{ppv} main event: {winner} {defeats} {loser}{gimmick}{ppv_how}! {winner} closed out {ppv} with a win over {loser}{gimmick}, and that is the moment everyone will be talking about. The main event was the one the whole night built to. Both of them left everything in the ring. The crowd was on its feet for the finish. Nights like this are why people buy pay-per-views.{crowd}\n\nFor {winner}, it's the biggest win in a long time. For {loser}, it's a long ride home. The rest of the roster watched it all from the back. Everybody knows what it takes to close a pay-per-view now. The next show starts the road to the next big night.",
        'ppv_results.title_change' => "{ppv} main event: {winner} {defeats} {loser}{gimmick} to win the {title}! {ppv} ended with a new champion: {winner} beat {loser}{gimmick}{ppv_how} and walked out with the {title}. The building exploded at the finish. A title change in the main event of a pay-per-view is as big as it gets. {loser} came in as champion and leaves with nothing. Nobody who saw it will forget it.{crowd}\n\nThe new champion has the whole roster's attention now. The rematch talk started before the lights went down. Contenders across the brand will be lining up for their chance. A reign that starts on a night like this carries a lot of expectations. The first defense will tell everybody what kind of champion {winner} is going to be.",
        'ppv_results.title_retained' => "{ppv} main event: {winner} {defeats} {loser}{gimmick} to retain the {title}! {winner} beat {loser}{gimmick}{ppv_how} to close {ppv} and is still the {title} holder. The challenger came closer than anybody expected. The champion found something extra when it mattered. It was a main event worthy of the name. The reign rolls on.{crowd}\n\n{loser} gave it everything and still came up short. A loss on the biggest stage stings for a long time. The champion, meanwhile, has proved a point to the whole roster. Anybody who wants the title will have to do better than that. The line of challengers just got a little shorter.",
        'ppv_results.title_kept' => "{ppv} main event: {winner} {defeats} {loser}{gimmick} by {via}, but the {title} stays put! Titles only change hands on a decisive win, so {loser} keeps the {title}. The challenger got the win but not the gold. The crowd booed the decision long after the bell. It was a frustrating end to a big night. Nobody thinks this is settled.{crowd}\n\n{winner} will feel robbed, and with good reason. The champion escaped with the belt, but not with much dignity. A rematch looks like the only fair answer. Expect plenty of talk before the next show. This story isn't over.",
        'survivor_series_teams' => 'Raw and Smackdown settle it in the Traditional 3 vs 3 elimination match. Team {captain_a} and Team {captain_b} are set. Three superstars on each side, eliminated one at a time until one team is left. Pride in the brand is on the line, and nothing else. The winning brand gets to call itself the best in WWE.',
        'mitb_qualified' => '{wrestler} is in the Money in the Bank ladder match at WrestleMania. Six climb, one briefcase. The briefcase is a world title shot that can be cashed in at any time. Getting into the match is the first step. Climbing the ladder is the hard part.',
        'mitb_winner' => "{wrestler} climbed the ladder and grabbed the briefcase: a world title shot they can cash in on any card for a year. Five others wanted it just as badly, and the ladder took a toll on all of them. When the briefcase came down, the crowd lost its mind. It's the most dangerous prize in the business. No champion can rest easy now.\n\nThe briefcase can be cashed in before a match, during one, or right after a title defense. It's the ultimate insurance policy. Every world champion will be checking over their shoulder. A year is a long time to wait, and a short time to plan. The clock starts now.",
        'mitb_cash_in' => "{wrestler} cashed in the Money in the Bank briefcase on {champion}, and {result}. The music hit and the whole building knew what was coming. The briefcase was a year of waiting for the right moment. This was the moment. It's the kind of thing that changes a title picture in seconds.\n\nWin or lose, the briefcase is gone now. The fans will be talking about it for weeks. The champion will remember who came for the title when they were most vulnerable. Moments like this define the year. Nobody saw it coming.",
        'mitb_expiring' => "{wrestler}'s Money in the Bank briefcase is close to expiring. Cash it in or lose it. A world title shot doesn't stay valid forever. The champion knows it's coming and can almost count the days. The question is when, and against whom.",
        'win_streak' => "{wrestler} has won {n} in a row and the locker room is taking notice. Every win makes the next opponent a little more nervous. Streaks like this get people talking about title shots. It also puts a target on the winner's back. Somebody will be looking to end it.",
        'streak_snapped' => "{loser} had won {n} straight until {winner} stopped them at {show}. Streaks end, and this one ended in front of a stunned crowd. {winner} gets the credit for doing what nobody else could. For {loser}, it's back to square one. The rematch talk has already started.",
        'losing_streak' => '{wrestler} has lost {n} straight. Morale will suffer if this keeps up. Every loss makes the next one harder to shake off. The fans have started to notice, and so has the locker room. Something has to change, and soon.',
        'interference_finish' => '{interferer} got involved and cost {loser} the match. {winner} takes the win. The rivalry between {interferer} and {loser} spilled right into the ring. {loser} had the match under control until the interruption. Nobody will be surprised if this gets worse before it gets better.',
        'interbrand_series_result' => "{brand} won the WrestleMania interbrand series {n}–{m} and gets a manual re-draft pick and a protected superstar. Three matches between the brands, and bragging rights on the biggest stage. The winning locker room celebrated like it had won a title. In a way, it had. The two tags are worth more than a belt when the re-draft comes.\n\nThe protected superstar can't be taken by the other brand. The manual pick lets the winner choose one superstar instead of leaving it to chance. Both will shape the rosters for the whole next season. The losing brand has to hope the random picks fall its way. The re-draft is next.",
        'upset_win' => 'Nobody expected {winner} to beat {loser}, but that is exactly what happened. The crowd took a second to believe it. Then the place erupted. Upsets like this are why people watch. {loser} will be hearing about this one for a while.',
        'rivalry_started' => '{wrestler_a} and {wrestler_b} have a problem with each other. Expect them to cross paths again soon. These things rarely stay quiet once they start. Every meeting from here on will mean a little more. The fans love a good grudge.',
        'rivalry_heating_up' => 'Every meeting between {wrestler_a} and {wrestler_b} makes this feud more personal. What started as a disagreement has turned into something nastier. The crowd reacts louder every time they share a ring. Neither one is backing down. This is far from over.',
        'rivalry_blowoff' => "{winner} beat {loser} at {ppv} and finally put their rivalry to rest. It was the match the whole feud built to. Every insult and every attack came out in the ring. When the bell rang, there was no doubt who came out on top. The crowd gave both of them a standing ovation.\n\nIt's over, at least for now. {winner} gets the last word, and {loser} has to live with it. The brand gets two superstars free for new stories. Rivalries like this make careers. This one will be remembered.",
        'rivalry_concluded' => "After {weeks} weeks of war, {wrestler_a} and {wrestler_b} have nothing left to settle. Their rivalry hit its peak last week, and there was nowhere higher for it to go. Every match between them drew the loudest reactions of the night. The fans picked sides and never let go. Both superstars walked away bruised but with their reputations bigger than ever. The rivalry is officially over.\n\nWhat comes next is the question everyone is asking. {wrestler_a} has new mountains to climb, and so does {wrestler_b}. Feuds this heated rarely come around more than once a year. The office will be looking for the next pair who can match it. For now, the brand gets two of its hottest superstars back for fresh stories. This one will be talked about for a long time.",
        'rivalry_milestone' => '{wrestler_a} and {wrestler_b} have been at war for {weeks} straight weeks, and the crowd reaction keeps getting louder. Most feuds burn out long before that. This one keeps finding new fuel. Every show seems to add another chapter. Nobody knows how it ends, but everybody is watching.',
        'debut' => "{wrestler} made a first appearance on {brand} thanks to a New Superstar promo. The crowd didn't know what to expect, and that was half the fun. First impressions matter in this business. This one got people talking. The roster has a new name to watch.",
        'legend_signed' => "{legend} signed with {brand} out of Free Agency and joins the roster next week. Legends draw crowds like nobody else. The locker room is buzzing about who gets to share a ring with them. The fans will be lining up to see it. It's a big day for the brand.",
        'free_agent_signed' => '{brand} picked up {wrestler} from Free Agency. They join the roster next week. A new signing brings new possibilities. The rest of the roster will be sizing up the new arrival. The first match will show what the brand paid for.',
        'released' => "{brand} let {wrestler} go. They are back in Free Agency. It's a tough day for anyone in the business. Another brand might come calling soon. For now, the roster has one fewer name on it.",
        'loser_leaves' => "{loser} lost a Loser Leaves Show match to {winner} and is gone from {brand}. Nobody can sign them for 4 weeks. Both knew what was at stake before the opening bell. One of them walked out with a career on the brand, and one walked out the door. It doesn't get more final than that.",
        'trade_completed' => 'The rosters have changed: {wrestlers_out} head to {brand_b} in exchange for {wrestlers_in}. Trades like this reshape a locker room overnight. The new arrivals will want to make an impression quickly. The ones who left will want to prove their old brand wrong. Both sides think they got the better deal.',
        'redraft_pick' => "{brand} took {wrestler} from {source} in the WrestleMania re-draft. New brand, new locker room, new opponents. It's a fresh start that some superstars wait years for. The old brand loses a familiar face. The new one gains a name to build around.",
        'injury' => "{wrestler} suffered a {label} ({severity}) and is out for {weeks} weeks. The medical team got to work right away. Nobody likes seeing anyone get hurt in the ring. It's a setback for the brand and for the superstar. The fans will be waiting for the comeback.",
        'injury_escalated' => "{wrestler} kept competing through an injury and it got worse: now a {label}, out for {weeks} weeks. The warning signs were there. Pushing through pain is part of the business, but it has its limits. This time the body gave out. It's a long road back from here.",
        'injury_return' => '{wrestler} has healed and is available to book again. The rehab is done and the doctors have signed off. Every superstar on the roster had to work around the absence. Now the comeback starts. The crowd will be glad to see the familiar face again.',
        'contract_demand' => "{wrestler} has won {n} straight and thinks that deserves a new contract. It's hard to argue with the results. Hot streaks sell tickets, and superstars on them know it. The office will have to decide what that's worth. A happy superstar is a winning superstar.",
        'title_demand' => "{wrestler} wants a shot at the {title} and is getting tired of waiting. The record says they've earned a look. Patience in this business only lasts so long. An unhappy contender can become a problem. The champion might want to keep an eye out.",
        'morale_unrest' => '{wrestler} is upset with how {brand} is using them. If nothing changes, they could try to leave. Unhappy superstars talk, and the locker room listens. A frustrated star is a flight risk. The office has some work to do.',
        'defection_attempt' => "{wrestler} is unhappy on {brand} and has asked to move to the other show. It's been building for a while. The complaints went unanswered, and now it's come to this. The other brand might have room, or it might not. Either way, the locker room has noticed.",
        'defection_completed' => "{wrestler} was unhappy and has moved to {rival_brand}. Any titles they held are vacated. The complaints had been building for weeks before the move. Now the other brand gets a motivated superstar with something to prove. It's a big loss for the brand that let it happen.\n\nThe locker room is buzzing about it. Others who feel overlooked are watching closely. The vacant titles open the door for somebody new. The war between the brands just got more personal. Expect fireworks when the two sides meet again.",
        'defection_blocked' => "The other roster is full, so the move fell through. {wrestler} refuses to compete for 2 weeks. It's a stalemate nobody wanted. The superstar is going nowhere, but isn't working either. The office has a morale problem on its hands.",
        'ratings_war' => '{brand} drew the bigger audience this week. The ratings war goes on every single week. A good card makes a big difference. The other brand will be looking for an answer. Next week is another chance.',
        'ratings_streak' => '{brand} has won the ratings war {n} weeks running. Momentum like that is hard to stop. The other brand needs something big to answer it. The fans are clearly enjoying the product. The streak goes on for now.',
        'slander_result' => '{brand} ran a smear campaign against {rival_brand} this week. The war between the brands moved from the ring to the newspapers. Both sides have plenty to say about each other. The fans are reading every word. The next shot is never far away.',
        'slander_result.succeeds' => "{brand} went after {rival_brand} with a smear campaign this week, and it stuck. {rival_brand}'s locker room will be answering questions for weeks. The papers ran with every word. Fans are taking sides, and the momentum has shifted. {rival_brand} will be looking for a way to hit back.",
        'slander_result.fails' => '{brand} tried to smear {rival_brand} this week, but nobody bought it. The money is gone and {rival_brand} is laughing. The papers barely ran the story. The fans saw it for what it was. It made {brand} look more desperate than {rival_brand} ever did.',
        'slander_result.backfires' => "{brand} ran one smear campaign too many against {rival_brand}. The fans turned on it, and it made {brand} look worse. There's such a thing as overdoing it. The papers have turned the story around. {rival_brand} couldn't have asked for a better week.",
        'interference_promo_result' => "{brand} sent someone to crash {rival_brand}'s show. Security scrambled the moment the intruder hit the ramp. The fans in the building didn't know what was happening at first. The war between the brands keeps escalating. Nobody's show is safe anymore.",
        'interference_promo_result.disrupts' => "{brand} sent someone to crash {rival_brand}'s show, and it worked: the segment fell apart. The intruder made it all the way to the ring before security caught up. The cameras caught every second of the chaos. {rival_brand} will be furious, and the damage bill won't be small. The war just went to another level.",
        'interference_promo_result.embarrasses itself on' => "{brand} sent someone to crash {rival_brand}'s show one time too many, and the fans turned on it. What used to be a shock is now a routine. The crowd booed the intruder all the way out of the building. {rival_brand} came off as the victim, and it worked in their favor. Some ideas get old fast.",
        'interference_promo_result.fails to disrupt' => "{brand} tried to crash {rival_brand}'s show, but security handled it and the show went on. The intruder barely made it past the curtain. {rival_brand}'s fans cheered as security dragged them out. It was an expensive way to make the other brand look good. Better luck next time.",
        // House rule (2026-11-15): the WrestleMania week WWE headline, written once the re-draft ends.
        'redraft_results' => "The Season {season} re-draft is in the books, and neither roster looks the way it did a week ago. Raw's new superstars are {raw_picks}. Smackdown's new arrivals are {smackdown_picks}. That's {moved} superstars who changed brands in one night. Every one of them brings their contract and their record to a new locker room. Some will thrive with a fresh start, and some will have to fight for a spot.

{immunity_brand} drew the bigger crowds going into WrestleMania, {immunity_crowd}, and earned the immunity tag. It used the tag to protect {immune}, who couldn't be picked. The WrestleMania interbrand series decided the manual draft pick. The singles match went to {series_singles}. The tag team match went to {series_tag}. The Money in the Bank ladder match went to {series_mitb}. {manual_brand} took the series {series_score}, and with it the manual draft pick. It spent the pick on {handpick}, the first name called in the re-draft.

Season {season} is officially over. Season {next_season} starts now, and both brands have been shaken up. The GMs have new faces to build around and holes to fill. Old rivalries are split across two shows, and new ones are waiting to happen. The champions stayed put, but their challengers may have changed. Expect both brands to look very different by the end of the month.",
        'season_reset' => 'WrestleMania is over. Each brand can re-draft from the rival roster before the new season starts. Up to ten superstars can change brands. The interbrand series winner gets two extra tags to protect and pick. Rosters will look very different by the first show of the season.',
        // House rule (2026-11-15): the wording the headline used to carry opens the summary.
        'match_result' => "{winner} {defeats} {loser} in decisive fashion. {pronoun} made sure to get the win {how}. It was a thrilling {match_kind} match from start to finish. Both of them came to fight, and the crowd was with them the whole way. There were near-falls that brought the fans to their feet. In the end, one of them had a little more left. It's a win that will count for something in the weeks ahead.",
        'match_result.not_decisive' => '{winner} {defeats} {loser} via {via}. Not the cleanest win, but a win is a win. It was a {match_kind} match that ended in controversy. {loser} will feel hard done by. The crowd let both of them hear about it on the way out. A rematch would not surprise anyone.',
        'match_result.injured' => "Handicapped and determined, {winner} {defeats} {loser} in a hard-hitting match {how} despite suffering a minor injury. Remember, an injury is not a hindrance to winning the match! It was a {match_kind} match that tested every bit of {winner}'s toughness. The trainer will want a long look before the next show. The fans gave {winner} a standing ovation on the way to the back.",
        'match_result.legacy' => "{match}: {winner} got the win over {loser}{by} at {show}. The crowd got a good match out of it. Both superstars gave it everything. Only one could walk away with the win. That's how it goes in this business.",
        'main_event_hype_result' => "{brand} hyped its main event all night, and {winner} delivered against {loser}. The crowd had been waiting for it since the doors opened. The build paid off with a finish they'll remember. Main events like that send people home happy. It's the best advertisement a show can have.",
        'promo_result' => "{brand} put {promo} on this week's show, and it {outcome}. Promos are a gamble every time. Some land and some don't. The money is spent either way. Next week brings another chance.",
        'call_out_face' => "{wrestler} grabbed a microphone and called out {wrestler_b} in front of the {brand} crowd. The fans hung on every word. It was a hero standing up to a bully. {wrestler_b} was watching from the back. The rivalry between them isn't going anywhere.",
        'call_out_heel' => "{wrestler} grabbed a microphone and called out {wrestler_b}, insulting the {brand} crowd along the way. The boos were deafening. It was exactly the reaction {wrestler} wanted. {wrestler_b} will have heard every word. The rivalry between them isn't going anywhere.",
        'promo_result.succeeds' => "{brand} put {promo} on this week's show, and it paid off. The crowd responded just the way the office hoped. A good segment keeps people invested between matches. It was money well spent. Expect more of the same.",
        'promo_result.fails' => "{brand} put {promo} on this week's show, but it fell flat. The money is spent either way. The crowd didn't respond the way anyone hoped. Not every segment can be a hit. The office will learn from it.",
        'promo_result.fizzles' => "{brand} planned {promo} for this week, but the card changed and it had nothing to work with. Plans in this business change fast. The segment never got off the ground. It happens to every brand sooner or later. There's always next week.",
        'contract_expiring' => "{wrestler} has {n} weeks left on their {brand} contract. Without a new deal they'll walk into Free Agency. The other brand will be watching closely. A good superstar never stays on the market for long. The clock is ticking on a decision.",
        'tickets_on_sale' => '{event} is next on the calendar on {date}.',
        'brand_spotlight' => "{brand}'s top champion spent the week with the fans in {city}, or the roster hung out at WWE HQ. Then a word on the budget.",
        'superstar_recap' => "A look back at a {brand} superstar's last match.",
        'injury_report' => "How many {brand} superstars were hurt in the last 6 months, with advice when it's too many.",
        'locker_room_note' => 'Who is unhappy, whose contract is running out, or all quiet.',
        'faction_formed' => '{members} won {wins} matches together and decided to make it official. From now on they compete as {faction}, a new {type} to watch. The wins came as a team, and now the team has a name. The rest of the roster has a new unit to worry about. Factions have a way of running a locker room. This one is just getting started.',
        'faction_member_added' => '{faction} has a new member: {wrestler} now fights alongside them. The group just got stronger. The rest of the roster will have to deal with one more opponent in the corner. New blood brings new ideas. {faction} looks more dangerous than ever.',
        'faction_title_won' => "{faction} beat {loser} {how} and walk out with the {title}! The champions look forward to meeting new challengers that they will dethrone! The whole group celebrated together in the ring. Winning gold as a team means more than winning it alone. The crowd gave them the reaction they deserved.\n\nNow comes the hard part: keeping it. Every tag team on the brand will want a shot at the new champions. {loser} will want the belts back first. A faction with gold is the most dangerous thing in a locker room. The title picture just changed for good.",
        'faction_title_lost' => '{faction} came into the night with the {title}, but {winner} took it from them {how}. The reign is over, and the group has to regroup. Losing gold as a team hurts every member. The new champions will be ready for a rematch. Something has to give inside the group now.',
        'faction_interbrand_win' => "{faction} stood up for {brand} at {ppv} and beat {loser} {how}. Pride in the brand was on the line, and they delivered. The whole locker room was cheering from the back. A win over the other brand means more than most. They'll be riding high for weeks.",
        'faction_disbanded' => "{members} are no longer {faction}. Each of them goes their own way from here. The group had its run, and now it's over. The fans will remember the good times. Whether they cross paths again is anyone's guess.",
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

        $summary = $story !== null
            ? $this->fill($story[1], $payload)
            : $this->fill(self::SUMMARIES[$this->variant($type, $payload, self::SUMMARIES)], $payload);

        // House rule (2026-11-07): a conditional match story adds how it
        // happened, as its own paragraph (story-text rule, 2026-11-11).
        $aside = trim((string) ($payload['aside_text'] ?? ''));

        return $aside === '' ? $summary : "{$summary}\n\n{$aside}";
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
     * The summary's variants for an event type (`type.variant` keys), by
     * variant name, for `/gm/docs`.
     *
     * @return array<string, string>
     */
    public function summaryVariants(NewsEventType $type): array
    {
        $variants = [];

        foreach (self::SUMMARIES as $key => $template) {
            if (str_starts_with($key, $type->value.'.')) {
                $variants[substr($key, strlen($type->value) + 1)] = $template;
            }
        }

        return $variants;
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
