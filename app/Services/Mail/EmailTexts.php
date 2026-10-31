<?php

namespace App\Services\Mail;

use App\Support\Rng\Rng;

/**
 * Email bodies (house rule, 2026-10-31): two or three short paragraphs per
 * email, with several variants per situation drawn with equal odds from the
 * save's seeded RNG, so the same email doesn't read the same every time.
 * Subjects stay fixed; only the bodies vary.
 *
 * `{placeholders}`: name, brand, rival, weeks, n, injury, severity, amount,
 * season, to, opening.
 */
class EmailTexts
{
    /**
     * @var array<string, list<string>>
     */
    private const TEXTS = [
        // JR / Tazz: the season starts.
        'welcome.first' => [
            "Welcome to {brand}. The roster is set and the titles have their first champions, so the rest is up to you.\n\nIf you ever need help, check your email. I'll keep you posted on contracts that are running out, injuries, unhappy superstars, upcoming pay-per-views and anything else you should know about.\n\nLet's give these people a show they'll never forget.",
            "Welcome aboard. {brand} is yours now: a full roster, fresh champions and fifty-two weeks to make your mark before WrestleMania.\n\nIf you ever need help, check your email. I'll be watching every show from ringside and I'll let you know about expiring contracts, injuries, unhappy superstars and the pay-per-views coming up.\n\nThe fans are already lining up. Don't keep them waiting.",
            "So you're the one running {brand}. Good. The titles are around the right waists, the locker room is full and the cameras are ready to roll.\n\nIf you ever need help, check your email. I'll keep you up to date on contracts, injuries, morale and every pay-per-view on the calendar.\n\nNow go build something special.",
        ],
        'welcome.next' => [
            "WrestleMania is behind us and season {season} starts now. Same {brand}, new year: the calendar starts over and the road leads to WrestleMania again.\n\nAs always, if you need help, check your email. I'll keep you posted.\n\nLast season is in the record books. Let's make this one bigger.",
            "Season {season} is here. The confetti from WrestleMania is barely swept up, and {brand} is already back on the road.\n\nIf you need help, check your email. Contracts, injuries, unhappy superstars, the next pay-per-view: I'll keep you in the loop on all of it.\n\nNew year, same goal. Let's go.",
            "Welcome to season {season}. The calendar resets, the rosters have been shaken up by the re-draft, and every title is up for grabs again.\n\nAs ever, check your email if you need help. I'll be right here.\n\nThe fans remember what we did last year. Time to top it.",
        ],

        // JR / Tazz: no Interference for a long stretch.
        'interference_reminder' => [
            "We haven't sent anyone to crash {rival}'s show in {weeks}. An Interference promo costs money and only works half the time, but when it lands it makes the news.\n\nThe fans love a surprise, and nothing gets the locker room talking like one of ours storming the other brand's ring.\n\nYou'll find it in the promo slots on your next card.",
            "It's been {weeks} since we last paid {rival} a visit. They're getting comfortable over there, and comfortable rivals are dangerous rivals.\n\nAn Interference promo isn't cheap and it's a coin flip whether it works, but when it does, the whole company hears about it.\n\nIt's in the promo slots on your next card if you want it.",
            "{weeks} and not a single surprise visit to {rival}. The fans are starting to forget there's a war on.\n\nSend somebody over the barricade with an Interference promo. Half the time security stops them, but the other half it's the biggest story of the week.\n\nYou'll find it in the promo slots on your next card.",
        ],

        // JR / Tazz: no Slander for a long stretch.
        'slander_reminder' => [
            "It's been {weeks} since we said a word about {rival}. They're getting a free ride in the press while we keep quiet.\n\nA Slander promo costs money and only works half the time, and if you run it twice in four weeks it backfires on us. But when it lands, {rival} spends the week answering questions instead of selling tickets.\n\nYou'll find it in the promo slots on your next card.",
            "{weeks} without a single shot at {rival}. Their GM has been running his mouth, and we've been too polite to answer.\n\nA Slander promo is a gamble: half the time nobody buys it. But when it sticks, it hurts them where it counts.\n\nJust don't overdo it. Twice in four weeks and it blows up in our faces.",
            "We haven't taken a swipe at {rival} in {weeks}. The fans love a war of words, and right now it's one-sided.\n\nRun a Slander promo when you get the chance. It's a coin flip whether it lands, and using it twice in four weeks backfires, but a good smear campaign can knock them off their game for a while.\n\nIt's in the promo slots on your next card.",
        ],

        // JR / Tazz: no trade offer for a long stretch.
        'trade_reminder' => [
            "We haven't made {rival} a trade offer in {weeks}. Sometimes the best way to freshen up the roster is to swap someone who isn't working out for someone who will.\n\nThe Trade page shows you how likely their GM is to say yes before you send anything, so it doesn't cost us a thing to look.\n\nChampions and seriously injured superstars can't be traded, and both rosters have to stay at 30 or under.",
            "It's been {weeks} since we last talked trade with {rival}. Their GM won't call us, so if we want something from that roster, we'll have to ask.\n\nCheck the Trade page: you can offer up to five for five, and it shows you the odds before you hit send.\n\nA good trade can change a whole season.",
            "{weeks} without a trade. Is there anybody on {rival} you've had your eye on?\n\nTheir GM values specialists, Legends, hot win streaks and title history, so put together something that looks good to them. The Trade page shows your chances as you build the offer.\n\nWorst case, they say no.",
        ],

        // JR / Tazz: no free agent signed for a long stretch.
        'free_agent_reminder' => [
            "We haven't signed anyone from Free Agency in {weeks}. There's talent sitting in that pool waiting for a call.\n\nKeep an eye out for superstars with match specialties: book them in their kind of match and they get an edge. And the Legends can't be drafted, so Free Agency is the only place to get them.\n\nIf we've got room on the roster and money in the bank, it's worth a look.",
            "It's been {weeks} since our last Free Agency signing. The roster could use some new blood.\n\nSpecialists are worth their weight in gold: put them in a Hardcore, Ladder or Submission match that suits them and they've got the edge. Legends are only ever in Free Agency, and the fans go wild for them.\n\nTake a look when you've got a spot open.",
            "{weeks} without signing a free agent. I know the budget is tight some weeks, but there are some real gems out there.\n\nLook for superstars with match specialties, who get an edge in their kind of match, and for the Legends, who you can only sign from Free Agency.\n\nNew signings join the roster the week after you sign them.",
        ],

        // JR / Tazz: money.
        'low_cash' => [
            "We're down to {amount}. Contracts and match costs come out every week, so it won't last long at this rate.\n\nAdvertising and Merchandise Sponsorship promos bring money in, and cheaper match types help. If we go into the red for too long, the board will make us choose.\n\nI'd rather we fix this before they do.",
            "I just saw the books: {amount} left. Between salaries and the price of every match on the card, that money is going out fast.\n\nA couple of Advertising or Merchandise Sponsorship promos would buy us time, and the cheaper match types won't hurt the show as much as you'd think.\n\nStay out of the red, and the board stays off our backs.",
            "Bad news from accounting: {brand} is down to {amount}. At this rate we'll be in the red within a few weeks.\n\nMoney promos (Advertising, Merchandise Sponsorship) and cheaper match types are the quickest fixes. Releasing someone we don't use is another.\n\nLet's sort it out before the board starts asking questions.",
        ],
        'deficit' => [
            "We've been in the red for {weeks} now, and the board has run out of patience. They want a plan before the next show airs.\n\nYou can run Advertising or Merchandise promos, downgrade to cheaper match types, or release roster members. Pick one, and pick it fast.\n\nI've seen GMs lose their jobs over less.",
            "{weeks} in the red. The board called this morning, and it wasn't a friendly call.\n\nThey want to see a plan: money promos (Advertising or Merchandise), cheaper match types across the card, or releases to cut the payroll. Nothing airs until you choose.\n\nWe can dig out of this, but only if we start now.",
            "The board wants a plan. We've spent {weeks} in the red, and they won't sign off on another show until they see how we're getting out of it.\n\nYour options are money promos (Advertising or Merchandise), cheaper match types, or releasing roster members.\n\nWhatever you pick, make it count.",
        ],

        // JR / Tazz: contracts that ran out.
        'contract_expired' => [
            "These contracts ran out this week. Each wrestler has been released to Free Agency.\n\nIf you want any of them back, they're on the Free Agency page, but the other brand can sign them too.",
            "We lost some people this week: these contracts ran out, and each wrestler has been released to Free Agency.\n\nYou can still try to sign them back from Free Agency, but you won't be the only one asking.",
            "The following contracts expired this week, and each wrestler has been released to Free Agency.\n\nAny titles they held are vacant now. If you want someone back, check the Free Agency page before the other brand does.",
        ],

        // JR / Tazz: a defection attempt.
        'defection' => [
            "{name} has had enough and wants out. Word in the locker room is they've been on the phone with {to} all week.\n\nIf {to} has room, {name} is gone. If not, they'll sulk in the back for a couple of weeks before they're ready to work again.\n\nAn unhappy superstar is a flight risk. Keep an eye on the morale of everyone else.",
            "We've got a problem. {name} walked into my office today and told me straight: they want to go to {to}.\n\nIf {to} has a spot open, there's nothing we can do. If they're full, {name} will sit out a couple of weeks instead.\n\nThis is what happens when the locker room isn't happy. Let's not let it spread.",
            "{name} is trying to jump ship to {to}. They've been unhappy for a while, and it looks like they've finally made up their mind.\n\nIf {to} can fit them on the roster, {name} will be gone by next week. Otherwise they'll refuse to work for a couple of weeks.\n\nWatch the morale on the rest of the roster before anyone else gets ideas.",
        ],

        // Rival GM: trade answers (the line before the names).
        'trade.accepted' => [
            'I accept your trade. The roster change is effective immediately.',
            "You've got yourself a deal. I accept your trade, and the roster change is effective immediately.",
            "Fine, I accept your trade. The roster change is effective immediately. Don't come crying to me if it doesn't work out.",
        ],
        'trade.declined' => [
            'I decline your trade.',
            'Nice try, but I decline your trade.',
            "I've looked it over, and I decline your trade. You'll have to do better than that.",
        ],

        // Rival GM: the player's Slander.
        'slander.success' => [
            "I saw what you pulled with that smear campaign against {rival}. Cute. Enjoy it while it lasts.\n\nThe fans are buying it for now, and my phone hasn't stopped ringing. But I don't forget, and I will get you back for this.\n\nYou just made an enemy.",
            "So that's how you want to play it? A smear campaign against {rival}, all over the papers.\n\nIt worked, I'll give you that. My locker room is rattled and the network wants answers. But you've opened a door you can't close, and I will get you back for this.\n\nSleep with one eye open.",
            "Congratulations. Your little smear campaign made {rival} look bad all week, and everybody's talking about it.\n\nRemember this moment, because it's the last time you'll be smiling. I will get you back for this, and I won't need to lie to do it.\n\nSee you on the battlefield.",
        ],
        'slander.failure' => [
            "Was that smear campaign supposed to hurt {rival}? The whole locker room had a good laugh at it.\n\nNobody bought a word of it. Thanks for the free entertainment, and for wasting your money.\n\nMaybe stick to wrestling.",
            "I read your smear campaign against {rival}. Then I read it to the boys in the back. We had a good laugh at it.\n\nThe fans didn't buy it, the press didn't buy it, and you paid for the privilege of looking foolish.\n\nBetter luck next time.",
            "That smear campaign was the best thing to happen to {rival} all week. Everyone had a good laugh at it, and it made you look desperate.\n\nKeep wasting your money like that and I won't have to do a thing to beat you.\n\nThanks for the laugh.",
        ],

        // Rival GM: the player's Interference.
        'interference.success' => [
            "Do you have any idea what your little stunt cost me? Your guys stormed my show, wrecked the ring, tore up the announce table and sent half my production crew running for cover. I've got repair bills, refunds and an angry network on the phone, and every last cent of it has your name on it.\n\nThe fans loved it, I'll give you that. They won't be cheering when I return the favour. You started this war, and I promise you {brand} will pay for it with interest.\n\nWatch your back.",
            "You think that was funny? Your thugs hijacked {rival} on live television. The ring is in pieces, the pyro rig is busted and I'm refunding half the front row.\n\nThat's money out of my pocket, and I always collect. Every dollar you cost me tonight, {brand} will pay for it with interest.\n\nThis isn't over. It's barely started.",
            "I just got the bill for the damage your people did to my show, and I nearly put my fist through the wall. Broken equipment, injured crew, a network breathing down my neck.\n\nYou wanted my attention? You've got it. I'm coming for {brand}, and you'll pay for it with interest.\n\nEnjoy the headlines while you can.",
        ],
        'interference.failure' => [
            "I heard you tried to send your boys onto {rival} this week. My security had them in a headlock before they got past the curtain, and the cameras caught every second of it. You paid for that, by the way. I didn't.\n\nThe locker room hasn't stopped laughing, and neither have I. Next time you want to crash my show, at least make it worth watching.",
            "Was that supposed to be an invasion? Your guy got about three steps down the ramp before my security dragged him out by his boots. The crowd booed him all the way to the parking lot.\n\nThe locker room hasn't stopped laughing. Thanks for the free entertainment, and for footing the bill.\n\nTry again sometime. Please.",
            "Nice try sending your people to {rival}. They never made it to the ring: my security was waiting for them, and they ended up thrown out the back door in front of a sold-out crowd.\n\nThe locker room hasn't stopped laughing, and I've already got the replay saved for our next show.\n\nThat was your money well spent. For me.",
        ],

        // JR / Tazz: the rival's Slander against us.
        'rival_slander.success' => [
            "{rival} spent the week running a smear campaign against {brand}, and it's getting traction. The fans are talking about it.\n\nThe papers picked it up and the boys in the back are reading every word. We need a strong show to answer them.\n\nGive the fans something better to talk about.",
            "I don't like reporting this, but {rival}'s smear campaign against {brand} is working. It's all over the wrestling news this week.\n\nThe best answer is a great show: big matches, a hot main event, a few surprises. Let the work speak louder than their lies.\n\nWe've been through worse.",
            "{rival} ran a smear campaign against {brand} this week, and some of it stuck. The fans are asking questions.\n\nWe can't take it back, but we can bury it. Put on the best show you can, and let the headlines move on.\n\nThey'll regret starting this.",
        ],
        'rival_slander.failure' => [
            "{rival} ran a smear campaign against {brand} this week. It fell flat: nobody bought it, and they paid good money for nothing.\n\nThe locker room thought it was hilarious. If anything, it fired our people up.\n\nLet's keep giving them reasons to be jealous.",
            "Good news for once: {rival}'s smear campaign against {brand} went nowhere. The fans saw right through it.\n\nThey paid good money to make themselves look petty. I couldn't have planned it better.\n\nKeep doing what you're doing.",
            "{rival} tried to smear {brand} this week and it backfired on them. Nobody bought it, and they paid good money for the embarrassment.\n\nThe fans are on our side. Let's keep it that way.\n\nI'll take a free win any day.",
        ],

        // Superstars: contract and injury notices.
        'contract_reminder' => [
            "Just a heads-up: my contract runs out in {weeks}. If you want me to stay on {brand}, we need to talk about a new deal soon.\n\nI've given this brand everything I've got, and I'd like to keep doing it. But I've got bills to pay, and I won't wait forever.\n\nOtherwise I'll be testing the free agent market.",
            "My contract is up in {weeks}, and nobody from the office has called me yet.\n\nI like it here. The locker room is good and the fans are behind me. But if there's no new deal on the table soon, I'll have to listen to other offers.\n\nYour move.",
            "I wanted to let you know my deal with {brand} runs out in {weeks}.\n\nI want to stay, I really do. Let's sit down and work out a new contract before it's too late.\n\nIf I don't hear from you, I'll be testing the free agent market.",
        ],
        'injury_returning' => [
            "Rehab is going well. The doctors say I'll be cleared to compete in {weeks}.\n\nI've been in the gym every day, and I feel stronger than before I got hurt. I've been watching every show from home, and I've got a few ideas about who I'd like to face first.\n\nKeep a spot open for me on the card.",
            "Quick update from the trainer's room: I'll be cleared to compete in {weeks}.\n\nIt's been a long road, and sitting on the sidelines nearly drove me crazy. The fans have been great, though. I've read every letter.\n\nI'm coming back hungry. Make sure there's a match waiting for me.",
            "The doctors gave me good news today: I'll be cleared to compete in {weeks}.\n\nI won't lie, there were days I wondered if I'd come back at all. But I'm almost there, and I'm ready to pick up right where I left off.\n\nKeep a spot open for me on the card.",
        ],

        // Superstars: morale complaints. Each is followed by a level line.
        'morale.losing_streak' => [
            "I've lost {n} matches in a row and I'm sick of it. Every week I go out there, give it everything, and walk back through the curtain with nothing to show for it.\n\nPut me in a position to win.",
            "{n} losses. Straight. Do you know what that does to a wrestler? The fans are starting to look at me like a joke, and the boys in the back aren't much better.\n\nGive me a match I can win. I'm begging you.",
            "I lost again. That's {n} in a row. I'm not asking for a handout, but I need a win, and I need it soon.\n\nPut me in a position to win before this streak defines me.",
        ],
        'morale.contract_extension' => [
            "I've won {n} straight and I'm still on the same deal I signed when nobody knew my name. Right now I'm one of the hottest acts on this brand.\n\nI've earned a new contract.",
            "{n} wins in a row. The fans are chanting my name, the merchandise is flying off the tables, and I'm still getting paid like an opening act.\n\nI've earned a new contract, and I expect to see one.",
            "Look at the record: {n} straight wins. Nobody on this roster is hotter than me right now.\n\nI've earned a new contract. Let's talk numbers.",
        ],
        'morale.title_match' => [
            "It's been {n} weeks since I got a title match. I've been patient, I've done everything you asked, and I've watched other people get the shots I've earned.\n\nI deserve a shot at the gold.",
            "{n} weeks without a title match. I didn't come here to make up the numbers. I came here to be a champion.\n\nI deserve a shot at the gold, and I'm tired of waiting for it.",
            "Every week I look at the card, and every week there's no title match with my name on it. That's {n} weeks now.\n\nI deserve a shot at the gold. Give me one and I'll prove it.",
        ],
        'morale.inactivity' => [
            "I haven't been used in {n} weeks. I didn't sign with {brand} to sit in the back.\n\nI show up every week ready to work, and every week I watch the show from a monitor in catering.",
            "{n} weeks without a match. My family keeps asking if I still work here, and honestly, I'm not sure what to tell them.\n\nI didn't sign with {brand} to sit in the back. Put me on the card.",
            "It's been {n} weeks since you last put me on a show. I'm in the best shape of my life, and I'm wasting it.\n\nI didn't sign with {brand} to sit in the back.",
        ],
        'morale.upset' => [
            "I'm upset, and if nothing changes I'll start looking at the other brand.",
            "I'm upset. If nothing changes soon, I'll start looking at the other brand, and I won't be the last.",
            "Let me be clear: I'm upset. If nothing changes, I'll start looking at the other brand.",
        ],
        'morale.unsatisfied' => [
            "I'm not happy about it.",
            "I'm not happy about it, and I won't stay quiet much longer.",
            "I'm not happy about it. I hope you're listening.",
        ],

        // Trainer: injuries.
        'injury.fresh' => [
            "{name} suffered a {injury}, a {severity} injury, during this week's show.",
            "Bad news from the trainer's room: {name} suffered a {injury}, a {severity} injury.",
            "I've just finished checking {name} over. They suffered a {injury}, a {severity} injury.",
        ],
        'injury.escalated' => [
            "{name} kept competing through a minor injury and it got worse. It's now a {injury}, a {severity} injury.",
            "I warned you about this. {name} kept working through a minor injury, and now it's a {injury}, a {severity} injury.",
            "{name} kept competing hurt, and the injury got worse. It's now a {injury}, a {severity} injury.",
        ],
        'injury.serious' => [
            "They can't compete and should be healed in {weeks}.\n\nThey can't be booked until then. I'll keep you posted on their recovery.",
            "They can't compete and should be healed in {weeks}.\n\nI've sent them for scans and set up a rehab plan. Don't book them until I clear them.",
            "They can't compete and should be healed in {weeks}.\n\nIt's not what anyone wanted to hear. Leave them off the card and let them heal properly.",
        ],
        'injury.minor' => [
            "They can still compete, but every match risks making it worse. It should heal in {weeks}.\n\nIf you can spare them for a week or two, I'd rest them.",
            "They can still compete, but every match risks making it worse. It should heal in {weeks}.\n\nI've strapped it up as best I can. The rest is your call.",
            "They can still compete, but every match risks making it worse. It should heal in {weeks}.\n\nA lighter schedule would go a long way.",
        ],
        'injury.cleared' => [
            "Good news: {name} has made a full recovery from the {injury}. The doctors ran the last round of tests this week and signed off on everything, so {name} is cleared to compete.\n\nThey've been training hard through rehab and can't wait to get back in the ring. You can book them again from next week. I'd ease them in with a lighter match first, but it's your call.",
            "{name} has made a full recovery from the {injury}. I watched them go through their final workout today and they didn't miss a step.\n\nThey're cleared to compete and you can book them again from next week. The fans will be glad to see them back.",
            "I'm happy to report that {name} has made a full recovery from the {injury}. The scans are clean and the doctors are happy.\n\nYou can book them again from next week. Take it easy with them for a match or two, and they'll be back to their best in no time.",
        ],
    ];

    /**
     * One variant of `$key`, drawn with `$rng`, with its placeholders filled.
     *
     * @param  array<string, string|int>  $vars
     */
    public static function pick(string $key, Rng $rng, array $vars = []): string
    {
        return self::fill($rng->pick(self::variants($key)), $vars);
    }

    /**
     * @return list<string>
     */
    public static function variants(string $key): array
    {
        return self::TEXTS[$key] ?? throw new \InvalidArgumentException("No email text for [{$key}].");
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::TEXTS);
    }

    /**
     * @param  array<string, string|int>  $vars
     */
    public static function fill(string $text, array $vars): string
    {
        $pairs = [];

        foreach ($vars as $name => $value) {
            $pairs['{'.$name.'}'] = (string) $value;
        }

        return strtr($text, $pairs);
    }
}
