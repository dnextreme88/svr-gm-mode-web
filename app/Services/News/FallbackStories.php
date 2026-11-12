<?php

namespace App\Services\News;

/**
 * Quiet-week news (house rule): when a week leaves a card empty, these fill
 * it, so the News page always has something to read. One variant is drawn
 * with equal odds from the save's seeded RNG.
 *
 * `{placeholders}`: brand, event, date, champion, title, city, budget,
 * wrestler, opponent, how, show, when, count, injury, names, n.
 */
class FallbackStories
{
    /**
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const STORIES = [
        // WWE headline: tickets for whatever airs next.
        'tickets.ppv' => [
            ['Tickets on sale now for {event}!', "{event} is next on the calendar on {date}. Seats are going fast. Every pay-per-view brings out the biggest matches and the hottest rivalries. The card is still taking shape, and every show between now and then will add to it. Don't be the one watching from home."],
            ['{event} tickets are moving fast', "The next stop is {event} on {date}, and the box office is busy. Fans have been calling all week asking what's on the card. The answer will come over the next few shows. One thing is certain: the biggest names will be there. Get your seats while you still can."],
            ['Get your seats for {event}', "Tickets for {event} on {date} are on sale now. Expect a sold-out arena. Pay-per-view nights are the ones people remember for years. The rivalries will boil over and the titles will be on the line. This is one you'll want to see in person."],
            ['The road leads to {event}', "All eyes turn to {event} on {date}. Tickets are on sale now. The weekly shows are building toward it, one match at a time. Every superstar on the roster wants a spot on the card. Make sure you're in the building when it happens."],
        ],
        // Weeks recorded before both shows were named: Raw alone.
        'tickets.show' => [
            ['Tickets on sale now for {event}', '{event} comes to town on {date}. Get your seats before they are gone. The roster is ready and the card is coming together. Every week brings a new surprise. Be there when it happens.'],
            ['Next up: {event} on {date}', "Tickets for {event} on {date} are on sale now. The box office opened this morning, and the lines formed early. Rivalries, title matches and surprises are all on the table. A live show is a different experience altogether. Don't miss it."],
            ['{event} is coming to your city', 'The box office is open for {event} on {date}. The superstars are already on the road. A full arena makes every match better. The fans in the building set the tone for the whole night. Grab a ticket and be part of it.'],
            ['Book your seat for {event}', "Tickets for {date} are on sale. Who knows what {event} has in store? The card is still being built, and anything can happen. That's half the fun of being there live. Get your seats today."],
        ],
        // No PPV next: both weekly shows, each with its date.
        'tickets.shows' => [
            ['Tickets on sale for Raw and Smackdown', "Monday Night Raw comes to town on {raw_date} and Smackdown on {smackdown_date}. Buy your tickets for these shows and don't miss out on the action! Both brands have plenty to prove this week. Title pictures are shifting and rivalries are heating up. Two nights, two arenas, one goal: winning the war between the brands."],
        ],

        // Brand headline: the top champion's week, or the roster at HQ.
        'spotlight.champion' => [
            ['{champion} takes the {title} on the road', '{champion} spent the week in {city}, meeting fans and showing off the {title}. The lines for autographs stretched around the block. Kids brought homemade belts and asked for photos. The champion stayed until every last fan had a picture. It was a reminder of what the gold means to the people who cheer for it. {budget}'],
            ['{champion} and the {title} visit {city}', "The {title} holder spent a great week with the fans in {city}. {champion} visited schools, local gyms and a children's hospital along the way. The belt went everywhere the champion did. Everybody who met them walked away with a story to tell. A quiet week in the ring turned into a big week for the brand. {budget}"],
            ['{city} welcomes {champion}', "{champion} brought the {title} to {city}, signing autographs and posing for photos all week. The local papers ran pictures on the front page. Fans who couldn't get tickets to the shows got a chance to meet a champion up close. It's the kind of week that builds loyalty for years. The champion promised to come back soon. {budget}"],
            ['A champion of the people: {champion} in {city}', "{champion} spent the week in {city} with the fans and the {title}. There were radio interviews in the morning and meet-and-greets every afternoon. The champion even spent an evening at a local wrestling school, giving tips to the students. Word of the visit spread fast. The brand couldn't ask for a better ambassador. {budget}"],
        ],
        'spotlight.hq' => [
            ['{brand} superstars hang out at WWE HQ', 'With no champions to send on tour, the {brand} roster spent the week at WWE headquarters, trading stories and pranks. Somebody filled a locker with packing peanuts, and nobody has owned up to it yet. The veterans told stories about the old days on the road. The younger superstars listened to every word. It was a good week for team spirit. {budget}'],
            ['Fun and games at WWE HQ for {brand}', 'The {brand} locker room spent the week at headquarters, poking fun at each other. A ping-pong tournament broke out in the break room and got very competitive. The office staff placed bets on the final. The superstars laughed more than they have in weeks. Sometimes a quiet week is exactly what a roster needs. {budget}'],
            ['{brand} roster bonds at headquarters', "A quiet week for {brand} meant a lot of laughs at WWE HQ. The superstars watched old tapes together and argued about the greatest matches ever. A few of them ended up in the training ring, trying out new moves. The office staff got a close look at the locker room's sense of humor. Everybody left closer than they arrived. {budget}"],
            ['Pranks and stories at WWE HQ', 'The {brand} superstars spent the week at headquarters, and nobody was safe from a practical joke. Shoes went missing, chairs were rigged and the coffee was spiked with hot sauce. The culprits are still at large. The camaraderie in the locker room has never been better. The office just hopes the furniture survives. {budget}'],
        ],
        // Pieces dropped into {budget} in the spotlight stories above.
        'budget.good' => [
            ['', 'The {brand} books look healthy at {money}.'],
            ['', '{brand} has {money} in the bank, and the office is happy.'],
            ['', 'Business is good: {brand} is sitting on {money}.'],
            ['', 'With {money} to spend, {brand} is in good shape.'],
        ],
        'budget.bad' => [
            ['', 'Money is tight, though: {brand} is down to {money}, and the GM needs to act fast.'],
            ['', 'The bad news: {brand} has only {money} left. The GM should cut costs or run some money promos.'],
            ['', '{brand} is down to {money}. Cheaper matches and Advertising promos would help.'],
            ['', 'The office is worried: {brand} has just {money}. The GM has to turn that around.'],
        ],

        // Brand stories: a superstar's last match, then the injury report.
        'recap.won' => [
            ['{wrestler} still riding high', "{wrestler}'s last match was a win over {opponent} {how} at {show}, {when}. It was a performance that got people talking in the back. The crowd was behind {wrestler} from the opening bell. A win like that builds confidence for the next one. The question now is who steps up next."],
            ['Looking back: {wrestler} beat {opponent}', "{wrestler} last stepped in the ring at {show} {when} and beat {opponent} {how}. It wasn't easy, and {opponent} made them work for every inch. In the end, {wrestler} had a little more left in the tank. That kind of grit doesn't go unnoticed. {wrestler} is ready for the next challenge."],
            ['{wrestler} wants more after beating {opponent}', '{when} at {show}, {wrestler} beat {opponent} {how}, and they are eager to go again. The win has {wrestler} feeling like they can beat anyone on the roster. Momentum is a powerful thing in this business. The fans have noticed too. All {wrestler} needs is a spot on the next card.'],
            ['{wrestler} is on the rise', '{wrestler} beat {opponent} {how} at {show} {when}. {brand} should keep them busy. Wins like that are how careers get built. The locker room is starting to see {wrestler} as a real threat. The next few weeks could be big ones.'],
        ],
        'recap.lost' => [
            ['{wrestler} looking to bounce back', "{wrestler}'s last match was a loss to {opponent} {how} at {show}, {when}. It's not the result anyone wanted. {wrestler} has been in the gym every day since, working on what went wrong. Losses sting, but they also teach. The next match will show what {wrestler} learned."],
            ['Looking back: {opponent} beat {wrestler}', "{wrestler} last stepped in the ring at {show} {when} and lost to {opponent} {how}. It was a close match that could have gone either way. {opponent} got the break at the right moment. {wrestler} hasn't stopped thinking about it. A rematch would suit them just fine."],
            ['{wrestler} wants a rematch with {opponent}', '{when} at {show}, {opponent} beat {wrestler} {how}. {wrestler} has not forgotten. The loss has been eating at {wrestler} all week. Nobody likes being the one whose shoulders hit the mat. {wrestler} wants another shot, and wants it soon.'],
            ['{wrestler} hungry after a loss', "{opponent} beat {wrestler} {how} at {show} {when}. {wrestler} wants back in the ring. Sitting on a loss is the worst feeling in this business. {wrestler} has been telling anyone who will listen that it won't happen again. The next opponent had better be ready."],
        ],
        'recap.none' => [
            ['{wrestler} waiting for a first match', "{wrestler} has yet to wrestle on {brand} and is ready whenever the GM calls. Training has been going well. The gear is packed and the bags are by the door. Every week {wrestler} checks the card, hoping to see their name. That first match can't come soon enough."],
            ['{wrestler} is ready to go', '{wrestler} has not had a match on {brand} yet, and they are getting restless. Watching from the back is starting to wear thin. The rest of the roster has been getting their chances. {wrestler} just wants one of their own. A good first impression could change everything.'],
            ['When will {wrestler} debut in the ring?', 'The {brand} fans are still waiting to see {wrestler} in action. Some of them have been asking about it on the signs they bring to the shows. A debut is a big moment for any superstar. {wrestler} wants to make it count. Hopefully the wait is nearly over.'],
            ['{wrestler} keeps training', 'Still waiting on a first {brand} match, {wrestler} spent the week in the gym. The cardio is up and the moves are sharp. Nobody on the roster has worked harder behind the scenes. Sooner or later, that work will show in the ring. {wrestler} intends to be ready when it does.'],
        ],
        'injuries.clean' => [
            ['A clean bill of health on {brand}', "No {brand} superstar has been hurt in the last 6 months. The roster is taking care of itself, and the GM has booked it well. The trainer has spent more time on prevention than treatment. Tired superstars have been rested, and risky matches have gone to fresh legs. It's a record any brand would be proud of. Long may it continue."],
            ['{brand} stays injury-free', 'Not a single {brand} injury in 6 months. Smart booking and good habits are paying off. The roster is fresh and every superstar is available. That gives the GM options every single week. Keeping it that way will take the same care.'],
            ['The {brand} trainer has a quiet job', "Six months without an injury on {brand}. Credit to the superstars and to the GM. The trainer's room has been more of a hangout than a hospital. Fatigue has been kept in check and rest weeks have been well used. It's the healthiest roster in the company."],
            ['Healthy roster, happy {brand}', "{brand} has gone 6 months without an injury, thanks to careful booking. A healthy roster is a happy roster. Nobody is sitting on the sidelines waiting to come back. Every rivalry and title picture can play out the way it was meant to. That's how a brand stays on top."],
        ],
        'injuries.hurt' => [
            ['{count} injuries on {brand} in 6 months', "{brand} has had {count} injuries in the last 6 months, most recently {wrestler} ({injury}). Rest anyone at 80% fatigue or more, and use Inactive Superstar to give tired superstars a week off. The trainer's room has been busy, and the roster is feeling it. Every injury takes a name off the card and a story out of the show. A little caution now saves a lot of trouble later."],
            ['The {brand} trainer is busy', '{count} injuries in 6 months, the latest {wrestler} ({injury}). Fewer Hardcore, Ladder and TLC matches for tired superstars would help. Those matches take a heavy toll on bodies that are already worn down. The fatigue bars tell the story before the injury does. Booking with them in mind will keep more superstars on their feet.'],
            ['{brand} needs to protect its roster', "With {count} injuries in 6 months, most recently {wrestler} ({injury}), the GM should lean on lighter Normal matches and keep fatigue under 80%. The roster can't keep losing people at this rate. Every week a superstar spends on the sidelines is a week the fans miss them. The weekly rest helps, but only if they're not pushed too hard. A careful month would make a big difference."],
            ['Injury watch on {brand}', '{wrestler} ({injury}) is the latest of {count} {brand} injuries in 6 months. Booking tired superstars in gimmick matches is a risk. The trainer has been sounding the alarm for weeks. Fatigue builds up faster than most people think. Rest days and lighter matches are the cure.'],
        ],

        // Ticker: morale and contracts.
        'locker.unhappy' => [
            ['Unhappy on {brand}: {names}', ''],
            ['{names} are not happy with {brand} management', ''],
        ],
        'locker.expiring' => [
            ['Contracts running out on {brand}: {names}', ''],
            ['{names}: {brand} deals expire within {n} weeks', ''],
        ],
        'locker.calm' => [
            ['All quiet in the {brand} locker room', ''],
            ['The {brand} roster is happy and under contract', ''],
        ],
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
     * Every quiet-week story for `/gm/docs`, grouped by the card it fills.
     *
     * @return list<array{key: string, name: string, situations: list<array{situation: string, label: string, extra: bool, variants: list<array{headline: string, summary: string}>}>}>
     */
    public static function catalog(): array
    {
        $groups = [
            ['tickets', 'WWE headline: tickets on sale', [
                'tickets.ppv' => 'A PPV airs next (named)',
                'tickets.shows' => 'No PPV next: both weekly shows, Raw and Smackdown, each with its date',
            ]],
            ['spotlight', 'Brand headline: a champion on tour, or WWE HQ', [
                'spotlight.champion' => 'The top champion tours a city (world, then second tier, tag, special)',
                'spotlight.hq' => 'No champions: a week at WWE HQ',
                'budget.good' => 'Budget line, above the $500,000 low-cash mark',
                'budget.bad' => 'Budget line, at or below the $500,000 low-cash mark',
            ]],
            ['recap', 'Brand story: a superstar’s last match', [
                'recap.won' => 'They won it',
                'recap.lost' => 'They lost it',
                'recap.none' => 'Nobody on the roster has wrestled yet',
            ]],
            ['injuries', 'Brand story: the injury report (last 6 months)', [
                'injuries.clean' => 'No injuries',
                'injuries.hurt' => 'Injuries, with booking advice',
            ]],
            ['locker', 'Ticker: the locker room', [
                'locker.unhappy' => 'Unsatisfied or Upset superstars',
                'locker.expiring' => 'Contracts about to run out',
                'locker.calm' => 'All quiet',
            ]],
        ];

        $catalog = [];

        foreach ($groups as [$groupKey, $name, $situations]) {
            $rows = [];

            foreach ($situations as $key => $label) {
                $rows[] = [
                    'situation' => $key,
                    'label' => $label,
                    'extra' => false,
                    'variants' => array_map(fn (array $v): array => ['headline' => $v[0], 'summary' => $v[1]], self::variants($key)),
                ];
            }

            $catalog[] = ['key' => $groupKey, 'name' => $name, 'situations' => $rows];
        }

        return $catalog;
    }
}
