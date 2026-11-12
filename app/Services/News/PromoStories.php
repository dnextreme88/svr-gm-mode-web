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
            ['{wrestler} talks the talk, then walks the walk', "{wrestler} sat down with the {brand} interviewer and promised a win. Later that night they delivered, beating {opponent} {how}. It's one thing to make a promise in front of a camera. It's another to keep it in front of a live crowd. {wrestler} did both, and the fans loved every second."],
            ['Fired-up {wrestler} rides the interview to a win', 'Still buzzing from a strong interview, {wrestler} carried that energy into the ring and beat {opponent} {how}. The crowd had been with {wrestler} since the first word. Every move in the match seemed to feed off it. {opponent} never quite found an answer. Confidence like that is hard to beat.'],
            ['{wrestler}: "I told you so"', '{wrestler} called their shot in the interview and made good on it at {show}, beating {opponent} {how}. There was no hesitation and no doubt. The win backed up every word. {opponent} will have to live with being the punchline. {wrestler} walked to the back with a grin.'],
            ['Inspired {wrestler} turns words into a win', 'The crowd bought every word of the interview, and {wrestler} rewarded them by beating {opponent} {how}. The fans were on their feet for the finish. It was the kind of night where everything clicks. {wrestler} left the building with momentum and a lot of new believers. The locker room took notice.'],
        ],
        'interview_space.success_lost' => [
            ['Did {wrestler} talk too much?', '{wrestler} ran their mouth in the interview, and {opponent} made them pay for it, beating them {how} at {show}. The words went over well with the crowd. The match did not. {opponent} looked like someone with a point to prove. {wrestler} will think twice before the next interview.'],
            ["{wrestler}'s big words come back to bite", "The interview went over well, but {opponent} took it personally and beat {wrestler} {how}. Some promises are harder to keep than others. {opponent} came out with fire in their eyes and never let up. {wrestler} was left on the mat wondering what happened. The crowd won't let them forget it."],
            ["{opponent} answers {wrestler}'s interview in the ring", '{wrestler} used the interview to call out the locker room. {opponent} heard it and responded by beating them {how}. Talk is cheap, and {opponent} proved it. The crowd loved the response even more than the interview. {wrestler} has some explaining to do.'],
            ['Distracted {wrestler} falls to {opponent}', '{wrestler} still had the interview on their mind when the bell rang, and {opponent} took advantage, winning {how}. A strong interview means nothing without a strong match to back it up. {opponent} stayed focused while {wrestler} played to the crowd. The finish came out of nowhere. Lesson learned, perhaps.'],
        ],
        'interview_space.failed_won' => [
            ['{wrestler} shakes off a flat interview', 'The interview went nowhere, but {wrestler} let their wrestling do the talking and beat {opponent} {how}. Not everyone is a talker. Some superstars are at their best when the bell rings. {wrestler} proved that tonight. The crowd forgave the interview by the time the match was over.'],
            ['Bad interview, good night for {wrestler}', "The fans tuned out {wrestler}'s interview, then tuned right back in when {wrestler} beat {opponent} {how}. The words didn't land, but the moves did. It was a reminder that wins are what count in this business. {opponent} learned it the hard way. {wrestler} will happily take the trade."],
            ['{wrestler} wins ugly after a rough interview', "{wrestler} stumbled through the interview and looked rattled, but still found a way past {opponent} {how}. It wasn't pretty, and nobody will call it a classic. A win is a win, though. {wrestler} looked relieved walking up the ramp. The interview coach might want a word."],
            ['Actions over words for {wrestler}', "{wrestler} isn't much of a talker, as the interview showed. In the ring it was a different story: they beat {opponent} {how}. The crowd went from quiet to loud in a matter of minutes. Wrestling ability is what pays the bills. {wrestler} has plenty of it."],
        ],
        'interview_space.failed_lost' => [
            ['Nothing goes right for {wrestler}', "{wrestler}'s interview fell flat, and the night got worse when {opponent} beat them {how}. Some nights nothing goes right. This was one of them. The walk back up the ramp felt a mile long. {wrestler} will want to forget this one quickly."],
            ['{wrestler} has a night to forget', "First an awkward interview, then a loss to {opponent} {how}. {wrestler} left {show} with nothing. The crowd lost interest early and never came back. It's the kind of night that shakes a superstar's confidence. {wrestler} has some work to do before the next show."],
            ['Rattled {wrestler} drops one to {opponent}', "The crowd turned on {wrestler}'s interview, and {opponent} smelled blood, winning {how}. A rough start turned into a rough night. {opponent} took advantage of every mistake. {wrestler} never looked comfortable. It's back to the drawing board."],
            ["{wrestler} can't talk or win their way out of trouble", "A forgettable interview and a loss to {opponent} {how}. {brand} will want more from {wrestler}. The talent is there, but the results aren't. The next few weeks could be important. Something has to change."],
        ],
        'interview_space.unbooked' => [
            ['{wrestler} fires up the {show} crowd', "{wrestler} wasn't booked to wrestle, but used the interview to hype the rest of {show}, and the packed house loved it. Every line got a reaction. It's not easy to steal a show without a match, but {wrestler} came close. The rest of the card benefited from the energy. Expect {wrestler} back in the ring soon."],
            ['{wrestler} promises big things for {brand}', 'In a sit-down interview, {wrestler} talked up what is coming on {brand} and got the crowd buzzing. There were hints about rivalries and title ambitions. Nothing concrete, but enough to get people talking. The fans will be watching closely. {wrestler} clearly has plans.'],
            ['{wrestler} thanks the {brand} faithful', "{wrestler} took the interview to thank a loud, full arena for the support. No match this week, but the fans went home happy. It was a warm moment in the middle of a busy show. The crowd chanted {wrestler}'s name at the end. Moments like that build a connection that lasts."],
            ['{wrestler} puts the {brand} locker room on notice', 'With no match on the card, {wrestler} used the interview to warn the rest of the roster: they are coming for whoever is next. The words were sharp and the delivery was sharper. More than one superstar in the back took it personally. The crowd ate it up. Somebody will want to answer that challenge.'],
            // Fan attendance (house rule, 2026-11-01).
            ['{wrestler} talks to {attendance} fans', "{wrestler} wasn't booked to wrestle, but had all {attendance} fans at {show} hanging on every word of the interview. It's a big crowd to hold in the palm of your hand. {wrestler} made it look easy. The cheers at the end said it all. That's what star power looks like."],
            ['{attendance} strong for {wrestler}', '{wrestler} grabbed the microphone in front of {attendance} fans at {show} and promised them a night to remember. The crowd took it as a promise. The noise was deafening. A crowd like that lifts everybody in the building. {wrestler} will want to deliver on that promise in the ring soon.'],
        ],
        'interview_space.unbooked_win_streak+' => [
            ['{wrestler} wants tougher competition', '{wrestler} has won {streak} straight and used the interview to ask {brand} for a real challenge. Beating the same level of opponent every week is getting old. {wrestler} wants a test that means something. The fans are behind the idea. The question is who steps up.'],
            ["Who's next? {wrestler} is running out of opponents", "{streak} wins in a row, and {wrestler} says nobody on {brand} has given them a fight yet. It's a bold claim, and the locker room heard it. A streak like that makes a superstar confident. It also makes them a target. Somebody will want to be the one to end it."],
        ],
        'interview_space.unbooked_loss_streak+' => [
            ['{wrestler} asks for a break', 'After {streak} straight losses, {wrestler} used the interview to ask {brand} for an easier opponent to get back on track. It was an honest moment, and the crowd respected it. Everybody hits a rough patch at some point. The hard part is climbing out of it. {wrestler} just needs one win to turn things around.'],
            ['{wrestler}: "My luck is about to change"', '{wrestler} has lost {streak} in a row but promised the fans the slide ends now. There was real fire in the words. The crowd wanted to believe it. A losing streak can break a superstar or make them stronger. {wrestler} intends to prove which it will be.'],
        ],
        'interview_space.unbooked_title_shot+' => [
            ['{wrestler} demands a title shot', "{wrestler} hasn't had a title match in {weeks} weeks and used the interview to tell {brand} they are done waiting. Patience has its limits. {wrestler} feels overlooked, and it showed. The crowd seemed to agree. The office might have to listen."],
            ['{wrestler} wants gold', "In the interview, {wrestler} made it clear: {weeks} weeks without a title match is too long. Every week on the sidelines is a week someone else gets the spotlight. {wrestler} believes it's time. The champions heard the message loud and clear. A title match would go a long way."],
        ],
        'interview_space.unbooked_champion+' => [
            ['{wrestler} talks up the {title}', '{wrestler} brought the {title} to the interview and dared anyone on {brand} to come and take it. The gold sat on the table the whole time. It was a statement as much as an interview. Challengers will be lining up. {wrestler} seemed to welcome all of them.'],
            ['Champion {wrestler} sends a message', 'The {title} holder used the interview to remind {brand} who is on top. There was no doubt in the delivery. {wrestler} sounded like a champion who plans to stay one. The rest of the roster heard every word. Somebody will want to prove the champion wrong.'],
        ],
        // The fallback when neither a match nor the wrestler's form gives the
        // interview a story (house rule, 2026-10-25): a sit-down interview
        // or an interview, goofy or enthusiastic, one variant each.
        'interview_space.spotlight' => [
            ['{wrestler} sits down with {show}', "{wrestler} had a sit-down interview tonight and was being goofy. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division! The jokes landed and the crowd laughed along. Behind the fun, there was a clear message about what comes next. It's a side of {wrestler} the fans don't often see. They liked it."],
            ['A fired-up sit-down with {wrestler}', '{wrestler} had a sit-down interview tonight and was being enthusiastic. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division! The energy was impossible to miss. {wrestler} barely stayed in the chair. The crowd fed off every word.'],
            ['{wrestler} clowns around on {show}', "{wrestler} had an interview tonight and was being goofy. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division! There were a few laughs at the interviewer's expense. The fans enjoyed the lighter moment. Underneath it all, {wrestler} sounded serious about the future."],
            ['{wrestler} is pumped up on {show}', "{wrestler} had an interview tonight and was being enthusiastic. It was a great interview and gave insights on how {pronoun} would be used and ready to take over and dominate the {brand} division! The crowd was chanting before the interview was over. It's hard not to get swept up in that kind of energy. The rest of the roster should take note."],
        ],
        // A failed interview that another superstar crashed (house rule).
        'interview_space.crashed' => [
            ['{wrestler_b} crashes {wrestler}\'s sit-down interview!', "{wrestler} had a sit-down interview tonight and was being goofy. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. Chairs went flying and the camera crew scrambled out of the way. Security arrived too late to stop it. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} ambushes {wrestler} mid-interview!', "{wrestler} had a sit-down interview tonight and was being enthusiastic. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. Nobody saw it coming, least of all {wrestler}. The crowd gasped as the set came apart. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} decks {wrestler} on {show}!', "{wrestler} had an interview tonight and was being goofy. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. The laughter stopped in an instant. {wrestler_b} stood over the wreckage and walked off without a word. What's gonna happen next between these two?{rivalry}"],
            ['{wrestler_b} gatecrashes {wrestler}\'s interview!', "{wrestler} had an interview tonight and was being enthusiastic. The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. The interviewer ducked for cover. By the time officials arrived, the damage was done. What's gonna happen next between these two?{rivalry}"],
        ],
        'interview_space.unbooked_flat' => [
            ["{wrestler}'s interview falls flat", '{wrestler} had the microphone and nothing to say. The crowd had checked out before it was over. A few fans headed for the concession stand. It happens to the best of them now and then. {wrestler} will want a better showing next time.'],
            ['Awkward silence for {wrestler}', "{wrestler}'s interview never found its footing, and {show} moved on quickly. The pauses were long and the answers were short. The interviewer did what they could. Nothing seemed to connect with the crowd. It's a night {wrestler} would rather forget."],
            ['{wrestler} loses the crowd', "The interview ran long and the fans let {wrestler} know it. A few boos started in the back of the arena. Then they spread. {wrestler} tried to win them back, but the moment had passed. Some interviews just don't work."],
            ["Cut short: {wrestler}'s interview goes nowhere", "{brand} pulled the plug on {wrestler}'s interview early after it failed to connect with the crowd. The producers made the call before it got worse. {wrestler} looked frustrated walking off the set. The crowd had already moved on. There's always next week."],
        ],

        // Main Event Hype: how the hyped main event went.
        'main_event_hype.success_title_change' => [
            ['The hype was real: {winner} is the new {title} holder!', "{brand} hyped its main event all night, and it delivered: {winner} beat {loser} {how} to win the {title}. The arena exploded when the bell rang. It was the payoff everyone had been waiting for. {loser} sat in the ring in disbelief. A new reign begins.\n\nThe build worked because the match lived up to it. Every segment pointed toward this moment, and the moment did not disappoint. {winner} celebrated with the fans at ringside. The rest of the roster now knows who holds the gold. The title picture will never look the same."],
            ['Main event delivers: {winner} takes the {title} from {loser}', 'All the build paid off when {winner} beat {loser} {how}. A new {title} reign starts tonight. The crowd was on its feet for the final minutes. Near-falls and counters kept everyone guessing until the very end. {winner} wanted it more, and it showed.'],
            ["{winner} wins the {title} in {brand}'s hyped main event", 'The crowd was ready for something big, and {winner} gave it to them, beating {loser} {how} for the {title}. The hype set the stage, and {winner} stole it. The celebration went on long after the cameras stopped rolling. {loser} will want a rematch. The new champion seems ready for anything.'],
            ['History in the main event: {winner} dethrones {loser}', "{brand} promised a main event nobody would forget. {winner} beating {loser} {how} for the {title} was exactly that. The noise when the referee's hand hit the mat for the last time was deafening. Title changes don't come much bigger. People will be talking about this one for years."],
        ],
        'main_event_hype.success_title_retained' => [
            ['The hype was real: {winner} retains the {title}', "{brand}'s main event lived up to the build. {winner} beat {loser} {how} and keeps the {title}. The challenger came close more than once. The champion had an answer every time. It was a main event worthy of all the hype."],
            ['{winner} survives {loser} in a main event classic', 'The main event got the hype it deserved, and {winner} walked out still {title} holder after beating {loser} {how}. Both of them left everything in the ring. The crowd gave them a standing ovation at the end. {loser} has nothing to be ashamed of. The champion knows it was a close call.'],
            ['{loser} comes up short in the {title} main event', "{loser} had the crowd behind them in a heavily hyped main event, but {winner} beat them {how} to retain the {title}. The fans were willing {loser} on until the very end. It wasn't enough. {winner} found a way, as champions do. The reign goes on."],
            ['Still champion: {winner} closes {show} with the {title}', '{brand} built the night around this {title} match, and {winner} delivered, beating {loser} {how}. The main event had the crowd hooked from the opening bell. {loser} gave the champion a real fight. In the end, {winner} had too much. The gold stays where it is.'],
        ],
        'main_event_hype.success_match' => [
            ['The hype was real: {winner} beats {loser} in the main event', '{brand} hyped its main event all night, and {winner} delivered, beating {loser} {how}. The match had the crowd from the first lock-up. It was worth every minute of the build. {winner} raised their arms to a standing ovation. The fans went home happy.'],
            ["{winner} steals the show in {brand}'s main event", '{winner} and {loser} tore the house down before {winner} won {how}. The pace never let up. Every big move drew a bigger reaction. {loser} was in it until the very end. It was the best match of the night by a distance.'],
            ['Main event lives up to the billing', "{brand} promised a main event worth watching. {winner} beat {loser} {how}, and the crowd left happy. The hype raised expectations, and the match met them. Both superstars looked like stars. That's what a main event should be."],
            ['{loser} falls in the spotlight', "With the whole arena watching, {loser} came up short. {winner} beat them {how} to close {show}. The main event spotlight is a bright one. {winner} shone under it, and {loser} didn't. That's how reputations are made and lost."],
        ],
        'main_event_hype.failed_title_change' => [
            ['The hype fell flat, but {winner} is the new {title} holder', "{brand}'s main event build never caught on, but nobody will forget the ending: {winner} beat {loser} {how} for the {title}. The crowd was quiet going in. It was anything but quiet at the finish. A title change has a way of waking people up. The new champion has everyone's attention now."],
            ['Quiet build, loud finish: {winner} wins the {title}', "The crowd shrugged at the main event hype, then jumped out of their seats when {winner} beat {loser} {how} for the {title}. The build may have missed, but the match didn't. Sometimes the ring tells the story better than any promo. {loser} never saw it coming. A new reign starts with a bang."],
            ['{winner} takes the {title} after a lukewarm build', "The fans didn't buy the hype. They bought {winner} beating {loser} {how} for the {title}. The main event turned out to be better than anyone expected. That's the beauty of live wrestling. Nobody knows what will happen until the bell rings."],
            ["{loser}'s reign ends in an undersold main event", "{brand}'s main event hype missed, but the result didn't: {winner} took the {title} from {loser} {how}. A title change is always big news. This one came almost out of nowhere. {loser} will be wondering what went wrong. {winner} won't be wondering about anything."],
        ],
        'main_event_hype.failed_title_retained' => [
            ['The hype missed, but {winner} keeps the {title}', "{brand}'s main event build fell flat. {winner} beat {loser} {how}, and the {title} stays put. The match itself was solid. It just never had the crowd behind it from the start. The champion will take the win either way."],
            ['Flat build, same champion', "The crowd never warmed to the main event hype. {winner} beat {loser} {how} to retain the {title}. It was a business-as-usual kind of night. {loser} gave it a go but didn't have enough. The reign rolls on, quietly."],
            ["{loser} can't take the {title} from {winner}", "{brand} tried to sell a title change all night. It didn't happen: {winner} retained by beating {loser} {how}. The fans didn't buy the hype, and they were right not to. {loser} came up short. The champion keeps the gold."],
            ['{winner} retains as the main event underwhelms', "The hype didn't land and neither did {loser}'s challenge. {winner} keeps the {title} after winning {how}. It wasn't the main event {brand} hoped for. The champion did what champions do. Next time the build will need more work."],
        ],
        'main_event_hype.failed_match' => [
            ['Main event hype falls flat', "{brand} pushed its main event all night, but the crowd never bought in. {winner} beat {loser} {how}. The match was fine, but fine isn't what anyone wanted. The hype set a bar that the night never reached. {brand} will want a better build next time."],
            ['{winner} beats {loser}, but the hype missed', "The main event build went nowhere with the fans. {winner} still got the win over {loser} {how}. The crowd was polite, not passionate. Both superstars worked hard. It just wasn't the night for it."],
            ['A quiet main event for {brand}', 'For all the hype, the main event came and went. {winner} beat {loser} {how} to close {show}. The fans filed out without much buzz. It happens to every brand now and then. Next week is a chance to do better.'],
            ['{brand} oversold its main event', "The fans weren't as excited as {brand} hoped. {winner} won the main event against {loser} {how}. The hype promised more than the match could give. That's a risk every time a brand goes big on a build. This one didn't pay off."],
        ],
        'main_event_hype.title_cheap' => [
            ['{champion} escapes the main event with the {title}', "{challenger} won the main event {how}, but titles only change hands on a decisive win. {champion} slipped out with the {title}. The crowd booed all the way to the back. {challenger} was left holding nothing but frustration. This one isn't over."],
            ['Controversy in the main event: {champion} keeps the gold', "The main event ended {how}. {challenger} got the win, {champion} kept the {title}, and nobody in the arena was happy. The hype promised a decisive finish. It didn't get one. Expect rematch talk to start immediately."],
            ['{challenger} wins, but the {title} stays put', "{challenger} beat {champion} {how} in the main event. It wasn't enough to take the {title}. The rules are the rules, and the champion knew exactly how to use them. {challenger} was furious. The fans were right there with them."],
            ['{champion} finds a way out', "With the {title} slipping away, the main event ended {how}. {champion} leaves {show} still champion. The champion looked relieved more than proud. {challenger} will want another shot, and they've earned it. The crowd certainly thinks so."],
        ],
        'main_event_hype.no_match' => [
            ['{brand} hypes a main event that never happens', "{brand} spent money hyping the main event, but there was no match on the card to hype. The fans were promised something big. They didn't get it. It was an expensive mistake. The office will want to make sure it doesn't happen again."],
            ['Main Event Hype with no main event', "The hype ran on {show}, but the card didn't have a main event to sell. The segment ended and nothing followed it. The crowd was left confused. Hype only works if there's something to back it up. This time there wasn't."],
            ['{brand} oversells an empty card', "{brand} promised a big main event. The fans never got one. The money for the promo was spent all the same. It's the kind of mix-up that makes a brand look disorganized. Better planning would have saved it."],
            ['Mixed signals from {brand}', "A promo hyped the main event, but {show} had no match to back it up. The fans noticed right away. A few of them said so on the way out. It wasn't a good look for the brand. The next card needs to be tighter."],
        ],

        // Title Match Hype: how the hyped title match went, and where.
        'title_match_hype.success_change' => [
            ['Hyped and delivered: {winner} wins the {title}', '{brand} built up the {title} match all night, and it paid off {where}: {winner} beat {loser} {how} to become the new champion. The crowd had been waiting for it since the doors opened. When the moment came, the building shook. {loser} could only watch as {winner} held the belt high. A new chapter starts tonight.'],
            ['The build pays off with a new {title} holder', '{winner} took the {title} from {loser} {how} {where}, just as the hype promised. Every segment of the night pointed toward this match. The match delivered on all of it. {winner} soaked in the cheers at ringside. {loser} has a long road back.'],
            ['{winner} answers the hype and takes the {title}', 'All the talk before the {title} match was about whether {loser} could hold on. {winner} settled it {how} {where}. The answer was no. It was a statement win for the new champion. The rest of the roster will be lining up to challenge.'],
            ['Changing of the guard {where}', 'The {title} match got the full hype treatment, and {winner} made it count, beating {loser} {how}. The old reign is over and a new one has begun. The fans got exactly what they were promised. {winner} celebrated like it was the biggest night of their life. Maybe it was.'],
        ],
        'title_match_hype.success_retained' => [
            ['{winner} lives up to the hype and keeps the {title}', "{brand} hyped the {title} match all night. {winner} beat {loser} {how} {where} to stay champion. The build made everyone wonder if this was the night the title changed hands. It wasn't. The champion proved once again why the gold belongs to them."],
            ["{loser}'s hyped title shot comes up short", "The build made {loser} look like a real threat to the {title}, but {winner} retained {how} {where}. The crowd believed in {loser} right up to the finish. {winner} had other plans. It's a tough loss after all that build. {loser} will have to start the climb again."],
            ['Still champion: {winner} retains {where}', "The crowd was hot for the {title} match, and {winner} gave them a win over {loser} {how}. The hype set the stage for a big night. The champion made sure it ended the right way for them. {loser} gave it everything. It just wasn't enough."],
            ['{winner} turns back {loser}', 'A well-hyped {title} match ended the way {winner} wanted: a win over {loser} {how} {where}. The champion looked sharp from start to finish. {loser} had moments, but never had control. The gold stays put. The line of challengers just got a little shorter.'],
        ],
        'title_match_hype.failed_change' => [
            ['Little hype, big result: {winner} wins the {title}', "The {title} hype never caught on, but {winner} beating {loser} {how} {where} got everyone's attention. Nobody expected the match to steal the night. It did. A title change has a way of making people forget a weak build. {winner} is the champion now, and that's what matters."],
            ['{winner} takes the {title} after a flat build', 'Nobody was talking about the {title} match before it started. Everyone is now: {winner} beat {loser} {how} {where}. The crowd woke up the moment it looked like the title might change. Then it did. The new champion will be the talk of the locker room.'],
            ['Nobody saw it coming: {loser} loses the {title}', 'The hype for the {title} match missed, and so did {loser}. {winner} is the new champion after winning {how} {where}. It was a stunning end to a quiet build. {loser} looked lost after the bell. The fans are still trying to process it.'],
            ['{winner} is champion, hype or no hype', "The promo fell flat, but the title match didn't. {winner} took the {title} from {loser} {how} {where}. Matches matter more than build-ups in the end. This one proved it. {winner} will carry the gold into the next show."],
        ],
        'title_match_hype.failed_retained' => [
            ['{winner} retains the {title} as the hype misses', "{brand}'s promo for the {title} match fell flat. {winner} beat {loser} {how} {where}, and nothing changed. The crowd wasn't invested, and the result didn't change their minds. It was a routine defense on a routine night. The champion moves on."],
            ['Same champion after a quiet build', "The {title} match never got the crowd going. {winner} retained against {loser} {how} {where}. Both of them worked hard. The build just didn't give the fans a reason to care. The reign continues without much fanfare."],
            ["{loser}'s title shot ends without much noise", "The build didn't land and neither did {loser}. {winner} keeps the {title} after winning {how} {where}. It was a quiet end to a quiet story. {loser} will need a better case for the next shot. The champion barely broke a sweat."],
            ['{winner} keeps the {title} {where}', "The hype missed, but {winner} did not. They beat {loser} {how} to stay {title} holder. The champion took care of business and moved on. Not every title defense needs fireworks. This one certainly didn't have any."],
        ],
        'title_match_hype.title_cheap' => [
            ['{champion} escapes with the {title} {where}', '{challenger} won {how}, but titles only change hands on a decisive win. {champion} slipped out with the {title}. The crowd let the champion hear it on the way out. All that hype, and the title never moved. {challenger} has every reason to demand a rematch.'],
            ['A cheap escape keeps the {title} on {champion}', 'The hyped {title} match ended {how} {where}. {challenger} got the win, and {champion} got to keep the gold. Nobody felt good about the finish. The fans wanted a winner and a champion to be the same person. This one is far from settled.'],
            ['{challenger} wins the match, not the {title}', "{challenger} beat {champion} {how} {where}. It wasn't enough to take the {title}. The rules were on the champion's side tonight. {challenger} stormed to the back, furious. The rematch practically books itself."],
            ['Controversy {where}: {champion} keeps the gold', "All that hype for the {title} match, and it ended {how}. {champion} is still champion, and {challenger} is furious. The crowd booed long after the match ended. The champion didn't seem to care. {challenger} certainly does."],
        ],
        'title_match_hype.fizzles' => [
            ["{brand}'s {title} hype has nothing to sell", "{brand} hyped a {title} match, but the match was gone from the card by the time {show} aired. The promo ran anyway. The fans were left waiting for a match that never came. It's an awkward look for any brand. The money was spent for nothing."],
            ['No {title} match after all', "The {title} hype ran, but the match it was selling never happened. Plans changed somewhere along the way. The crowd noticed the gap. A few of them weren't shy about saying so. Better coordination would have saved the promo."],
            ['Fans left waiting for the {title}', "{brand} promised a {title} match. The card didn't have one, and the crowd noticed. The hype pointed to something that wasn't there. The champion never even made an appearance. It was a wasted segment."],
            ['Mixed signals from {brand} on the {title}', "A promo hyped the {title}, but there was no title match on {show} to back it up. The fans were confused, and the office was embarrassed. It's the kind of mistake that's easy to avoid. The champion's next defense will need a better build. This one went nowhere."],
        ],

        // Comeback Reminder: an injured star stays on the fans' minds.
        'comeback_reminder.success' => [
            ['{wrestler} is coming back', "{brand} aired a video on {wrestler}'s recovery from a {injury}. They're about {weeks_left} away, and the crowd can't wait. The footage showed long days in rehab and hard work in the gym. The cheers when it ended were as loud as any match all night. The countdown is officially on."],
            ["Don't forget about {wrestler}", "{brand} showed {wrestler} rehabbing a {injury} and expects them back in about {weeks_left}. The video was a reminder of what the brand has been missing. The fans responded with a chant of {wrestler}'s name. Injuries take superstars away, but they don't take away the support. The return will be a big night."],
            ['{wrestler} sends a message from the sidelines', "Out with a {injury}, {wrestler} promised the {brand} fans they'll be back stronger in about {weeks_left}. The message was short and full of fire. {wrestler} looked determined in every frame. The crowd loved it. Whoever is waiting on the other side of that return had better be ready."],
            ["The countdown to {wrestler}'s return is on", "{brand} reminded the fans that {wrestler} is about {weeks_left} from returning after a {injury}. The reaction was loud. It's clear the fans haven't forgotten. The video showed a superstar fighting to get back. That return will be one to watch."],
        ],
        'comeback_reminder.failed' => [
            ["{wrestler}'s comeback video falls flat", "{brand} aired an update on {wrestler}'s recovery from a {injury}, but the crowd barely reacted. They're still about {weeks_left} away. The fans were more interested in the next match. Time away from the ring can make people forget. {wrestler} will have to win them back on return."],
            ['Out of sight, out of mind for {wrestler}?', "A reminder about {wrestler}'s return from a {injury} didn't land. The fans have moved on for now. That's the hard truth of an injury in this business. The roster keeps moving without you. {wrestler} will have to make a big impression when the time comes."],
            ['Quiet reaction to the {wrestler} update', "{brand}'s look at {wrestler}'s recovery from a {injury} got a polite clap and little else. The video was well made. It just didn't connect with the crowd tonight. The return is still on track. The excitement will have to build another way."],
            ['{wrestler} update gets lost in the shuffle', "The {wrestler} comeback segment came and went. With about {weeks_left} still to go, {brand} will need to try again. A busy card left little room for the moment to breathe. The crowd was focused elsewhere. There's still time to rebuild the buzz."],
        ],

        // Inactive Superstar: a planned week off.
        'inactive_superstar.success' => [
            ['{wrestler} takes a well-earned breather', '{wrestler} sat this week out on purpose. The time off should have them fresh for the next show. The body needs rest in this business, even if superstars hate to admit it. A week away from the ring can add years to a career. Expect {wrestler} back and ready.'],
            ['{wrestler} rests up', '{brand} gave {wrestler} the week off to recover. Expect them back on the card soon, fresher than before. The trainer signed off on the plan right away. A little rest now prevents a lot of trouble later. {wrestler} spent the week in the gym and the ice bath.'],
            ['A quiet week for {wrestler}, by design', "{wrestler} wasn't on {show}, and that was the plan. {brand} wants them healthy for what's next. The aches and pains of a long schedule add up. A week off lets them fade. {wrestler} will return with full energy."],
            ['{wrestler} recharges on the sidelines', "{wrestler} spent the week away from the ring, getting their body right. Massages, stretching and a lot of sleep were on the menu. It's not glamorous, but it works. The fatigue that's been building up is finally easing. Next week, {wrestler} will feel like a new superstar."],
        ],
        'inactive_superstar.failed' => [
            ["{wrestler}'s week off doesn't help much", "{wrestler} sat the week out but didn't get much from it and is still banged up. Sometimes rest isn't enough. The aches haven't gone away. The trainer will keep an eye on things. {brand} may need to be careful with the next booking."],
            ['{wrestler} sits out, still sore', "A week away from the ring didn't do as much for {wrestler} as {brand} hoped. The body is slow to recover after a long run of matches. {wrestler} was frustrated by the lack of progress. The plan made sense on paper. It just didn't pay off this time."],
            ['Restless {wrestler} wants back in', "{wrestler} hated sitting out and didn't rest much. Expect them to demand a match next week. Some superstars can't sit still. {wrestler} spent the week pacing the halls instead of resting. The trainer is not impressed."],
            ['No quick fix for {wrestler}', "{wrestler} took the week off, but the aches haven't gone anywhere. Recovery doesn't follow a schedule. A single week was never going to undo months of wear. {wrestler} is still available to book. The GM will want to be careful about it."],
        ],
        'inactive_superstar.fizzles' => [
            ["{wrestler}'s rest week gets cancelled", "{brand} planned to rest {wrestler}, but they ended up booked on {show} anyway. The plan fell apart before it started. {wrestler} didn't complain, but the body might. The rest week will have to wait. The trainer is shaking their head."],
            ['So much for a week off, {wrestler}', "{wrestler} was supposed to sit out {show}. Instead, they were on the card. Somebody in the office didn't get the memo. {wrestler} laced up the boots and went to work anyway. The rest will have to come another week."],
            ['Change of plans for {wrestler}', "{brand}'s plan to rest {wrestler} fell apart when they were booked on the show. It was a mix-up nobody wanted. {wrestler} is a professional and did the job. The fatigue keeps adding up, though. A real week off is overdue."],
            ['No rest for {wrestler}', "{wrestler} didn't get the week off {brand} planned. They wrestled on {show} instead. The card needed bodies, and {wrestler} was one of them. It's the nature of a busy schedule. Hopefully the rest comes soon."],
        ],

        // Rivalries.
        'start_rivalry.success' => [
            ['Bad blood brewing between {wrestler} and {wrestler_b}', '{wrestler} and {wrestler_b} came face to face on {show}, and it got heated fast. This is not over. Words turned into shoves, and shoves nearly turned into punches. Officials had to step in before it got out of hand. The crowd wants to see where this goes.'],
            ['{wrestler} and {wrestler_b} have a problem', "A backstage confrontation between {wrestler} and {wrestler_b} ended with both being pulled apart. Nobody knows exactly what started it. Everybody knows it isn't finished. Crew members scattered as the two went at it. The locker room is already picking sides."],
            ['A new feud on {brand}', '{wrestler} got in the face of {wrestler_b}, and the crowd wants to see them settle it. The tension was obvious from the first word. Neither one backed down. The fans started chanting for a match on the spot. This could be the start of something big.'],
            ['{wrestler_b} has {wrestler} in their sights', "{wrestler_b} didn't like what {wrestler} had to say on {show}. Expect them to cross paths soon. The look on {wrestler_b}'s face said everything. It's personal now. The next meeting between these two will be anything but friendly."],
        ],
        'start_rivalry.failed' => [
            ['{wrestler} and {wrestler_b} brawl, but nobody cares', "{wrestler} and {wrestler_b} went at it on {show}, but the crowd didn't buy it as the start of anything. The punches were real enough. The reaction wasn't. Some pairings just don't click with the fans. This one might need a different approach."],
            ["A feud that didn't start", '{brand} tried to put {wrestler} and {wrestler_b} together. The segment fizzled. The crowd watched in silence. Nothing about it felt personal. The office will have to look elsewhere for its next big feud.'],
            ['Flat face-off for {wrestler} and {wrestler_b}', "{wrestler} and {wrestler_b} stared each other down, and that's all that happened. The crowd waited for something more. It never came. Both of them walked away without a word. It was a non-event."],
            ["The fans don't buy {wrestler} vs {wrestler_b}", "The confrontation between {wrestler} and {wrestler_b} got a muted reaction. The crowd seemed more interested in the next match. A rivalry needs the fans behind it. This one didn't have them. Maybe another time."],
        ],
        'continue_rivalry.success' => [
            ['The {wrestler}–{wrestler_b} feud gets more personal', '{wrestler} and {wrestler_b} went at it again on {show}, and neither one is backing down. The insults got sharper and the shoves got harder. The crowd is fully invested now. Every week seems to add fuel to the fire. This feud is a long way from over.'],
            ["{wrestler} won't let it go", '{wrestler} reminded {wrestler_b} and the {brand} crowd that their rivalry is far from finished. The words were cold and deliberate. {wrestler_b} heard every one of them. The fans responded with a roar. Something has to give eventually.'],
            ['Security needed for {wrestler} and {wrestler_b}', "Another run-in between {wrestler} and {wrestler_b} ended with officials pulling them apart. It took four people to separate them. The crowd was on its feet the whole time. These two simply can't be in the same building. The next match between them will be a war."],
            ['{wrestler_b} fires back at {wrestler}', "{wrestler_b} had plenty to say about {wrestler} on {show}. The rivalry rolls on. The fans have been waiting for {wrestler_b}'s side of the story. They got it, loud and clear. {wrestler} will surely have an answer."],
        ],
        'continue_rivalry.failed' => [
            ['Is the {wrestler}–{wrestler_b} feud running out of steam?', "{wrestler} and {wrestler_b} crossed paths on {show}, but the crowd didn't react like it used to. The fire seems to be fading. Feuds can only run so long before the fans move on. Both of them will need something new to bring the heat back. The clock is ticking."],
            ['Same old story for {wrestler} and {wrestler_b}', "The latest {wrestler} and {wrestler_b} segment felt like a rerun. The crowd had seen it all before. Nothing new was said, and nothing new happened. A rivalry needs fresh chapters to stay alive. This week's wasn't one."],
            ['Fans tune out {wrestler} vs {wrestler_b}', '{brand} kept the {wrestler}–{wrestler_b} rivalry going on {show}, but it fell flat. The fans were polite at best. The segment ran a little too long. It might be time for this feud to reach its end. One big match could settle it.'],
            ['{wrestler} and {wrestler_b} go through the motions', 'Another confrontation between {wrestler} and {wrestler_b}, and nothing new came of it. The anger looked forced. The crowd noticed. Both superstars deserve a better story than this. Something needs to change.'],
        ],

        'diva_bikini_contest.success' => [
            ['{wrestler} and {wrestler_b} heat up {show}', 'The bikini contest between {wrestler} and {wrestler_b} was one of the loudest segments of the night. The crowd was on its feet from the moment they walked out. Both Divas played to the fans perfectly. The judges had a tough call to make. Everyone in the building was a winner.'],
            ['Bikini contest a hit on {brand}', '{wrestler} and {wrestler_b} had the crowd on its feet in a bikini contest on {show}. The noise rivaled the main event. Both of them knew exactly how to work a crowd. The segment flew by. The fans wanted more.'],
            ['{wrestler} vs {wrestler_b}: the crowd wins', 'Nobody in the arena was complaining after {wrestler} and {wrestler_b} faced off in a bikini contest. The cheers were deafening. Both Divas brought their best. The camera flashes never stopped. It was a highlight of the show.'],
            ['{brand} Divas steal the spotlight', 'The {wrestler} and {wrestler_b} bikini contest drew one of the biggest reactions on {show}. The Divas proved once again that they can steal a show. The crowd chanted both names. The segment had the whole arena buzzing. {brand} will want to do it again.'],
        ],
        'diva_bikini_contest.failed' => [
            ['Bikini contest falls flat on {brand}', "The {wrestler} and {wrestler_b} bikini contest didn't get the reaction {brand} wanted. The crowd was strangely quiet. Both Divas tried to fire them up. It just didn't happen tonight. Not every segment can be a hit."],
            ['A cold night for {wrestler} and {wrestler_b}', 'The bikini contest between {wrestler} and {wrestler_b} got a lukewarm response. The arena felt chilly in more ways than one. The crowd was saving its energy for the matches. Both Divas deserved better. It was just the wrong night.'],
            ['{wrestler} and {wrestler_b} contest ends early', "{brand} cut the bikini contest short when it didn't connect with the crowd. The producers made the call quickly. {wrestler} and {wrestler_b} looked disappointed as they left. The segment never found its rhythm. There will be other chances."],
            ["{brand}'s bikini contest misses", "For all the build, the {wrestler} and {wrestler_b} bikini contest came and went quietly. The crowd's reaction was polite at best. The timing on the card may not have helped. Both Divas will want a better showcase. This one didn't work out."],
        ],

        // Money and hype.
        'advertising.success' => [
            ['{brand} cashes in on advertising', "{brand} sold ad time on {show} and banked {amount}. The money lands next week. Sponsors want to be where the fans are, and the fans were watching. The accountants are very happy. It's a welcome boost for the budget."],
            ['Sponsors line up for {brand}', 'Advertisers paid {amount} to get in front of the {show} audience. Demand for the ad slots was high. A big audience brings big money. The deal will help pay for the matches and contracts. It was a good week for business.'],
            ['{brand} brings in {amount} from advertisers', "{show}'s ad breaks paid off. The money lands next week. Every commercial slot was sold. The sales team earned their paychecks this week. The budget is better for it."],
            ['A good week for the {brand} accountants', '{brand} picked up {amount} in advertising on {show}. The deal came together quickly. Sponsors liked what they saw in the ratings. The extra cash will come in handy. The office is smiling.'],
        ],
        'advertising.failed' => [
            ['Advertisers pass on {brand}', "{brand} tried to sell ad time on {show}, but nobody bit. The sales team made all the calls they could. The sponsors just weren't interested this week. The budget won't get the boost it needed. They'll try again soon."],
            ['No sponsors for {brand} this week', 'The advertising push on {show} came up empty. Not a single deal closed. It happens when the market is slow. The accountants are not happy. The next pitch will need to be stronger.'],
            ['{brand} ad slots go unsold', "{show}'s ad breaks stayed empty. No money this week. The sales team came back with nothing to show for it. It's a setback for the budget. Better ratings would make the pitch easier."],
            ['A quiet week for {brand} sales', '{brand} pitched advertisers on {show} without any luck. The phones were quiet all week. Sponsors are cautious right now. The brand will have to find money somewhere else. Advertising will be back on the table next week.'],
        ],
        'merchandise_sponsorship.success' => [
            ['{brand} merchandise flies off the shelves', 'Fans at {show} snapped up {brand} merchandise, bringing in {amount}. The lines at the merch stand never got shorter. T-shirts, caps and posters sold out by the main event. The sponsorship deal paid off in full. The budget just got a lot healthier.'],
            ['{brand} merch deal pays {amount}', "The merchandise sponsorship on {show} landed its {amount} deal. The sponsor was thrilled with the numbers. Every superstar's shirt seemed to be selling. The money will come in handy for the weeks ahead. It was a big win for the business side."],
            ['T-shirts and dollars for {brand}', "{brand} sold its way to {amount} in merchandise on {show}. The fans wanted a piece of their favorite superstars. The stands were cleared out by the end of the night. The sponsor is already talking about next time. The accountants couldn't be happier."],
            ['{brand} sponsorship pays off', "The merchandise sponsorship came through: {amount} for {brand}. Sales were strong all night long. The fans showed up with their wallets open. It's the kind of week every brand hopes for. The budget is in better shape now."],
        ],
        'merchandise_sponsorship.failed' => [
            ['Slow merch sales for {brand}', "{brand}'s merchandise push on {show} didn't move much. The stands were quiet most of the night. The sponsor walked away disappointed. There's no money from the deal this week. The brand will have to try something else."],
            ['{brand} merchandise sits on the shelves', "The fans at {show} kept their wallets closed. Boxes of shirts went back on the truck unopened. The sponsorship didn't pay out a cent. It was a frustrating night for the business side. Better sales will need a hotter roster."],
            ['Sponsorship stalls for {brand}', "{brand} ran its merchandise sponsorship on {show}, but sales were thin. The deal needed a big night, and it didn't get one. The sponsor won't be paying out this time. The budget stays where it was. There's always another week."],
            ['No merch money for {brand}', "{brand} came away from {show} with nothing from merchandise. The fans just weren't buying. The sponsor's terms were tough, and the sales fell short. It's a blow to the budget. The brand will have to look elsewhere for cash."],
        ],
        'ppv_hype.success' => [
            ['{brand} builds toward {ppv}', "{brand} spent part of {show} selling {ppv}, {ppv_weeks} away, and the crowd is buying. The video package hit all the right notes. The fans cheered every name on the card. Ticket sales should get a nice bump. The big night can't come soon enough."],
            ['{ppv} buzz is growing', 'The {ppv} hype on {show} got a big reaction. It is {ppv_weeks} away. The crowd started chanting for the event before the segment was over. Every rivalry on the brand is pointing toward it. The anticipation is building.'],
            ['All eyes on {ppv}', "{brand} reminded the fans that {ppv} is {ppv_weeks} away, and the arena roared. The promo showed the biggest stars and the biggest stakes. The fans are clearly ready. The weekly shows will build toward it from here. It's going to be a big night."],
            ['{brand} is ready for {ppv}', 'The road to {ppv} ran through {show}, and {brand} made it count. The hype hit the crowd at just the right moment. Superstars on the card made their intentions clear. The fans are counting down the days. The stage is set.'],
        ],
        'ppv_hype.failed' => [
            ["{ppv} hype doesn't land", "{brand} tried to sell {ppv} on {show}, but the crowd stayed quiet. It is {ppv_weeks} away. The video package didn't generate the buzz {brand} hoped for. The card may need a bigger hook. There's still time to fix it."],
            ['Fans not sold on {ppv} yet', 'The {ppv} promo on {show} came and went without much reaction. The fans seem to be waiting to see the card first. Hype only goes so far without big matches to back it up. {brand} has work to do. The next few shows matter.'],
            ['A slow build to {ppv}', "{brand} hyped {ppv}, {ppv_weeks} away, but it didn't catch on. The crowd was more interested in the matches in front of them. It's not panic time yet. A hot rivalry or a big title match could turn it around. The clock is ticking."],
            ['{brand} still needs to sell {ppv}', "The {ppv} hype on {show} missed. {brand} has {ppv_weeks} to fix it. The fans need a reason to buy in. Right now, they don't have one. The next few cards will have to provide it."],
        ],

        // New Superstar: the debut (success) or a quiet first look (failure).
        'new_superstar.success' => [
            ['{wrestler} makes their {brand} debut!', '{wrestler} made a first appearance on {brand} thanks to a New Superstar promo, and the crowd took notice. The entrance alone got people talking. First impressions matter in this business. This one was a good one. The roster has a new name to watch.'],
            ['Welcome to {brand}, {wrestler}', "{wrestler} was introduced to the {brand} fans on {show}. They're here to make an impact. The crowd gave a warm welcome. {wrestler} soaked it all in. The first match will be a big test."],
            ['{wrestler} arrives on {show}', "{brand}'s newest signing, {wrestler}, made a statement in their first appearance. The look, the attitude and the confidence all came through. The locker room was watching closely on the monitors. More than one superstar sized up the new arrival. This could be the start of something big."],
            ['New face, big reaction: {wrestler} debuts', 'The {brand} crowd got its first look at {wrestler} and liked what it saw. The reaction was louder than expected. A good debut can carry a superstar a long way. {wrestler} made the most of it. Now comes the hard part.'],
        ],
        'new_superstar.failed' => [
            ["{wrestler}'s introduction falls flat", "{brand} introduced {wrestler} on {show}, but the crowd didn't know what to make of them. The reaction was polite but quiet. It's not easy being the new face. {wrestler} will need a strong first match to change that. The talent is there."],
            ['A quiet first look at {wrestler}', "{wrestler}'s first {brand} segment didn't get much of a reaction. The crowd was saving its voice for the matches. A slow start isn't the end of the world. Plenty of stars started the same way. {wrestler} will get other chances."],
            ['{wrestler} still has to win the fans over', "The New Superstar promo for {wrestler} missed. They'll need a big first match. The crowd needs a reason to care. A win over a known name would help. The pressure is on."],
            ["Who's {wrestler}? {brand} fans aren't sure", "{wrestler}'s introduction on {show} came and went without much noise. The fans are still waiting to see what the fuss is about. The first impression didn't land. The second one has to. {wrestler} will want to make it count."],
        ],

        // Rival-targeting promos always make the WWE headline.
        'slander_promotion.succeeds' => [
            ["{brand}'s smear campaign against {rival_brand} succeeds!", "{brand} went after {rival_brand} with a smear campaign this week, and it stuck. {rival_brand}'s locker room will be answering questions for weeks. The papers ran with every word. Fans are taking sides, and the momentum has shifted. {rival_brand} will be looking for a way to hit back."],
            ['{brand} drags {rival_brand} through the mud', "The {brand} smear campaign hit its mark, and {rival_brand} looks bad this week. The stories were everywhere. {rival_brand}'s GM spent the week on damage control. The fans are talking about it on every forum. Round one goes to {brand}."],
            ['{rival_brand} reeling after {brand} attack ads', "{brand}'s campaign against {rival_brand} worked. The fans are talking, and not kindly. {rival_brand}'s superstars were peppered with questions all week. The damage will take time to repair. The war of words just heated up."],
            ['Dirty tricks pay off for {brand}', "{brand} went negative on {rival_brand}, and the fans bought every word. It wasn't pretty, but it was effective. {rival_brand} is on the back foot. The brand war has a new front. Expect a response."],
        ],
        'slander_promotion.fails' => [
            ["{brand}'s smear campaign against {rival_brand} fails!", '{brand} tried to smear {rival_brand} this week, but nobody bought it. The money is gone and {rival_brand} is laughing. The papers barely ran the story. The fans saw it for what it was. It made {brand} look more desperate than {rival_brand} ever did.'],
            ['{rival_brand} shrugs off {brand} smear', "The {brand} campaign against {rival_brand} didn't stick. {rival_brand} barely bothered to respond. The fans moved on within a day. It was money down the drain. {brand} will need a better plan."],
            ['{brand} wastes money on attack ads', "{brand}'s shots at {rival_brand} missed, and the fans moved on. The campaign never got any traction. {rival_brand}'s GM was reportedly amused. It's an embarrassing week for {brand}. Lesson learned, hopefully."],
            ['No damage done: {rival_brand} survives {brand} smear', "{brand} went after {rival_brand}, and {rival_brand} barely noticed. The stories fizzled out almost immediately. The fans weren't interested. {rival_brand} came through without a scratch. {brand} spent the money for nothing."],
        ],
        'slander_promotion.backfires' => [
            ["{brand}'s smear campaign against {rival_brand} backfires!", "{brand} ran one smear campaign too many against {rival_brand}. The fans turned on it, and it made {brand} look worse. There's such a thing as overdoing it. The papers have turned the story around. {rival_brand} couldn't have asked for a better week."],
            ['{brand} goes to the well once too often', 'Another smear campaign against {rival_brand}, and this time the fans turned on {brand}. The constant attacks have worn thin. The fans are siding with the underdog. {rival_brand} is enjoying every minute of it. {brand} needs to change course.'],
            ['Fans sick of {brand} attacks on {rival_brand}', "{brand}'s latest shot at {rival_brand} blew up in its face. The fans have had enough of the mudslinging. The backlash was swift and loud. {rival_brand} looks like the bigger brand for staying quiet. It's a self-inflicted wound."],
            ['{rival_brand} wins the war of words', "{brand} smeared {rival_brand} again, and the fans sided with {rival_brand}. One campaign too many turned the tide. The papers are now writing about {brand}'s desperation. {rival_brand} came out looking stronger. It's a big swing in the brand war."],
        ],
        'interference_promotion.disrupts' => [
            ["{brand} disrupts {rival_brand}'s show!", "{brand} sent {wrestler} to crash {rival_brand}'s show, and it worked: the segment fell apart. {wrestler} made it all the way to the ring before security caught up. The cameras caught every second of the chaos. {rival_brand} will be furious, and the damage bill won't be small. The war just went to another level."],
            ['{wrestler} invades {rival_brand}', "{wrestler} showed up uninvited on {rival_brand}'s show and caused chaos for {brand}. Nobody saw it coming. The {rival_brand} crowd didn't know whether to boo or cheer. By the time security arrived, the damage was done. {wrestler} is a hero back on {brand}."],
            ['Chaos on {rival_brand} as {wrestler} crashes the show', "{rival_brand}'s night went sideways when {wrestler} from {brand} hit the ring. Equipment was knocked over and the segment was ruined. Producers scrambled to get the show back on track. It was a massive statement from {brand}. The rivalry between the brands has never been hotter."],
            ['{brand} sends a message through {wrestler}', "{wrestler} ruined part of {rival_brand}'s show, and {brand} is loving it. The invasion was quick and effective. {rival_brand}'s GM is said to be livid. The fans are buzzing about it everywhere. {brand} won this round."],
        ],
        'interference_promotion.backfires' => [
            ["{brand}'s invasion of {rival_brand} backfires!", "{brand} sent {wrestler} to crash {rival_brand}'s show one time too many. The fans saw it coming, booed {wrestler} out of the building, and {brand} looks desperate. What used to be a shock is now a routine. {rival_brand} came off as the victim, and it worked in their favor. Some ideas get old fast."],
            ['{brand} goes to the well once too often', 'Another {brand} raid on {rival_brand}, and this time the crowd turned on {wrestler} before they reached the ring. Security barely had to lift a finger. The fans did the work for them. {brand} has overplayed its hand. The next invasion should wait a while.'],
            ['Fans sick of {brand} invading {rival_brand}', "{wrestler}'s latest trip to {rival_brand} blew up in {brand}'s face. The crowd was ready for it and let {wrestler} have it. The invasions have lost their surprise. {rival_brand} is enjoying the turnaround. {brand} needs a new trick."],
            ['{rival_brand} wins the night as {brand} overplays its hand', "{brand} sent {wrestler} after {rival_brand} again, and the fans sided with {rival_brand}. The boos were deafening. {wrestler} was escorted out to a chorus of jeers. It's a big win for {rival_brand}'s image. {brand} will be rethinking its strategy."],
        ],
        'interference_promotion.fails' => [
            ["{brand} fails to disrupt {rival_brand}'s show!", "{brand} sent {wrestler} to crash {rival_brand}'s show, but security handled it and the show went on. {wrestler} barely made it past the curtain. {rival_brand}'s fans cheered as security dragged them out. It was an expensive way to make the other brand look good. Better luck next time."],
            ['{wrestler} turned away at the {rival_brand} door', "{wrestler}'s attempt to invade {rival_brand} ended before it began. Security was waiting at the loading dock. The whole thing was over in seconds. {rival_brand}'s GM is surely having a laugh. {brand} will need a better plan."],
            ["{rival_brand} shuts down {brand}'s invasion", "{brand} sent {wrestler} after {rival_brand}'s show. It didn't work. {rival_brand} was ready and waiting. {wrestler} was thrown out before reaching the ring. It's a win for {rival_brand}'s security team."],
            ['Failed raid: {wrestler} gets nowhere on {rival_brand}', '{wrestler} tried to cause trouble for {rival_brand} and was escorted out. The crowd barely noticed the attempt. It was over before it started. {brand} paid for an invasion that never happened. That money could have been better spent.'],
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
        'backfires' => 'Backfired (overused)',
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
