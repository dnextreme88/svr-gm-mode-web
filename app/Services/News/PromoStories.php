<?php

namespace App\Services\News;

/**
 * News stories for aired promos (house rule): every promo tells its own
 * story, chosen by what happened around it on the show. Each situation has
 * at least 4 variants (headline + summary), and one is drawn with equal odds
 * from the save's seeded RNG.
 *
 * Situations ending in a `+` are extras: they join the base situation's pool
 * when the wrestler's form calls for it (a streak, a long wait for a title
 * shot, holding a title), so they may have fewer than 4 variants.
 *
 * `{placeholders}`: brand, rival_brand, show, wrestler, wrestler_b,
 * opponent, winner, loser, champion, challenger, title, how, where, streak,
 * weeks, injury, weeks_left, amount, ppv, ppv_weeks, pronoun, rivalry.
 */
class PromoStories
{
    /**
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const STORIES = [
        // Interview Space: how the interview played into the wrestler's night.
        'interview_space.success_won' => [
            ['{wrestler} talks the talk, then walks the walk', '{wrestler} sat down with the {brand} interviewer and promised a win. Later that night they delivered, beating {opponent} {how}.'],
            ['Fired-up {wrestler} rides the interview to a win', 'Still buzzing from a strong interview, {wrestler} carried that energy into the ring and beat {opponent} {how}.'],
            ['{wrestler}: "I told you so"', '{wrestler} called their shot in the interview and made good on it at {show}, beating {opponent} {how}.'],
            ['Inspired {wrestler} turns words into a win', 'The crowd bought every word of the interview, and {wrestler} rewarded them by beating {opponent} {how}.'],
        ],
        'interview_space.success_lost' => [
            ['Did {wrestler} talk too much?', '{wrestler} ran their mouth in the interview, and {opponent} made them pay for it, beating them {how} at {show}.'],
            ["{wrestler}'s big words come back to bite", 'The interview went over well, but {opponent} took it personally and beat {wrestler} {how}.'],
            ["{opponent} answers {wrestler}'s interview in the ring", '{wrestler} used the interview to call out the locker room. {opponent} heard it and responded by beating them {how}.'],
            ['Distracted {wrestler} falls to {opponent}', '{wrestler} still had the interview on their mind when the bell rang, and {opponent} took advantage, winning {how}.'],
        ],
        'interview_space.failed_won' => [
            ['{wrestler} shakes off a flat interview', 'The interview went nowhere, but {wrestler} let their wrestling do the talking and beat {opponent} {how}.'],
            ['Bad interview, good night for {wrestler}', "The fans tuned out {wrestler}'s interview, then tuned right back in when {wrestler} beat {opponent} {how}."],
            ['{wrestler} wins ugly after a rough interview', '{wrestler} stumbled through the interview and looked rattled, but still found a way past {opponent} {how}.'],
            ['Actions over words for {wrestler}', "{wrestler} isn't much of a talker, as the interview showed. In the ring it was a different story: they beat {opponent} {how}."],
        ],
        'interview_space.failed_lost' => [
            ['Nothing goes right for {wrestler}', "{wrestler}'s interview fell flat, and the night got worse when {opponent} beat them {how}."],
            ['{wrestler} has a night to forget', 'First an awkward interview, then a loss to {opponent} {how}. {wrestler} left {show} with nothing.'],
            ['Rattled {wrestler} drops one to {opponent}', "The crowd turned on {wrestler}'s interview, and {opponent} smelled blood, winning {how}."],
            ["{wrestler} can't talk or win their way out of trouble", 'A forgettable interview and a loss to {opponent} {how}. {brand} will want more from {wrestler}.'],
        ],
        'interview_space.unbooked' => [
            ['{wrestler} fires up the {show} crowd', "{wrestler} wasn't booked to wrestle, but used the interview to hype the rest of {show}, and the packed house loved it."],
            ['{wrestler} promises big things for {brand}', 'In a sit-down interview, {wrestler} talked up what is coming on {brand} and got the crowd buzzing.'],
            ['{wrestler} thanks the {brand} faithful', '{wrestler} took the interview to thank a loud, full arena for the support. No match this week, but the fans went home happy.'],
            ['{wrestler} puts the {brand} locker room on notice', 'With no match on the card, {wrestler} used the interview to warn the rest of the roster: they are coming for whoever is next.'],
        ],
        'interview_space.unbooked_win_streak+' => [
            ['{wrestler} wants tougher competition', '{wrestler} has won {streak} straight and used the interview to ask {brand} for a real challenge.'],
            ["Who's next? {wrestler} is running out of opponents", '{streak} wins in a row, and {wrestler} says nobody on {brand} has given them a fight yet.'],
        ],
        'interview_space.unbooked_loss_streak+' => [
            ['{wrestler} asks for a break', 'After {streak} straight losses, {wrestler} used the interview to ask {brand} for an easier opponent to get back on track.'],
            ['{wrestler}: "My luck is about to change"', '{wrestler} has lost {streak} in a row but promised the fans the slide ends now.'],
        ],
        'interview_space.unbooked_title_shot+' => [
            ['{wrestler} demands a title shot', "{wrestler} hasn't had a title match in {weeks} weeks and used the interview to tell {brand} they are done waiting."],
            ['{wrestler} wants gold', 'In the interview, {wrestler} made it clear: {weeks} weeks without a title match is too long.'],
        ],
        'interview_space.unbooked_champion+' => [
            ['{wrestler} talks up the {title}', '{wrestler} brought the {title} to the interview and dared anyone on {brand} to come and take it.'],
            ['Champion {wrestler} sends a message', 'The {title} holder used the interview to remind {brand} who is on top.'],
        ],
        // The fallback when neither a match nor the wrestler's form gives the
        // interview a story (house rule, 2026-10-25): a sit-down interview
        // or an interview, goofy or enthusiastic, one variant each.
        'interview_space.spotlight' => [
            ['{wrestler} sits down with {show}', '{wrestler} had a sit-down interview tonight and was being goofy. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division!'],
            ['A fired-up sit-down with {wrestler}', '{wrestler} had a sit-down interview tonight and was being enthusiastic. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division!'],
            ['{wrestler} clowns around on {show}', '{wrestler} had an interview tonight and was being goofy. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division!'],
            ['{wrestler} is pumped up on {show}', '{wrestler} had an interview tonight and was being enthusiastic. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division!'],
        ],
        // A failed interview that another superstar crashed (house rule).
        'interview_space.crashed' => [
            ['{wrestler_b} crashes {wrestler}\'s sit-down interview!', "{wrestler} had a sit-down interview tonight and was being goofy. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} ambushes {wrestler} mid-interview!', "{wrestler} had a sit-down interview tonight and was being enthusiastic. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} decks {wrestler} on {show}!', "{wrestler} had an interview tonight and was being goofy. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} gatecrashes {wrestler}\'s interview!', "{wrestler} had an interview tonight and was being enthusiastic. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. What's gonna happen next between these two?{rivalry}"],
        ],
        'interview_space.unbooked_flat' => [
            ["{wrestler}'s interview falls flat", '{wrestler} had the microphone and nothing to say. The crowd had checked out before it was over.'],
            ['Awkward silence for {wrestler}', "{wrestler}'s interview never found its footing, and {show} moved on quickly."],
            ['{wrestler} loses the crowd', 'The interview ran long and the fans let {wrestler} know it.'],
            ["Cut short: {wrestler}'s interview goes nowhere", "{brand} pulled the plug on {wrestler}'s interview early after it failed to connect with the crowd."],
        ],

        // Main Event Hype: how the hyped main event went.
        'main_event_hype.success_title_change' => [
            ['The hype was real: {winner} is the new {title} holder!', '{brand} hyped its main event all night, and it delivered: {winner} beat {loser} {how} to win the {title}.'],
            ['Main event delivers: {winner} takes the {title} from {loser}', 'All the build paid off when {winner} beat {loser} {how}. A new {title} reign starts tonight.'],
            ["{winner} wins the {title} in {brand}'s hyped main event", 'The crowd was ready for something big, and {winner} gave it to them, beating {loser} {how} for the {title}.'],
            ['History in the main event: {winner} dethrones {loser}', '{brand} promised a main event nobody would forget. {winner} beating {loser} {how} for the {title} was exactly that.'],
        ],
        'main_event_hype.success_title_retained' => [
            ['The hype was real: {winner} retains the {title}', "{brand}'s main event lived up to the build. {winner} beat {loser} {how} and keeps the {title}."],
            ['{winner} survives {loser} in a main event classic', 'The main event got the hype it deserved, and {winner} walked out still {title} holder after beating {loser} {how}.'],
            ['{loser} comes up short in the {title} main event', '{loser} had the crowd behind them in a heavily hyped main event, but {winner} beat them {how} to retain the {title}.'],
            ['Still champion: {winner} closes {show} with the {title}', '{brand} built the night around this {title} match, and {winner} delivered, beating {loser} {how}.'],
        ],
        'main_event_hype.success_match' => [
            ['The hype was real: {winner} beats {loser} in the main event', '{brand} hyped its main event all night, and {winner} delivered, beating {loser} {how}.'],
            ["{winner} steals the show in {brand}'s main event", '{winner} and {loser} tore the house down before {winner} won {how}.'],
            ['Main event lives up to the billing', '{brand} promised a main event worth watching. {winner} beat {loser} {how}, and the crowd left happy.'],
            ['{loser} falls in the spotlight', 'With the whole arena watching, {loser} came up short. {winner} beat them {how} to close {show}.'],
        ],
        'main_event_hype.failed_title_change' => [
            ['The hype fell flat, but {winner} is the new {title} holder', "{brand}'s main event build never caught on, but nobody will forget the ending: {winner} beat {loser} {how} for the {title}."],
            ['Quiet build, loud finish: {winner} wins the {title}', 'The crowd shrugged at the main event hype, then jumped out of their seats when {winner} beat {loser} {how} for the {title}.'],
            ['{winner} takes the {title} after a lukewarm build', "The fans didn't buy the hype. They bought {winner} beating {loser} {how} for the {title}."],
            ["{loser}'s reign ends in an undersold main event", "{brand}'s main event hype missed, but the result didn't: {winner} took the {title} from {loser} {how}."],
        ],
        'main_event_hype.failed_title_retained' => [
            ['The hype missed, but {winner} keeps the {title}', "{brand}'s main event build fell flat. {winner} beat {loser} {how}, and the {title} stays put."],
            ['Flat build, same champion', 'The crowd never warmed to the main event hype. {winner} beat {loser} {how} to retain the {title}.'],
            ["{loser} can't take the {title} from {winner}", "{brand} tried to sell a title change all night. It didn't happen: {winner} retained by beating {loser} {how}."],
            ['{winner} retains as the main event underwhelms', "The hype didn't land and neither did {loser}'s challenge. {winner} keeps the {title} after winning {how}."],
        ],
        'main_event_hype.failed_match' => [
            ['Main event hype falls flat', '{brand} pushed its main event all night, but the crowd never bought in. {winner} beat {loser} {how}.'],
            ['{winner} beats {loser}, but the hype missed', 'The main event build went nowhere with the fans. {winner} still got the win over {loser} {how}.'],
            ['A quiet main event for {brand}', 'For all the hype, the main event came and went. {winner} beat {loser} {how} to close {show}.'],
            ['{brand} oversold its main event', "The fans weren't as excited as {brand} hoped. {winner} won the main event against {loser} {how}."],
        ],
        'main_event_hype.title_cheap' => [
            ['{champion} escapes the main event with the {title}', '{challenger} won the main event {how}, but titles only change hands on a decisive win. {champion} slipped out with the {title}.'],
            ['Controversy in the main event: {champion} keeps the gold', 'The main event ended {how}. {challenger} got the win, {champion} kept the {title}, and nobody in the arena was happy.'],
            ['{challenger} wins, but the {title} stays put', "{challenger} beat {champion} {how} in the main event. It wasn't enough to take the {title}."],
            ['{champion} finds a way out', 'With the {title} slipping away, the main event ended {how}. {champion} leaves {show} still champion.'],
        ],
        'main_event_hype.no_match' => [
            ['{brand} hypes a main event that never happens', '{brand} spent money hyping the main event, but there was no match on the card to hype.'],
            ['Main Event Hype with no main event', "The hype ran on {show}, but the card didn't have a main event to sell."],
            ['{brand} oversells an empty card', '{brand} promised a big main event. The fans never got one.'],
            ['Mixed signals from {brand}', 'A promo hyped the main event, but {show} had no match to back it up.'],
        ],

        // Title Match Hype: how the hyped title match went, and where.
        'title_match_hype.success_change' => [
            ['Hyped and delivered: {winner} wins the {title}', '{brand} built up the {title} match all night, and it paid off {where}: {winner} beat {loser} {how} to become the new champion.'],
            ['The build pays off with a new {title} holder', '{winner} took the {title} from {loser} {how} {where}, just as the hype promised.'],
            ['{winner} answers the hype and takes the {title}', 'All the talk before the {title} match was about whether {loser} could hold on. {winner} settled it {how} {where}.'],
            ['Changing of the guard {where}', 'The {title} match got the full hype treatment, and {winner} made it count, beating {loser} {how}.'],
        ],
        'title_match_hype.success_retained' => [
            ['{winner} lives up to the hype and keeps the {title}', '{brand} hyped the {title} match all night. {winner} beat {loser} {how} {where} to stay champion.'],
            ["{loser}'s hyped title shot comes up short", 'The build made {loser} look like a real threat to the {title}, but {winner} retained {how} {where}.'],
            ['Still champion: {winner} retains {where}', 'The crowd was hot for the {title} match, and {winner} gave them a win over {loser} {how}.'],
            ['{winner} turns back {loser}', 'A well-hyped {title} match ended the way {winner} wanted: a win over {loser} {how} {where}.'],
        ],
        'title_match_hype.failed_change' => [
            ['Little hype, big result: {winner} wins the {title}', "The {title} hype never caught on, but {winner} beating {loser} {how} {where} got everyone's attention."],
            ['{winner} takes the {title} after a flat build', 'Nobody was talking about the {title} match before it started. Everyone is now: {winner} beat {loser} {how} {where}.'],
            ['Nobody saw it coming: {loser} loses the {title}', 'The hype for the {title} match missed, and so did {loser}. {winner} is the new champion after winning {how} {where}.'],
            ['{winner} is champion, hype or no hype', "The promo fell flat, but the title match didn't. {winner} took the {title} from {loser} {how} {where}."],
        ],
        'title_match_hype.failed_retained' => [
            ['{winner} retains the {title} as the hype misses', "{brand}'s promo for the {title} match fell flat. {winner} beat {loser} {how} {where}, and nothing changed."],
            ['Same champion after a quiet build', 'The {title} match never got the crowd going. {winner} retained against {loser} {how} {where}.'],
            ["{loser}'s title shot ends without much noise", "The build didn't land and neither did {loser}. {winner} keeps the {title} after winning {how} {where}."],
            ['{winner} keeps the {title} {where}', 'The hype missed, but {winner} did not. They beat {loser} {how} to stay {title} holder.'],
        ],
        'title_match_hype.title_cheap' => [
            ['{champion} escapes with the {title} {where}', '{challenger} won {how}, but titles only change hands on a decisive win. {champion} slipped out with the {title}.'],
            ['A cheap escape keeps the {title} on {champion}', 'The hyped {title} match ended {how} {where}. {challenger} got the win, and {champion} got to keep the gold.'],
            ['{challenger} wins the match, not the {title}', "{challenger} beat {champion} {how} {where}. It wasn't enough to take the {title}."],
            ['Controversy {where}: {champion} keeps the gold', 'All that hype for the {title} match, and it ended {how}. {champion} is still champion, and {challenger} is furious.'],
        ],
        'title_match_hype.fizzles' => [
            ["{brand}'s {title} hype has nothing to sell", '{brand} hyped a {title} match, but the match was gone from the card by the time {show} aired.'],
            ['No {title} match after all', 'The {title} hype ran, but the match it was selling never happened.'],
            ['Fans left waiting for the {title}', "{brand} promised a {title} match. The card didn't have one, and the crowd noticed."],
            ['Mixed signals from {brand} on the {title}', 'A promo hyped the {title}, but there was no title match on {show} to back it up.'],
        ],

        // Comeback Reminder: an injured star stays on the fans' minds.
        'comeback_reminder.success' => [
            ['{wrestler} is coming back', "{brand} aired a video on {wrestler}'s recovery from a {injury}. They're about {weeks_left} away, and the crowd can't wait."],
            ["Don't forget about {wrestler}", '{brand} showed {wrestler} rehabbing a {injury} and expects them back in about {weeks_left}.'],
            ['{wrestler} sends a message from the sidelines', "Out with a {injury}, {wrestler} promised the {brand} fans they'll be back stronger in about {weeks_left}."],
            ["The countdown to {wrestler}'s return is on", '{brand} reminded the fans that {wrestler} is about {weeks_left} from returning after a {injury}. The reaction was loud.'],
        ],
        'comeback_reminder.failed' => [
            ["{wrestler}'s comeback video falls flat", "{brand} aired an update on {wrestler}'s {injury}, but the crowd barely reacted. They're still about {weeks_left} away."],
            ['Out of sight, out of mind for {wrestler}?', "A reminder about {wrestler}'s return from a {injury} didn't land. The fans have moved on for now."],
            ['Quiet reaction to the {wrestler} update', "{brand}'s look at {wrestler}'s recovery from a {injury} got a polite clap and little else."],
            ['{wrestler} update gets lost in the shuffle', 'The {wrestler} comeback segment came and went. With about {weeks_left} still to go, {brand} will need to try again.'],
        ],

        // Inactive Superstar: a planned week off.
        'inactive_superstar.success' => [
            ['{wrestler} takes a well-earned breather', '{wrestler} sat this week out on purpose. The time off should have them fresh for the next show.'],
            ['{wrestler} rests up', '{brand} gave {wrestler} the week off to recover. Expect them back on the card soon, fresher than before.'],
            ['A quiet week for {wrestler}, by design', "{wrestler} wasn't on {show}, and that was the plan. {brand} wants them healthy for what's next."],
            ['{wrestler} recharges on the sidelines', '{wrestler} spent the week away from the ring, getting their body right.'],
        ],
        'inactive_superstar.failed' => [
            ["{wrestler}'s week off doesn't help much", "{wrestler} sat the week out but didn't get much from it and is still banged up."],
            ['{wrestler} sits out, still sore', "A week away from the ring didn't do as much for {wrestler} as {brand} hoped."],
            ['Restless {wrestler} wants back in', "{wrestler} hated sitting out and didn't rest much. Expect them to demand a match next week."],
            ['No quick fix for {wrestler}', "{wrestler} took the week off, but the aches haven't gone anywhere."],
        ],
        'inactive_superstar.fizzles' => [
            ["{wrestler}'s rest week gets cancelled", '{brand} planned to rest {wrestler}, but they ended up booked on {show} anyway.'],
            ['So much for a week off, {wrestler}', '{wrestler} was supposed to sit out {show}. Instead, they were on the card.'],
            ['Change of plans for {wrestler}', "{brand}'s plan to rest {wrestler} fell apart when they were booked on the show."],
            ['No rest for {wrestler}', "{wrestler} didn't get the week off {brand} planned. They wrestled on {show} instead."],
        ],

        // Rivalries.
        'start_rivalry.success' => [
            ['Bad blood brewing between {wrestler} and {wrestler_b}', '{wrestler} and {wrestler_b} came face to face on {show}, and it got heated fast. This is not over.'],
            ['{wrestler} and {wrestler_b} have a problem', 'A backstage confrontation between {wrestler} and {wrestler_b} ended with both being pulled apart.'],
            ['A new feud on {brand}', '{wrestler} got in the face of {wrestler_b}, and the crowd wants to see them settle it.'],
            ['{wrestler_b} has {wrestler} in their sights', "{wrestler_b} didn't like what {wrestler} had to say on {show}. Expect them to cross paths soon."],
        ],
        'start_rivalry.failed' => [
            ['{wrestler} and {wrestler_b} brawl, but nobody cares', "{wrestler} and {wrestler_b} went at it on {show}, but the crowd didn't buy it as the start of anything."],
            ["A feud that didn't start", '{brand} tried to put {wrestler} and {wrestler_b} together. The segment fizzled.'],
            ['Flat face-off for {wrestler} and {wrestler_b}', "{wrestler} and {wrestler_b} stared each other down, and that's all that happened."],
            ["The fans don't buy {wrestler} vs {wrestler_b}", 'The confrontation between {wrestler} and {wrestler_b} got a muted reaction.'],
        ],
        'continue_rivalry.success' => [
            ['The {wrestler}–{wrestler_b} feud gets more personal', '{wrestler} and {wrestler_b} went at it again on {show}, and neither one is backing down.'],
            ["{wrestler} won't let it go", '{wrestler} reminded {wrestler_b} and the {brand} crowd that their rivalry is far from finished.'],
            ['Security needed for {wrestler} and {wrestler_b}', 'Another run-in between {wrestler} and {wrestler_b} ended with officials pulling them apart.'],
            ['{wrestler_b} fires back at {wrestler}', '{wrestler_b} had plenty to say about {wrestler} on {show}. The rivalry rolls on.'],
        ],
        'continue_rivalry.failed' => [
            ['Is the {wrestler}–{wrestler_b} feud running out of steam?', "{wrestler} and {wrestler_b} crossed paths on {show}, but the crowd didn't react like it used to."],
            ['Same old story for {wrestler} and {wrestler_b}', 'The latest {wrestler} and {wrestler_b} segment felt like a rerun.'],
            ['Fans tune out {wrestler} vs {wrestler_b}', '{brand} kept the {wrestler}–{wrestler_b} rivalry going on {show}, but it fell flat.'],
            ['{wrestler} and {wrestler_b} go through the motions', 'Another confrontation between {wrestler} and {wrestler_b}, and nothing new came of it.'],
        ],

        'diva_bikini_contest.success' => [
            ['{wrestler} and {wrestler_b} heat up {show}', 'The bikini contest between {wrestler} and {wrestler_b} was one of the loudest segments of the night.'],
            ['Bikini contest a hit on {brand}', '{wrestler} and {wrestler_b} had the crowd on its feet in a bikini contest on {show}.'],
            ['{wrestler} vs {wrestler_b}: the crowd wins', 'Nobody in the arena was complaining after {wrestler} and {wrestler_b} faced off in a bikini contest.'],
            ['{brand} Divas steal the spotlight', 'The {wrestler} and {wrestler_b} bikini contest drew one of the biggest reactions on {show}.'],
        ],
        'diva_bikini_contest.failed' => [
            ['Bikini contest falls flat on {brand}', "The {wrestler} and {wrestler_b} bikini contest didn't get the reaction {brand} wanted."],
            ['A cold night for {wrestler} and {wrestler_b}', 'The bikini contest between {wrestler} and {wrestler_b} got a lukewarm response.'],
            ['{wrestler} and {wrestler_b} contest ends early', "{brand} cut the bikini contest short when it didn't connect with the crowd."],
            ["{brand}'s bikini contest misses", 'For all the build, the {wrestler} and {wrestler_b} bikini contest came and went quietly.'],
        ],

        // Money and hype.
        'advertising.success' => [
            ['{brand} cashes in on advertising', '{brand} sold ad time on {show} and banked {amount}.'],
            ['Sponsors line up for {brand}', 'Advertisers paid {amount} to get in front of the {show} audience.'],
            ['{brand} brings in {amount} from advertisers', "{show}'s ad breaks paid off. The money lands next week."],
            ['A good week for the {brand} accountants', '{brand} picked up {amount} in advertising on {show}.'],
        ],
        'advertising.failed' => [
            ['Advertisers pass on {brand}', '{brand} tried to sell ad time on {show}, but nobody bit.'],
            ['No sponsors for {brand} this week', 'The advertising push on {show} came up empty.'],
            ['{brand} ad slots go unsold', "{show}'s ad breaks stayed empty. No money this week."],
            ['A quiet week for {brand} sales', '{brand} pitched advertisers on {show} without any luck.'],
        ],
        'merchandise_sponsorship.success' => [
            ['{brand} merchandise flies off the shelves', 'Fans at {show} snapped up {brand} merchandise, bringing in {amount}.'],
            ['{brand} merch deal pays {amount}', 'The merchandise sponsorship on {show} landed its {amount} deal.'],
            ['T-shirts and dollars for {brand}', '{brand} sold its way to {amount} in merchandise on {show}.'],
            ['{brand} sponsorship pays off', 'The merchandise sponsorship came through: {amount} for {brand}.'],
        ],
        'merchandise_sponsorship.failed' => [
            ['Slow merch sales for {brand}', "{brand}'s merchandise push on {show} didn't move much."],
            ['{brand} merchandise sits on the shelves', 'The fans at {show} kept their wallets closed.'],
            ['Sponsorship stalls for {brand}', '{brand} ran its merchandise sponsorship on {show}, but sales were thin.'],
            ['No merch money for {brand}', '{brand} came away from {show} with nothing from merchandise.'],
        ],
        'ppv_hype.success' => [
            ['{brand} builds toward {ppv}', '{brand} spent part of {show} selling {ppv}, {ppv_weeks} away, and the crowd is buying.'],
            ['{ppv} buzz is growing', 'The {ppv} hype on {show} got a big reaction. It is {ppv_weeks} away.'],
            ['All eyes on {ppv}', '{brand} reminded the fans that {ppv} is {ppv_weeks} away, and the arena roared.'],
            ['{brand} is ready for {ppv}', 'The road to {ppv} ran through {show}, and {brand} made it count.'],
        ],
        'ppv_hype.failed' => [
            ["{ppv} hype doesn't land", '{brand} tried to sell {ppv} on {show}, but the crowd stayed quiet. It is {ppv_weeks} away.'],
            ['Fans not sold on {ppv} yet', 'The {ppv} promo on {show} came and went without much reaction.'],
            ['A slow build to {ppv}', "{brand} hyped {ppv}, {ppv_weeks} away, but it didn't catch on."],
            ['{brand} still needs to sell {ppv}', 'The {ppv} hype on {show} missed. {brand} has {ppv_weeks} to fix it.'],
        ],

        // New Superstar: the debut (success) or a quiet first look (failure).
        'new_superstar.success' => [
            ['{wrestler} makes their {brand} debut!', '{wrestler} made a first appearance on {brand} thanks to a New Superstar promo, and the crowd took notice.'],
            ['Welcome to {brand}, {wrestler}', "{wrestler} was introduced to the {brand} fans on {show}. They're here to make an impact."],
            ['{wrestler} arrives on {show}', "{brand}'s newest signing, {wrestler}, made a statement in their first appearance."],
            ['New face, big reaction: {wrestler} debuts', 'The {brand} crowd got its first look at {wrestler} and liked what it saw.'],
        ],
        'new_superstar.failed' => [
            ["{wrestler}'s introduction falls flat", "{brand} introduced {wrestler} on {show}, but the crowd didn't know what to make of them."],
            ['A quiet first look at {wrestler}', "{wrestler}'s first {brand} segment didn't get much of a reaction."],
            ['{wrestler} still has to win the fans over', "The New Superstar promo for {wrestler} missed. They'll need a big first match."],
            ["Who's {wrestler}? {brand} fans aren't sure", "{wrestler}'s introduction on {show} came and went without much noise."],
        ],

        // Rival-targeting promos always make the WWE headline.
        'slander_promotion.succeeds' => [
            ["{brand}'s smear campaign against {rival_brand} succeeds!", "{brand} went after {rival_brand} with a smear campaign this week, and it stuck. {rival_brand}'s locker room will be answering questions for weeks."],
            ['{brand} drags {rival_brand} through the mud', 'The {brand} smear campaign hit its mark, and {rival_brand} looks bad this week.'],
            ['{rival_brand} reeling after {brand} attack ads', "{brand}'s campaign against {rival_brand} worked. The fans are talking, and not kindly."],
            ['Dirty tricks pay off for {brand}', '{brand} went negative on {rival_brand}, and the fans bought every word.'],
        ],
        'slander_promotion.fails' => [
            ["{brand}'s smear campaign against {rival_brand} fails!", '{brand} tried to smear {rival_brand} this week, but nobody bought it. The money is gone and {rival_brand} is laughing.'],
            ['{rival_brand} shrugs off {brand} smear', "The {brand} campaign against {rival_brand} didn't stick."],
            ['{brand} wastes money on attack ads', "{brand}'s shots at {rival_brand} missed, and the fans moved on."],
            ['No damage done: {rival_brand} survives {brand} smear', '{brand} went after {rival_brand}, and {rival_brand} barely noticed.'],
        ],
        'slander_promotion.backfires' => [
            ["{brand}'s smear campaign against {rival_brand} backfires!", '{brand} ran one smear campaign too many against {rival_brand}. The fans turned on it, and it made {brand} look worse.'],
            ['{brand} goes to the well once too often', 'Another smear campaign against {rival_brand}, and this time the fans turned on {brand}.'],
            ['Fans sick of {brand} attacks on {rival_brand}', "{brand}'s latest shot at {rival_brand} blew up in its face."],
            ['{rival_brand} wins the war of words', '{brand} smeared {rival_brand} again, and the fans sided with {rival_brand}.'],
        ],
        'interference_promotion.disrupts' => [
            ["{brand} disrupts {rival_brand}'s show!", "{brand} sent {wrestler} to crash {rival_brand}'s show, and it worked: the segment fell apart."],
            ['{wrestler} invades {rival_brand}', "{wrestler} showed up uninvited on {rival_brand}'s show and caused chaos for {brand}."],
            ['Chaos on {rival_brand} as {wrestler} crashes the show', "{rival_brand}'s night went sideways when {wrestler} from {brand} hit the ring."],
            ['{brand} sends a message through {wrestler}', "{wrestler} ruined part of {rival_brand}'s show, and {brand} is loving it."],
        ],
        'interference_promotion.fails' => [
            ["{brand} fails to disrupt {rival_brand}'s show!", "{brand} sent {wrestler} to crash {rival_brand}'s show, but security handled it and the show went on."],
            ['{wrestler} turned away at the {rival_brand} door', "{wrestler}'s attempt to invade {rival_brand} ended before it began."],
            ["{rival_brand} shuts down {brand}'s invasion", "{brand} sent {wrestler} after {rival_brand}'s show. It didn't work."],
            ['Failed raid: {wrestler} gets nowhere on {rival_brand}', '{wrestler} tried to cause trouble for {rival_brand} and was escorted out.'],
        ],
    ];

    /**
     * What each situation means, for `/gm/docs`.
     *
     * @var array<string, string>
     */
    private const SITUATIONS = [
        'success_won' => 'Success, then won their match',
        'success_lost' => 'Success, then lost their match',
        'failed_won' => 'Failed, then won their match',
        'failed_lost' => 'Failed, then lost their match',
        'unbooked' => 'Success, not booked in a match',
        'unbooked_win_streak+' => 'Extra: on a winning streak (3+)',
        'unbooked_loss_streak+' => 'Extra: on a losing streak (3+)',
        'unbooked_title_shot+' => 'Extra: no title match for a while',
        'unbooked_champion+' => 'Extra: holds a title',
        'unbooked_flat' => 'Failed, not booked in a match',
        'success_title_change' => 'Success: the title changed hands',
        'success_title_retained' => 'Success: the champion retained',
        'success_match' => 'Success: a non-title main event',
        'failed_title_change' => 'Failed: the title changed hands',
        'failed_title_retained' => 'Failed: the champion retained',
        'failed_match' => 'Failed: a non-title main event',
        'title_cheap' => 'The challenger won by DQ or count-out, so the champion kept the title',
        'no_match' => 'No match on the card',
        'success_change' => 'Success: the title changed hands',
        'success_retained' => 'Success: the champion retained',
        'failed_change' => 'Failed: the title changed hands',
        'failed_retained' => 'Failed: the champion retained',
        'fizzles' => 'Fizzled',
        'success' => 'Success',
        'failed' => 'Failed',
        'succeeds' => 'Success',
        'fails' => 'Failed',
        'backfires' => 'Backfired (used twice in 4 weeks)',
        'disrupts' => 'Success',
    ];

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function story(string $key, int $variant): ?array
    {
        return self::STORIES[$key][$variant] ?? null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function variants(string $key): array
    {
        return self::STORIES[$key] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::STORIES);
    }

    /**
     * Every promo's situations and variants, grouped by promo key.
     *
     * @return array<string, list<array{situation: string, label: string, extra: bool, variants: list<array{headline: string, summary: string}>}>>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::STORIES as $key => $variants) {
            [$promo, $situation] = explode('.', $key, 2);
            $catalog[$promo][] = [
                'situation' => $situation,
                'label' => self::SITUATIONS[$situation] ?? $situation,
                'extra' => str_ends_with($situation, '+'),
                'variants' => array_map(fn (array $v): array => ['headline' => $v[0], 'summary' => $v[1]], $variants),
            ];
        }

        return $catalog;
    }
}
