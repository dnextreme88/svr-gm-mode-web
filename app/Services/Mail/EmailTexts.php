<?php

namespace App\Services\Mail;

use App\Support\Rng\Rng;

/**
 * Email bodies (house rule, 2026-10-31), with several variants per situation
 * drawn with equal odds from the save's seeded RNG, so the same email doesn't
 * read the same every time. Subjects stay fixed; only the bodies vary.
 *
 * Story-text rule (house rule, 2026-11-11): every body runs 1–3 paragraphs
 * of 5–8 sentences (`App\Support\Text\StoryText`). Pieces that are joined
 * into one email (injury report + outlook, morale complaint + level, the
 * specialty and free agent extras) are each a whole paragraph. A trade
 * answer's opening is a paragraph; the names listed under it are exempt.
 *
 * The rival GM's shared-PPV emails (`rival_ppv.*`) have exactly two
 * variants, polite first and arrogant second, drawn 50/50 (house rule,
 * 2026-11-11). Every other situation has at least three.
 *
 * `{placeholders}`: name, brand, rival, weeks, n, injury, severity, amount,
 * season, to, families, names, max, cap, race, our_crowd, your_crowd, slots.
 */
class EmailTexts
{
    /**
     * @var array<string, list<string>>
     */
    private const TEXTS = [
        // JR / Tazz: the season starts.
        'welcome.first' => [
            "Welcome to {brand}. The roster is set, the titles have their first champions, and the arena crew is already hanging the lights for the first show. From here on, every decision is yours. Who wrestles, who gets the title shots, who gets sent home: it all goes through your office. I've called a lot of matches in my time, and I can tell you the fans can smell a great show from the parking lot.\n\nIf you ever need help, check your email. I'll keep you posted on contracts that are running out, injuries, unhappy superstars and every pay-per-view on the calendar. When something goes wrong in the locker room, you'll hear it from me first. When the other brand tries something, you'll hear that from me too. I'll be at ringside every week, so nothing gets past us.\n\nFifty-two weeks stand between you and WrestleMania. Build your champions, feed your rivalries and keep your stars healthy. The fans are already lining up at the box office. Let's give these people a show they'll never forget. Welcome aboard.",
            "Welcome aboard. {brand} is yours now: a full roster, fresh champions and fifty-two weeks to make your mark before WrestleMania. I've been around this business a long time, and I can't remember a locker room this hungry. Every one of them wants to impress the new boss. Use that while it lasts.\n\nIf you ever need help, check your email. I'll be watching every show from ringside, and I'll let you know about expiring contracts, injuries, unhappy superstars and the pay-per-views coming up. The Trainer will write when somebody gets hurt, and the superstars will write when they're not happy. Vince will check in before the big shows, and so will the other brand's GM, whether you want to hear from him or not. Read them all, because the small ones matter too.\n\nThe first card is waiting for you. Book a strong opener, close with something people will talk about, and keep an eye on the fatigue bars. Tired superstars get hurt, and hurt superstars don't draw. The fans are already lining up. Don't keep them waiting.",
            "So you're the one running {brand}. Good. The titles are around the right waists, the locker room is full and the cameras are ready to roll. All that's missing is somebody to make the big calls, and that's you. I'll tell you now: this job is harder than it looks from the cheap seats.\n\nIf you ever need help, check your email. I'll keep you up to date on contracts, injuries, morale and every pay-per-view on the calendar. When your budget gets thin, I'll say so. When a superstar is about to walk out the door, you'll know before he does. That's my job, and I take it seriously.\n\nThe road runs all the way to WrestleMania, and every week on it counts. Make your champions look like champions and give the fans a reason to come back. Watch the other brand, because they'll be watching you. Now go build something special. I'll see you at ringside.",
        ],
        'welcome.next' => [
            "WrestleMania is behind us and season {season} starts now. Same {brand}, new year: the calendar starts over and the road leads to WrestleMania again. The re-draft has shaken up both locker rooms, so take a good look at who you've got. Some old faces are gone and some new ones are waiting for their chance. The champions are still the champions, and they'll have targets on their backs from day one.\n\nAs always, if you need help, check your email. Contracts, injuries, unhappy superstars, the next pay-per-view: I'll keep you posted on all of it. The other brand had a long off-season to think about how they lost to us, or how they beat us. Either way, they'll come out swinging. Let's be ready for them.\n\nLast season is in the record books, and nobody can take it away from us. This one starts with a clean slate. Book smart, keep your stars fresh and give the fans something new to cheer for. Let's make this one bigger. I'll see you at ringside.",
            "Season {season} is here. The confetti from WrestleMania is barely swept up, and {brand} is already back on the road. The fans who were there for the big show want to see what happens next. The ones who missed it want to know what all the fuss was about. Either way, the house is full and the cameras are rolling.\n\nIf you need help, check your email. Contracts, injuries, unhappy superstars, the next pay-per-view: I'll keep you in the loop on all of it. The re-draft moved a few people around, so some of your plans may need a rewrite. Take a look at the roster before you book the first card. A fresh face in the right spot can carry a whole season.\n\nNew year, same goal: WrestleMania, and a win over the other brand when it counts. The titles are there to be fought over, and the rivalries are there to be built. I'll be right here every week. Let's go. The road starts now.",
            "Welcome to season {season}. The calendar resets, the rosters have been shaken up by the re-draft, and every title is up for grabs again. Some of your superstars are carrying momentum from last year, and some are carrying grudges. Both are worth something if you use them right. Take stock before you start booking.\n\nAs ever, check your email if you need help. I'll be right here with news on contracts, injuries, morale and the pay-per-views ahead. The other brand will be doing the same homework, so don't give them a head start. A slow start can cost you SummerSlam before you know it. The fans remember who drew the big crowds.\n\nThe fans remember what we did last year, and they expect more this time. Give them champions worth believing in and rivalries worth paying for. Keep an eye on the budget and on the fatigue bars. Time to top it. I'll see you at ringside.",
        ],

        // JR / Tazz: how superstar specialties work (season weeks 1 and 27).
        'specialty_tip' => [
            "A quick word on superstar specialties. Some superstars are specialists in a type of match, like Hardcore, Ladder or Submission. Book a specialist in their kind of match and they get an edge on the outcome. Put two specialists of the same kind in one match and the edges cancel out. You can see every superstar's specialties on their roster page, and it's worth a look before you build the card.",
            "Let me tell you something about specialties that a lot of GMs miss. A Ladder specialist climbs faster, a Hardcore specialist hits harder, and a Submission specialist knows exactly where to twist. Book them in their kind of match and they get a real edge on the outcome. Put two of the same kind in there and the edge disappears, because they cancel each other out. Every superstar's specialties are on their roster page.\n\nUse it when you need a win to look convincing. A specialist in the right match makes your plans a lot more likely to come off. Use it against the other brand too: if they've got a specialist, keep them out of his kind of match. Little edges like this win wars. Keep it in mind.",
            "Here's something worth remembering when you put a card together. Some of our superstars are specialists in one kind of match: Hardcore, Ladder, Submission and the like. In their kind of match they've got an edge on the outcome. Two specialists in the same kind of match cancel each other out. Check the roster pages to see who's who.",
        ],
        'specialty_tip.mention' => [
            "For example, {name} is a {families} specialist. Put them in that kind of match and the odds swing their way. It's a good spot for a big win when you need one. It's also a good way to make a newcomer look strong in a hurry. Keep that in mind when you build the card.",
            "Take {name}, for instance: a {families} specialist. Book them in that kind of match and they've got the edge on almost anybody. That can set up a title win or rescue a slump. Just don't put another specialist of the same kind across from them. Keep that in mind when you build the card.",
            "Right now, the one I'd look at is {name}, a {families} specialist. In that kind of match they're tough to beat. The fans can tell when somebody's in their element, too. A win there means more than a win anywhere else. Keep that in mind when you build the card.",
        ],

        // JR / Tazz: how to keep the crowds interested (every 8 weeks; house rule, 2026-11-11).
        'booking.variety' => [
            "I want to talk to you about keeping the crowd on its feet. Fans are smarter than people give them credit for, and they notice when they've seen the same thing two weeks running. If the opener was a Ladder match last week, they don't want another Ladder match in that spot this week. Same goes for every match on the card. Book the same kind of match in the same spot two weeks in a row and fewer people buy a ticket.\n\nMix it up from week to week. A Tables match one week, a Submission match the next, a straight singles match after that. Variety keeps the people guessing, and people who are guessing keep coming back. It doesn't cost you a dime, and it's the easiest way to stop the house from shrinking. Keep it fresh.",
            "Here's something I've learned from a lot of years at ringside: the fans hate a rerun. They paid to see something new, and if the same kind of match sits in the same spot as last week, they feel it. It doesn't matter how good it was the first time. The second time, it's just not the same. That's when the empty seats start showing up.\n\nSo shuffle things around. Move the Steel Cage to a different slot, try a Hardcore match where the singles match was, or give the tag teams a turn. Every week should feel like its own show. The more you mix the categories, the more people come through the door. Trust me on this one.",
            "Let me give you a tip on keeping the crowds big. People notice when the card looks like last week's card. Put the same kind of match in the same spot two weeks running, and some of them decide to stay home. It's not that they didn't like it. They just want to see something they haven't seen yet.\n\nA little variety goes a long way, and it's free. Change the match categories from one week to the next, spot by spot. If you had a Ladder match in the main event, try something else there next week. The fans will reward you for it at the box office. Keep them guessing.",
        ],
        'booking.variety.repeats' => [
            "This week's {brand} card had {slots} with the same kind of match as the week before. The crowd noticed, and the numbers took a small hit for it. It's an easy fix: just change the categories next week. Nobody's asking you to throw out your plans. Move the pieces around and the fans will come back.",
            "For what it's worth, {slots} on this week's {brand} card were the same kind of match as last week's. That cost us a few fans at the door. One repeat isn't the end of the world, but they add up. Take a look before you lock in the next card. A small change in each slot makes all the difference.",
            "I counted {slots} on this week's {brand} card that matched last week's. The house was a little lighter because of it. It's the kind of thing that sneaks up on you. A quick shuffle of the match categories fixes it. Next week is a chance to win those fans back.",
        ],

        // JR / Tazz: why Match 1 and the Main Event matter (every 12 weeks; house rule, 2026-11-11).
        'booking.slots' => [
            "Let me tell you about the two most important spots on any card. The first match sets the tone. If the opener is hot, the crowd is with you all night. If it's flat, you spend the rest of the show trying to win them back. Put something on first that gets people out of their seats.\n\nThe main event is what sends them home happy, and it's what sells the next ticket. Nothing draws like a world title on the line in the last match of the night. Any title helps, but the big gold is the big draw. A gimmick match will do it too, and so will two rivals settling a score. Get those two spots right and fill out the rest of the card, and the crowds will follow.",
            "There's an old saying in this business: open hot and close strong. The opening match is the first impression, and you don't get a second one. A title match or a gimmick match up front tells the fans they're in for a big night. Two rivals going at it right out of the gate does the same thing. Start with a bang.\n\nThen there's the main event, the match people talk about on the way to the parking lot. That's where your world title belongs whenever you can put it there. A Ladder match, a Steel Cage or a grudge match between rivals all work too. Fill every slot on the card while you're at it, because the fans notice an empty one. Get the top and the bottom of the card right, and the rest takes care of itself.",
            "I want to share something with you about how a card draws. The fans pay most attention to two matches: the first one and the last one. The opener tells them what kind of night it's going to be. The main event is what they remember tomorrow. Everything else matters, but those two matter most.\n\nIf you've got a title to defend, put it in one of those spots, and put the world title in the main event if you can. A gimmick match or two rivals with real bad blood will pack the place too. And don't leave holes in the card, because a full card tells people you mean business. Book those two spots like your job depends on it. In a way, it does.",
        ],

        // JR / Tazz: no Interference for a long stretch.
        'interference_reminder' => [
            "We haven't sent anyone to crash {rival}'s show in {weeks}. An Interference promo costs money and only works half the time, but when it lands it makes the news. The fans love a surprise, and nothing gets the locker room talking like one of ours storming the other brand's ring. It also gives our crowd something to cheer about the next week. You'll find it in the promo slots on your next card.\n\nJust remember it's a gamble. If security catches our guy, {rival} gets to laugh about it. And don't go to the well too often, because the fourth one in five weeks blows up in our faces. Pick your moment, and pick somebody fresh enough to take the punishment. When it works, it's worth every penny.",
            "It's been {weeks} since we last paid {rival} a visit. They're getting comfortable over there, and comfortable rivals are dangerous rivals. An Interference promo isn't cheap and it's a coin flip whether it works. But when it does, the whole company hears about it. It's in the promo slots on your next card if you want it.\n\nWhoever you send will take a beating, so don't pick somebody who's already worn out. The fans eat it up when it works and our crowd gets a lift from it. Just don't overdo it: a fourth run-in within five weeks backfires. Their GM will be furious either way. That's half the fun.",
            "{weeks} and not a single surprise visit to {rival}. The fans are starting to forget there's a war on. Send somebody over the barricade with an Interference promo. Half the time security stops them, but the other half it's the biggest story of the week. You'll find it in the promo slots on your next card. I'd love to see the look on their GM's face.",
        ],

        // JR / Tazz: no Slander for a long stretch.
        'slander_reminder' => [
            "It's been {weeks} since we said a word about {rival}. They're getting a free ride in the press while we keep quiet. A Slander promo costs money and only works half the time. If you run a fourth one within five weeks it backfires on us, so don't get greedy. But when it lands, {rival} spends the week answering questions instead of selling tickets. You'll find it in the promo slots on your next card.",
            "{weeks} without a single shot at {rival}. Their GM has been running his mouth, and we've been too polite to answer. A Slander promo is a gamble, and half the time nobody buys it. But when it sticks, it hurts them where it counts. It puts our side of the story in the headlines too.\n\nJust don't overdo it. A fourth one within five weeks blows up in our faces, and that's a story nobody wants. Space them out and make each one count. The fans love a war of words. Let's give them one.",
            "We haven't taken a swipe at {rival} in {weeks}. The fans love a war of words, and right now it's one-sided. Run a Slander promo when you get the chance. It's a coin flip whether it lands, and a fourth one within five weeks backfires. But a good smear campaign can knock them off their game for a while. It's in the promo slots on your next card.",
        ],

        // JR / Tazz: no trade offer for a long stretch.
        'trade_reminder' => [
            "We haven't made {rival} a trade offer in {weeks}. Sometimes the best way to freshen up the roster is to swap someone who isn't working out for someone who will. The Trade page shows you how likely their GM is to say yes before you send anything. It doesn't cost us a thing to look. Champions and seriously injured superstars can't be traded, and both rosters have to stay at 30 or under.",
            "It's been {weeks} since we last talked trade with {rival}. Their GM won't call us, so if we want something from that roster, we'll have to ask. Check the Trade page: you can offer up to five for five, and it shows you the odds before you hit send. Their GM likes Legends, specialists and superstars on a hot streak. He doesn't like taking on injuries. A good trade can change a whole season.",
            "{weeks} without a trade. Is there anybody on {rival} you've had your eye on? Their GM values specialists, Legends, hot win streaks and title history, so put together something that looks good to them. The Trade page shows your chances as you build the offer. Worst case, they say no. Best case, you just stole their best player.",
        ],

        // JR / Tazz: no free agent signed for a long stretch.
        'free_agent_reminder' => [
            "We haven't signed anyone from Free Agency in {weeks}. There's talent sitting in that pool waiting for a call. Keep an eye out for superstars with match specialties: book them in their kind of match and they get an edge. The Legends can't be drafted, so Free Agency is the only place to get them. If we've got room on the roster and money in the bank, it's worth a look.",
            "It's been {weeks} since our last Free Agency signing. The roster could use some new blood. Specialists are worth their weight in gold: put them in a Hardcore, Ladder or Submission match that suits them and they've got the edge. Legends are only ever in Free Agency, and the fans go wild for them. A Legend on the card puts people in seats, too. Take a look when you've got a spot open.",
            "{weeks} without signing a free agent. I know the budget is tight some weeks, but there are some real gems out there. Look for superstars with match specialties, who get an edge in their kind of match. Look for the Legends too, because you can only sign them from Free Agency. New signings join the roster the week after you sign them. Don't let the other brand get there first.",
        ],
        'free_agent_reminder.names' => [
            "Right now, {names} {are} sitting in the Free Agency pool. Somebody's going to sign them sooner or later. I'd rather it was us than {rival}. Check the price before you make the call. Don't wait too long.",
            "Here's who caught my eye: {names}. As of this week, they're still unsigned. The other brand reads the same list we do. A quick call could land us a real difference-maker. Think it over.",
            "If you want a name, {names} {are} out there right now. They won't be on the market forever. A good free agent can change a card overnight. Check what the deal would cost us. Your call.",
        ],

        // JR / Tazz: money.
        'low_cash' => [
            "We're down to {amount}. Contracts and match costs come out every week, so it won't last long at this rate. Advertising and Merchandise Sponsorship promos bring money in, and cheaper match types help. If we go into the red for too long, the board will make us choose for ourselves. I'd rather we fix this before they do.",
            "I just saw the books: {amount} left. Between salaries and the price of every match on the card, that money is going out fast. A couple of Advertising or Merchandise Sponsorship promos would buy us time. The cheaper match types won't hurt the show as much as you'd think. Stay out of the red, and the board stays off our backs.",
            "Bad news from accounting: {brand} is down to {amount}. At this rate we'll be in the red within a few weeks. Money promos like Advertising and Merchandise Sponsorship are the quickest fix. Cheaper match types help, and releasing someone we don't use is another option. Let's sort it out before the board starts asking questions.",
        ],
        'deficit' => [
            "We've been in the red for {weeks} now, and the board has run out of patience. They want a plan before the next show airs. You can run Advertising or Merchandise promos, downgrade to cheaper match types, or release roster members. Pick one, and pick it fast. Nothing airs until you do.\n\nI've seen GMs lose their jobs over less. The money promos are the gentlest way out, but they take time. Releases work fastest, but the locker room won't like it. Cheaper matches keep everybody on the payroll at the cost of a smaller show. Whatever you choose, let's get it done.",
            "{weeks} in the red. The board called this morning, and it wasn't a friendly call. They want to see a plan: money promos, cheaper match types across the card, or releases to cut the payroll. Nothing airs until you choose. We can dig out of this, but only if we start now.",
            "The board wants a plan. We've spent {weeks} in the red, and they won't sign off on another show until they see how we're getting out of it. Your options are money promos, cheaper match types, or releasing roster members. None of them is fun. Whatever you pick, make it count.",
        ],

        // JR / Tazz: contracts that ran out.
        'contract_expired' => [
            "These contracts ran out this week. Each superstar has been released to Free Agency. Any titles they held are vacant now. If you want any of them back, they're on the Free Agency page. Just remember the other brand can sign them too.",
            "We lost some people this week: these contracts ran out, and each superstar has been released to Free Agency. Their titles, if they had any, are vacant. You can still try to sign them back. You won't be the only one asking, though. Move fast if they matter to you.",
            'The following contracts expired this week. Each superstar has been released to Free Agency. Any titles they held are vacant now. If you want someone back, check the Free Agency page before the other brand does. Next time, the Expiring Contracts digest is your friend.',
        ],

        // JR / Tazz: a defection attempt.
        'defection' => [
            "{name} has had enough and wants out. Word in the locker room is they've been on the phone with {to} all week. If {to} has room, {name} is gone, titles and all. If not, they'll sulk in the back for a couple of weeks before they're ready to work again. Either way, we lose them for a while.\n\nAn unhappy superstar is a flight risk. The complaints usually come in first, by email, and by now {name} has stopped complaining. Keep an eye on the morale of everyone else on the roster. Give the unhappy ones a win, a title shot or a spot on the card. It's cheaper than replacing them.",
            "We've got a problem. {name} walked into my office today and told me straight: they want to go to {to}. If {to} has a spot open, there's nothing we can do about it. If they're full, {name} will sit out a couple of weeks instead. This is what happens when the locker room isn't happy. Let's not let it spread.",
            "{name} is trying to jump ship to {to}. They've been unhappy for a while, and it looks like they've finally made up their mind. If {to} can fit them on the roster, {name} will be gone by next week. Otherwise they'll refuse to work for a couple of weeks. Watch the morale on the rest of the roster before anyone else gets ideas.",
        ],

        // Rival GM: trade answers (the paragraph before the names).
        'trade.accepted' => [
            "I accept your trade. The roster change is effective immediately. I've had my people look over the paperwork, and everything checks out. My guys are packing their bags as we speak. Here's the deal as it stands.",
            "You've got yourself a deal. I accept your trade, and the roster change is effective immediately. I'll admit it's a better offer than I expected from you. Treat them well, because I know I will. Here's what changed hands.",
            "Fine, I accept your trade. The roster change is effective immediately. Don't come crying to me if it doesn't work out. I know exactly what I'm getting, and I'm not sure you do. Here's the deal.",
        ],
        'trade.declined' => [
            "I decline your trade. I looked it over twice, and it just doesn't work for my roster. I'm not giving up talent for less than it's worth. If you want to try again, make it worth my while. Here's what you offered.",
            "Nice try, but I decline your trade. I can count, and I know who I'd be getting. You'll need to put something better on the table. My door's still open, for now. For the record, here's the offer.",
            "I've looked it over, and I decline your trade. You'll have to do better than that. My superstars are worth more than what you're offering. Come back when you're serious. This is what you sent me.",
        ],

        // Rival GM: the player's Slander.
        'slander.success' => [
            "I saw what you pulled with that smear campaign against {rival}. Cute. Enjoy it while it lasts. The fans are buying it for now, and my phone hasn't stopped ringing. But I don't forget, and I will get you back for this. You just made an enemy.",
            "So that's how you want to play it? A smear campaign against {rival}, all over the papers. It worked, I'll give you that. My locker room is rattled and the network wants answers. But you've opened a door you can't close, and I will get you back for this.\n\nEvery word you printed, I'll remember. Every reporter who called me this week, I'll send your way when it's my turn. You think you're winning a war of words? You've barely fired the first shot. Sleep with one eye open.",
            "Congratulations. Your little smear campaign made {rival} look bad all week, and everybody's talking about it. Remember this moment, because it's the last time you'll be smiling. I will get you back for this, and I won't need to lie to do it. See you on the battlefield.",
        ],
        'slander.failure' => [
            'Was that smear campaign supposed to hurt {rival}? The whole locker room had a good laugh at it. Nobody bought a word of it. Thanks for the free entertainment, and for wasting your money. Maybe stick to wrestling.',
            "I read your smear campaign against {rival}. Then I read it to the boys in the back. We had a good laugh at it. The fans didn't buy it, the press didn't buy it, and you paid for the privilege of looking foolish. Better luck next time.",
            "That smear campaign was the best thing to happen to {rival} all week. Everyone had a good laugh at it, and it made you look desperate. Keep wasting your money like that and I won't have to do a thing to beat you. My ratings went up, by the way. Thanks for the laugh.",
        ],

        // Rival GM: the player's Interference.
        'interference.success' => [
            "Do you have any idea what your little stunt cost me? Your guys stormed my show, wrecked the ring, tore up the announce table and sent half my production crew running for cover. I've got repair bills, refunds and an angry network on the phone. Every last cent of it has your name on it. The fans loved it, I'll give you that.\n\nThey won't be cheering when I return the favour. You started this war, and I promise you {brand} will pay for it with interest. I know where your trucks park and I know when your show goes live. Don't think for a second I'll forget this. Watch your back.",
            "You think that was funny? Your thugs hijacked {rival} on live television. The ring is in pieces, the pyro rig is busted and I'm refunding half the front row. That's money out of my pocket, and I always collect. Every dollar you cost me tonight, {brand} will pay for it with interest. This isn't over, because it's barely started.",
            "I just got the bill for the damage your people did to my show, and I nearly put my fist through the wall. Broken equipment, injured crew, a network breathing down my neck. You wanted my attention? You've got it. I'm coming for {brand}, and you'll pay for it with interest. Enjoy the headlines while you can.",
        ],
        'interference.failure' => [
            "I heard you tried to send your boys onto {rival} this week. My security had them in a headlock before they got past the curtain, and the cameras caught every second of it. You paid for that, by the way. I didn't. The locker room hasn't stopped laughing, and neither have I.\n\nNext time you want to crash my show, at least make it worth watching. Send somebody who can throw a punch, and maybe send a map. My security team asked me to say thanks for the practice. We'll be ready for you next time too. Try harder.",
            "Was that supposed to be an invasion? Your guy got about three steps down the ramp before my security dragged him out by his boots. The crowd booed him all the way to the parking lot. The locker room hasn't stopped laughing. Thanks for the free entertainment, and for footing the bill. Try again sometime, please.",
            "Nice try sending your people to {rival}. They never made it to the ring: my security was waiting for them. They ended up thrown out the back door in front of a sold-out crowd. The locker room hasn't stopped laughing, and I've already got the replay saved for our next show. That was your money well spent. For me.",
        ],

        // JR / Tazz: the rival's Slander against us.
        'rival_slander.success' => [
            "{rival} spent the week running a smear campaign against {brand}, and it's getting traction. The fans are talking about it. The papers picked it up and the boys in the back are reading every word. We need a strong show to answer them. Give the fans something better to talk about.",
            "I don't like reporting this, but {rival}'s smear campaign against {brand} is working. It's all over the wrestling news this week. The best answer is a great show: big matches, a hot main event, a few surprises. Let the work speak louder than their lies. We've been through worse.",
            "{rival} ran a smear campaign against {brand} this week, and some of it stuck. The fans are asking questions. We can't take it back, but we can bury it. Put on the best show you can, and let the headlines move on. They'll regret starting this.",
        ],
        'rival_slander.failure' => [
            "{rival} ran a smear campaign against {brand} this week. It fell flat: nobody bought it, and they paid good money for nothing. The locker room thought it was hilarious. If anything, it fired our people up. Let's keep giving them reasons to be jealous.",
            "Good news for once: {rival}'s smear campaign against {brand} went nowhere. The fans saw right through it. They paid good money to make themselves look petty. I couldn't have planned it better. Keep doing what you're doing.",
            "{rival} tried to smear {brand} this week and it backfired on them. Nobody bought it, and they paid good money for the embarrassment. The fans are on our side. Let's keep it that way. I'll take a free win any day.",
        ],

        // Superstars: contract and injury notices.
        'contract_reminder' => [
            "Just a heads-up: my contract runs out in {weeks}. If you want me to stay on {brand}, we need to talk about a new deal soon. I've given this brand everything I've got, and I'd like to keep doing it. But I've got bills to pay, and I won't wait forever. Otherwise I'll be testing the free agent market.",
            "My contract is up in {weeks}, and nobody from the office has called me yet. I like it here. The locker room is good and the fans are behind me. But if there's no new deal on the table soon, I'll have to listen to other offers. Your move.",
            "I wanted to let you know my deal with {brand} runs out in {weeks}. I want to stay, I really do. Let's sit down and work out a new contract before it's too late. I'm not asking for the moon, just a fair deal. If I don't hear from you, I'll be testing the free agent market.",
        ],
        'injury_returning' => [
            "Rehab is going well. The doctors say I'll be cleared to compete in {weeks}. I've been in the gym every day, and I feel stronger than before I got hurt. I've been watching every show from home, and I've got a few ideas about who I'd like to face first. Keep a spot open for me on the card.",
            "Quick update from the trainer's room: I'll be cleared to compete in {weeks}. It's been a long road, and sitting on the sidelines nearly drove me crazy. The fans have been great, though, and I've read every letter. I'm coming back hungry. Make sure there's a match waiting for me.",
            "The doctors gave me good news today: I'll be cleared to compete in {weeks}. I won't lie, there were days I wondered if I'd come back at all. But I'm almost there, and I'm ready to pick up right where I left off. The first person who gets in my way is in trouble. Keep a spot open for me on the card.",
        ],

        // Superstars: morale complaints, each followed by a level paragraph.
        'morale.losing_streak' => [
            "I've lost {n} matches in a row and I'm sick of it. Every week I go out there, give it everything, and walk back through the curtain with nothing to show for it. The fans are starting to look through me. The boys in the back have stopped asking how I'm doing. Put me in a position to win.",
            "{n} losses, straight. Do you know what that does to a superstar? The fans are starting to look at me like a joke, and the boys in the back aren't much better. I still believe I can beat anybody on this roster. Give me a match I can win, because I'm begging you.",
            "I lost again. That's {n} in a row. I'm not asking for a handout, but I need a win, and I need it soon. Every loss makes the next one easier for them to book. Put me in a position to win before this streak defines me.",
        ],
        'morale.contract_extension' => [
            "I've won {n} straight and I'm still on the same deal I signed when nobody knew my name. Right now I'm one of the hottest acts on this brand. The crowd pops when my music hits, and you know it. Other superstars are making more for doing less. I've earned a new contract.",
            "{n} wins in a row. The fans are chanting my name, the merchandise is flying off the tables, and I'm still getting paid like an opening act. That doesn't sit right with me. I've earned a new contract, and I expect to see one. Let's not make this complicated.",
            "Look at the record: {n} straight wins. Nobody on this roster is hotter than me right now. I'm carrying shows, and I'm doing it on an old contract. I've earned a new contract. Let's talk numbers.",
        ],
        'morale.title_match' => [
            "It's been {n} weeks since I got a title match. I've been patient, I've done everything you asked, and I've watched other people get the shots I've earned. I'm not getting any younger. The fans keep asking when it's my turn. I deserve a shot at the gold.",
            "{n} weeks without a title match. I didn't come here to make up the numbers. I came here to be a champion. I deserve a shot at the gold, and I'm tired of waiting for it. Put my name next to a belt on the card and watch what happens.",
            "Every week I look at the card, and every week there's no title match with my name on it. That's {n} weeks now. I've been beating good people while I wait. I deserve a shot at the gold. Give me one and I'll prove it.",
        ],
        'morale.inactivity' => [
            "I haven't been used in {n} weeks. I didn't sign with {brand} to sit in the back. I show up every week ready to work, and every week I watch the show from a monitor in catering. My ring gear hasn't been out of the bag in a month. Put me on the card.",
            "{n} weeks without a match. My family keeps asking if I still work here, and honestly, I'm not sure what to tell them. I didn't sign with {brand} to sit in the back. Any match, any opponent, any spot on the card. Put me on the card.",
            "It's been {n} weeks since you last put me on a show. I'm in the best shape of my life, and I'm wasting it. I didn't sign with {brand} to sit in the back. The fans forget you fast in this business. Don't let them forget me.",
        ],
        'morale.upset' => [
            "I'm upset, and I'm not going to pretend otherwise. I've kept my head down and done my job, and look where it's got me. If nothing changes, I'll start looking at the other brand. They've got room, and I hear they're always looking for talent. Don't make me make that call.",
            "Let me be clear: I'm upset. I'm not the only one in that locker room who feels this way, either. If nothing changes soon, I'll start looking at the other brand. I won't be the last to do it. Fix this while you still can.",
            "I'm upset, and I've run out of patience. I've said my piece politely, and nothing happened. If nothing changes, I'll start looking at the other brand. You'll read about it in the papers before you hear it from me. Consider this your warning.",
        ],
        'morale.unsatisfied' => [
            "I'm not happy about it. I'm not going to make a scene, at least not yet. But I wanted you to hear it from me first. I'm a professional and I'll keep showing up. I hope you're listening.",
            "I'm not happy about it, and I won't stay quiet much longer. I've talked to a few of the boys and they agree with me. I'd rather sort this out between us. Just don't let it drag on. The clock is ticking.",
            "I'm not happy about it. I know you've got a whole roster to think about. But I've given this brand a lot, and I'd like something back. I'm not asking for much. I hope you're listening.",
        ],

        // Trainer: injuries. The report paragraph, then the outlook paragraph.
        'injury.fresh' => [
            "{name} suffered a {injury}, a {severity} injury, during this week's show. I was at ringside when it happened and got to them as fast as I could. They walked to the back under their own power, which is a good sign. I've had a proper look at it since. It's not the worst I've seen, and it's not the best.",
            "Bad news from the trainer's room: {name} suffered a {injury}, a {severity} injury. It happened mid-match, and they finished it on adrenaline alone. By the time they got through the curtain, they could barely stand. I've run the first tests. Here's where we are.",
            "I've just finished checking {name} over. They suffered a {injury}, a {severity} injury. They were tired going in, and that's usually when it happens. I've seen enough of these to know how they go. Here's what to expect.",
        ],
        'injury.escalated' => [
            "{name} kept competing through a minor injury and it got worse. It's now a {injury}, a {severity} injury. I flagged it the first time, and I'm flagging it again now. Every match on a bad injury is a roll of the dice. This time the dice came up wrong.",
            "I warned you about this. {name} kept working through a minor injury, and now it's a {injury}, a {severity} injury. They didn't complain, because they never do. The body doesn't care how tough you are. Here's what happens now.",
            "{name} kept competing hurt, and the injury got worse. It's now a {injury}, a {severity} injury. It started small and it didn't stay small. I've seen it happen a hundred times. Here's the outlook.",
        ],
        'injury.serious' => [
            "They can't compete and should be healed in {weeks}. They can't be booked until then. I've set up a rehab plan and I'll be checking in on them every week. If they hold a title, it's been vacated. I'll keep you posted on their recovery.",
            "They can't compete and should be healed in {weeks}. I've sent them for scans and set up a rehab plan. Don't book them until I clear them. If they were a champion, the title is vacant now. They'll write to you when they're close to coming back.",
            "They can't compete and should be healed in {weeks}. It's not what anyone wanted to hear. Leave them off the card and let them heal properly. Rushing back is how a few weeks turn into a few months. I'll let you know the moment they're cleared.",
        ],
        'injury.minor' => [
            "They can still compete, but every match risks making it worse. It should heal in {weeks}. If you can spare them for a week or two, I'd rest them. A lighter match helps if you can't. The worst thing you can do is push them past their limit.",
            "They can still compete, but every match risks making it worse. It should heal in {weeks}. I've strapped it up as best I can. The rest is your call. Just know that I've seen minor injuries turn serious in a single night.",
            "They can still compete, but every match risks making it worse. It should heal in {weeks}. A lighter schedule would go a long way. Keep their fatigue down and give them time to recover. They'll thank you for it later.",
        ],
        'injury.cleared' => [
            "Good news: {name} has made a full recovery from the {injury}. The doctors ran the last round of tests this week and signed off on everything, so {name} is cleared to compete. They've been training hard through rehab and can't wait to get back in the ring. You can book them again from next week. I'd ease them in with a lighter match first, but it's your call.",
            "{name} has made a full recovery from the {injury}. I watched them go through their final workout today and they didn't miss a step. They're cleared to compete, and you can book them again from next week. The fans will be glad to see them back. Their fatigue is fresh, so they're ready for anything.",
            "I'm happy to report that {name} has made a full recovery from the {injury}. The scans are clean and the doctors are happy. You can book them again from next week. Take it easy with them for a match or two. They'll be back to their best in no time.",
        ],

        // Vince McMahon: shared-PPV reminders, 2 weeks out.
        'ppv_reminder.summerslam' => [
            "SummerSlam is {weeks} away. It's the first pay-per-view of the season where Raw and Smackdown share the card, so the whole company is watching. The brand whose weekly shows draw the most fans this season gets the main event slot. That brand books the opener and the main event, and the other brand fills in the middle. {race}\n\nMake every week count between now and then. A full card, a hot opener and a big main event are what fill the building. The fans are voting with their tickets. I want SummerSlam to be the biggest show of the summer. Don't let me down.",
            "{weeks} to go until SummerSlam. Raw and Smackdown share one card, and only one brand gets the main event. It goes to whoever drew the most fans at their weekly shows this season. {race} The headlining brand books the opener and the main event too.\n\nThe next two weeks still count toward the total, so this isn't over. Book shows people want to see, and fill every slot. I'll be watching the numbers personally. The main event of SummerSlam is a prize worth fighting for. Go get it.",
            "SummerSlam is {weeks} away, and I've been looking at the attendance figures. The brand that drew the most fans this season gets the main event and books the opener as well. The other brand gets the matches in between. {race} There's still time to change it.\n\nThis is the show the whole summer builds to. Put your best foot forward every week until then. Big matches, hot rivalries and a full card bring the people in. I expect both brands to fight for it. Make me proud.",
        ],
        'ppv_reminder.survivor_series' => [
            "Survivor Series is {weeks} away. The main event is our Traditional 3 vs 3 Survivor Series elimination tag match: three from Raw against three from Smackdown. They're eliminated one at a time until one side is left standing. It's a house rule, not something we've done before. The winning brand walks out as the better brand, and everybody knows it.\n\nChoose your team wisely. Fresh legs matter in an elimination match, so don't run your best people into the ground before then. No Divas this time. The other brand will send its best, and so should you. I'll be at ringside.",
            "{weeks} until Survivor Series. Three of yours against three of theirs in a Traditional Survivor Series elimination tag match, and it closes the show. Every elimination counts, and the last team standing wins it for their brand. This is about bragging rights for the whole company. I want to see a war.\n\nPick three who can go the distance. Keep them healthy and keep their fatigue down. A tired superstar is the first one eliminated. The fans will remember who survived. Make sure it's yours.",
            "Survivor Series is {weeks} away, and the main event is Raw against Smackdown: three on three, elimination rules. One team survives, one brand gets to call itself the best. It's that simple, and it's that important. I've been waiting all year for this one. So have the fans.\n\nChoose your team wisely. Don't pick on reputation alone, because fatigue and injuries can sink a team fast. Divas don't take part in this one. Give the people a match they'll talk about until WrestleMania. I'll see you there.",
        ],
        'ppv_reminder.royal_rumble' => [
            "The Royal Rumble is {weeks} away. Thirty men, fifteen from each brand, and only one winner. Every ninety seconds a new superstar comes through the curtain. The only way out is over the top rope. Whoever wins the Rumble gets an automatic world title shot in the main event of WrestleMania.\n\nPick your fifteen with care, or let the computer pick them for you. Divas don't enter the Rumble. The brand that wins it gets the WrestleMania main event, and that's the biggest stage in the world. Send in your best. I'll be watching.",
            "{weeks} to the Royal Rumble. Fifteen from Raw, fifteen from Smackdown, all in one ring. Over the top rope and you're out, until only one man is left. That man goes on to the main event of WrestleMania for a world title. There's no bigger prize in January.\n\nYou'll pick your fifteen entrants the week of the show. Think about who has the stamina to last. A fresh superstar who's been resting can go a long way. Don't send in somebody who's barely standing. Good luck.",
            "The Royal Rumble is {weeks} away. Thirty men enter and one walks out with a ticket to the main event of WrestleMania. Your brand has fifteen spots, and so does theirs. It's every man for himself once the bell rings. Brand loyalty only goes so far over the top rope.\n\nThe winner's brand gets the WrestleMania main event, against its own world champion. That's a match that sells itself. Choose your entrants wisely. No Divas in this one. Make it count.",
        ],
        'ppv_reminder.wrestlemania' => [
            "WrestleMania is {weeks} away. On top of the card, Raw and Smackdown go head to head in a three-match interbrand series. The brand that wins the series gets the immunity tag and the manual draft pick tag. The immunity tag protects one superstar from the re-draft. The manual pick lets you swap one random pick for one of your choosing.\n\nThat's real power heading into next season. The re-draft takes up to ten superstars from each brand, so those two tags can save your season. The main event belongs to the Royal Rumble winner. Make sure the rest of the card lives up to it. This is the biggest show of the year.",
            "{weeks} until WrestleMania, the grandest stage of them all. Raw and Smackdown face off in a three-match interbrand series on the card. Win the series and your brand gets two tags for the re-draft. One protects a superstar of yours, and the other lets you hand-pick one of theirs. Lose it, and the other brand gets them.\n\nThe Royal Rumble winner headlines the show against his brand's world champion. Everything else on the card is yours to book. Put on a show worthy of the name. The whole world is watching. Don't blink.",
            "WrestleMania is {weeks} away. The card has everything: the Royal Rumble winner's title shot, Money in the Bank, and a three-match interbrand series between Raw and Smackdown. The series winner gets the immunity tag and the manual draft pick tag for the re-draft. One keeps a superstar safe, the other picks one of the rival's. Those tags decide next season.\n\nBook the best card you've ever booked. Keep your top people healthy for the big night. The re-draft follows right after, so think ahead. This is what the whole year has built to. Make it legendary.",
        ],

        // Vince McMahon: the re-draft opens after WrestleMania.
        'draft_reminder' => [
            "WrestleMania is in the books, and that means it's time for the re-draft. Each brand takes up to {max} random picks from the other brand's non-champions. Champions stay where they are, so their titles stay put too. Contracts carry over with whoever moves. Rosters are still capped at {cap}, so plan accordingly.\n\nThe brand that won the interbrand series can swap one random pick for a manual pick. It can also protect one of its own superstars from being taken. The other brand just has to hope for the best. Once the picks are in, the new season starts. Good luck.",
            "WrestleMania is over, and the re-draft is open. Up to {max} picks each, at random, from the other brand's non-champions. Nobody goes over the cap of {cap}, so a full roster skips its picks. Contracts travel with the superstar. Champions don't move.\n\nThe interbrand series winner gets two extra tools. It can hand-pick one superstar from the other brand instead of a random pick. It can also protect one of its own. Use them wisely if you've got them. The new season starts as soon as the picks are done.",
            "The biggest show of the year is behind us, and it's re-draft time. Each brand makes up to {max} random picks from the other brand's non-champions. The cap is still {cap}, so watch your numbers. Contracts and records come along with anyone who moves. It's a fresh start for everybody.\n\nIf you won the interbrand series, you get to protect one of your own and hand-pick one of theirs. If you lost it, the other brand gets those perks instead. Either way, the roster you end up with is the one you take into the new season. Make the most of it. I'll be watching.",
        ],

        // Rival GM: one week before each shared PPV (house rule, 2026-11-11).
        // Exactly two variants each: polite first, arrogant second.
        'rival_ppv.summerslam.ahead' => [
            "SummerSlam is next week, and I wanted to reach out before the big night. It's the first time this season our two brands share a card, and the fans are buzzing about it. I'll be honest with you: {rival} has drawn more fans to our weekly shows than {brand} has, {our_crowd} to {your_crowd}. That should earn us the main event, and I'm proud of my roster for it. You've put on some good shows too, and I mean that.\n\nMay the best show win. I'll book the main event like the whole company is watching, because it is. I hope your matches are every bit as good as ours. However it turns out, it'll be a night to remember. See you at SummerSlam.",
            "SummerSlam is next week, and let's not pretend there's any suspense. {rival} drew {our_crowd} fans this season. {brand} drew {your_crowd}. The fans have spoken, and they spoke loud and clear: we're the brand people pay to see. The main event is ours, and so is the night.\n\nGo ahead and fill the middle of the card. Somebody has to warm the crowd up for my main event. Try not to put them to sleep before we get there. When the dust settles, everyone will know which show is the real show. Enjoy the view from the undercard.",
        ],
        'rival_ppv.summerslam.behind' => [
            "SummerSlam is next week, the first night this season our two brands share a card. I'll give credit where it's due: {brand} has drawn more fans than {rival} at the weekly shows, {your_crowd} to {our_crowd}. You've earned that main event, and I respect it. That doesn't mean we're going to roll over. My roster is going to make the middle of that card the part people talk about.\n\nMay the best show win. I hope your main event lives up to the numbers. We'll do our best to make you work for it. Win or lose, I think the fans are in for a great night. Good luck.",
            "SummerSlam is next week, and I hear you think you've won something. So {brand} drew {your_crowd} fans to our {our_crowd}. Big deal. Crowds come and go, and a main event slot is just a spot on a piece of paper. My superstars are going to steal the show from the middle of the card, and everybody will know it.\n\nEnjoy your main event while you can. The fans will be talking about my matches on the drive home, not yours. Numbers don't win wars, talent does. I've got more of it than you. See you there.",
        ],
        'rival_ppv.summerslam.even' => [
            "SummerSlam is next week, and the crowd race couldn't be closer: {rival} and {brand} have drawn the same, {our_crowd} fans each. I didn't expect that, and I doubt you did either. It says a lot about both of our rosters. Whoever ends up with the main event, I think we can both be proud of the season so far. It's been a real fight.\n\nMay the best show win. I'll give the fans everything I've got, and I expect you'll do the same. That's how it should be on a night like this. Good luck. I'll see you at SummerSlam.",
            "SummerSlam is next week, and the numbers are dead even at {our_crowd} fans apiece. Enjoy it, because that's as close as you'll ever get to {rival}. It took you all season to catch up to where we started. That's not a race, that's a rescue. One good night and we'll leave you behind for good.\n\nWhatever slot you end up with, my superstars will outshine yours. The fans already know which show they'd rather watch. They just haven't said it loudly enough yet. SummerSlam is where they will. See you there.",
        ],
        'rival_ppv.survivor_series' => [
            "Survivor Series is next week, and I wanted to wish you luck before the big night. Three from {rival} against three from {brand}, elimination rules, and only one team survives. I've thought hard about my team, and I believe they'll make {rival} proud. I'm sure you've done the same with yours. Whoever wins gets to call their brand the best in WWE, at least until WrestleMania.\n\nI hope it's a clean fight and a great match. The fans deserve a main event they'll remember. I'll shake your hand afterwards, win or lose. May the better team survive. See you there.",
            "Survivor Series is next week, and I'll save you the suspense: {rival} is the better brand, and next week everybody gets to see it. My three are going to eliminate your three one at a time. The fans will watch {brand}'s team leave the ring in order. There's a reason the whole company looks up to {rival}. You're about to find out why.\n\nPick your team carefully. It won't matter, but it'll make you feel better. When it's over, I'll be the GM of the best brand in WWE, and you'll be explaining what went wrong. Don't bother calling me after. I'll be busy celebrating.",
        ],
        'rival_ppv.royal_rumble' => [
            "The Royal Rumble is next week, thirty men and fifteen from each of our brands. I'll be straight with you: I think my fifteen are as good as any group I've ever put together. They've been training for this all month, and they want that WrestleMania main event badly. I'm sure yours do too. It should be quite a sight when the ring fills up.\n\nWhoever wins, they'll have earned it. I'd like it to be one of mine, of course, and I'll be cheering for {rival} all night. But I respect what you've built at {brand}. Let's give the fans a Rumble to remember. Good luck.",
            "The Royal Rumble is next week, and I almost feel bad for your fifteen. My superstars are bigger, faster and hungrier, and they'll be throwing {brand} over the top rope one after another. I hope your people know how to land, because they'll be doing a lot of it. The last man standing is coming from {rival}. Write it down.\n\nThat means the main event of WrestleMania is ours too. Our winner, our world champion, our show. You can watch from the stands with everybody else. Bring a camera, because you'll want to remember what winning looks like. See you at the Rumble.",
        ],
        'rival_ppv.wrestlemania' => [
            "WrestleMania is next week, the biggest night of the year for both of us. I wanted to tell you how much I'm looking forward to the interbrand series. I'm going to give it everything I've got, and I expect the same from {brand}. If {rival} wins, I'll take the two franchise tags that come with it. I'll hand-pick the best superstar on your roster and protect the best one on mine.\n\nThat's just the prize on the table, and you'd do the same in my shoes. Win or lose, it's been a hard-fought year between our brands. I've learned a lot from going up against you. Good luck on the grandest stage of them all. May the better brand win.",
            "WrestleMania is next week, and I've already cleared a spot on my roster. When {rival} wins the interbrand series, and we will, I'm taking both franchise tags. With the first one I'm picking the best superstar on {brand}, and you already know who I mean. With the second, I'm making sure my best stays right where he is. You won't be able to touch him.\n\nThink about that while you book your card. Your top star will be wearing my brand's colours by next season. You'll be rebuilding from scratch while I'm defending my titles. Enjoy WrestleMania. It's the last big night you'll have for a while.",
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
