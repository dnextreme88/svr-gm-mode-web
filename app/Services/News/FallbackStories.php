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
            ['Tickets on sale now for {event}!', '{event} is next on the calendar on {date}. Seats are going fast.'],
            ['{event} tickets are moving fast', 'The next stop is {event} on {date}, and the box office is busy.'],
            ['Get your seats for {event}', 'Tickets for {event} on {date} are on sale now. Expect a sold-out arena.'],
            ['The road leads to {event}', 'All eyes turn to {event} on {date}. Tickets are on sale now.'],
        ],
        // Weeks recorded before both shows were named: Raw alone.
        'tickets.show' => [
            ['Tickets on sale now for {event}', '{event} comes to town on {date}. Get your seats before they are gone.'],
            ['Next up: {event} on {date}', 'Tickets for {event} on {date} are on sale now.'],
            ['{event} is coming to your city', 'The box office is open for {event} on {date}.'],
            ['Book your seat for {event}', 'Tickets for {date} are on sale. Who knows what {event} has in store?'],
        ],
        // No PPV next: both weekly shows, each with its date.
        'tickets.shows' => [
            ['Tickets on sale for Raw and Smackdown', "Monday Night Raw comes to town on {raw_date} and Smackdown on {smackdown_date}. Buy your tickets for these shows and don't miss out on the action!"],
        ],

        // Brand headline: the top champion's week, or the roster at HQ.
        'spotlight.champion' => [
            ['{champion} takes the {title} on the road', '{champion} spent the week in {city}, meeting fans and showing off the {title}. {budget}'],
            ['{champion} and the {title} visit {city}', 'The {title} holder spent a great week with the fans in {city}. {budget}'],
            ['{city} welcomes {champion}', '{champion} brought the {title} to {city}, signing autographs and posing for photos all week. {budget}'],
            ['A champion of the people: {champion} in {city}', '{champion} spent the week in {city} with the fans and the {title}. {budget}'],
        ],
        'spotlight.hq' => [
            ['{brand} superstars hang out at WWE HQ', 'With no champions to send on tour, the {brand} roster spent the week at WWE headquarters, trading stories and pranks. {budget}'],
            ['Fun and games at WWE HQ for {brand}', 'The {brand} locker room spent the week at headquarters, poking fun at each other. {budget}'],
            ['{brand} roster bonds at headquarters', 'A quiet week for {brand} meant a lot of laughs at WWE HQ. {budget}'],
            ['Pranks and stories at WWE HQ', 'The {brand} superstars spent the week at headquarters, and nobody was safe from a practical joke. {budget}'],
        ],
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
            ['{wrestler} still riding high', "{wrestler}'s last match was a win over {opponent} {how} at {show}, {when}."],
            ['Looking back: {wrestler} beat {opponent}', '{wrestler} last stepped in the ring at {show} {when} and beat {opponent} {how}.'],
            ['{wrestler} wants more after beating {opponent}', '{when} at {show}, {wrestler} beat {opponent} {how}, and they are eager to go again.'],
            ['{wrestler} is on the rise', '{wrestler} beat {opponent} {how} at {show} {when}. {brand} should keep them busy.'],
        ],
        'recap.lost' => [
            ['{wrestler} looking to bounce back', "{wrestler}'s last match was a loss to {opponent} {how} at {show}, {when}."],
            ['Looking back: {opponent} beat {wrestler}', '{wrestler} last stepped in the ring at {show} {when} and lost to {opponent} {how}.'],
            ['{wrestler} wants a rematch with {opponent}', '{when} at {show}, {opponent} beat {wrestler} {how}. {wrestler} has not forgotten.'],
            ['{wrestler} hungry after a loss', '{opponent} beat {wrestler} {how} at {show} {when}. {wrestler} wants back in the ring.'],
        ],
        'recap.none' => [
            ['{wrestler} waiting for a first match', '{wrestler} has yet to wrestle on {brand} and is ready whenever the GM calls.'],
            ['{wrestler} is ready to go', '{wrestler} has not had a match on {brand} yet, and they are getting restless.'],
            ['When will {wrestler} debut in the ring?', 'The {brand} fans are still waiting to see {wrestler} in action.'],
            ['{wrestler} keeps training', 'Still waiting on a first {brand} match, {wrestler} spent the week in the gym.'],
        ],
        'injuries.clean' => [
            ['A clean bill of health on {brand}', 'No {brand} superstar has been hurt in the last 6 months. The roster is taking care of itself, and the GM has booked it well.'],
            ['{brand} stays injury-free', 'Not a single {brand} injury in 6 months. Smart booking and good habits are paying off.'],
            ['The {brand} trainer has a quiet job', 'Six months without an injury on {brand}. Credit to the wrestlers and to the GM.'],
            ['Healthy roster, happy {brand}', '{brand} has gone 6 months without an injury, thanks to careful booking.'],
        ],
        'injuries.hurt' => [
            ['{count} injuries on {brand} in 6 months', '{brand} has had {count} injuries in the last 6 months, most recently {wrestler} ({injury}). Rest anyone at 80% fatigue or more, and use Inactive Superstar to give tired wrestlers a week off.'],
            ['The {brand} trainer is busy', '{count} injuries in 6 months, the latest {wrestler} ({injury}). Fewer Hardcore, Ladder and TLC matches for tired wrestlers would help.'],
            ['{brand} needs to protect its roster', 'With {count} injuries in 6 months, most recently {wrestler} ({injury}), the GM should lean on lighter Normal matches and keep fatigue under 80%.'],
            ['Injury watch on {brand}', '{wrestler} ({injury}) is the latest of {count} {brand} injuries in 6 months. Booking tired wrestlers in gimmick matches is a risk.'],
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
                'locker.unhappy' => 'Unsatisfied or Upset wrestlers',
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
