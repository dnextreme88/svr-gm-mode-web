<?php

namespace App\Services\Mail;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Enums\MatchFamily;
use App\Enums\PpvKey;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Calendar\CalendarService;
use App\Services\Titles\TitleService;

/**
 * Private GM emails (§2, §3). Most come from JR (Raw) or Tazz (SmackDown);
 * others come from the rival GM, Vince McMahon, the Trainer or the superstar
 * themselves.
 */
class EmailService
{
    public function __construct(
        private CalendarService $calendar,
        private TitleService $titles,
    ) {}

    /**
     * JR for Raw, Tazz for SmackDown.
     */
    public function sender(Brand $brand): string
    {
        return (string) config('gm.email_senders.'.$brand->value);
    }

    /**
     * The GM of the brand on the other side of `$brand`.
     */
    public function rivalGm(Brand $brand): string
    {
        return (string) config('gm.email_senders.rival_gm.'.$this->rival($brand)->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(Save $save, Brand $brand, EmailTemplate $template, string $subject, array $payload = [], ?string $body = null, ?int $week = null, ?string $sender = null): Email
    {
        $week ??= $save->current_week;

        return Email::create([
            'save_id' => $save->id,
            'brand' => $brand,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'template' => $template,
            'sender' => $sender ?? $this->sender($brand),
            'subject' => $subject,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    /**
     * An email written by the superstar themselves.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromSuperstar(SaveWrestler $wrestler, Brand $brand, EmailTemplate $template, string $subject, string $body, int $week, array $payload = []): Email
    {
        return $this->send($wrestler->game, $brand, $template, $subject, ['save_wrestler_id' => $wrestler->id, ...$payload], $body, $week, $wrestler->name());
    }

    /**
     * The weekly Expiring Contracts digest (§2): one email listing everyone
     * on the brand with 4 or fewer weeks left, sorted by weeks left.
     */
    public function expiringContracts(Save $save, Brand $brand, int $week): ?Email
    {
        $threshold = (int) config('gm.contracts.expiring_threshold_weeks');

        $contracts = Contract::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('status', ContractStatus::Active)
            ->where('weeks_remaining', '>', 0)
            ->where('weeks_remaining', '<=', $threshold)
            ->whereHas('saveWrestler', fn ($q) => $q->where('brand', $brand))
            ->get()
            ->sort(fn (Contract $a, Contract $b): int => [$a->weeks_remaining, $a->saveWrestler->name()] <=> [$b->weeks_remaining, $b->saveWrestler->name()])
            ->values();

        if ($contracts->isEmpty()) {
            return null;
        }

        $rows = $contracts->map(function (Contract $contract): array {
            $wrestler = $contract->saveWrestler;
            $titles = $this->titles->titlesHeldBy($wrestler);

            return [
                'save_wrestler_id' => $wrestler->id,
                'name' => $wrestler->name(),
                'weeks_left' => $contract->weeks_remaining,
                'weekly_cost' => $contract->weekly_cost,
                'morale' => $wrestler->morale->value,
                'champion' => $titles !== [],
                'titles' => $titles,
                'expires_this_week' => $contract->weeks_remaining === 1,
            ];
        })->all();

        $count = count($rows);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::ExpiringContracts,
            "Contracts expiring soon — {$count} superstar(s)",
            ['rows' => $rows],
            week: $week,
        );
    }

    /**
     * Follow-up listing everyone whose contract hit 0 this week (§2).
     *
     * @param  list<SaveWrestler>  $expired
     * @param  array<int, list<string>>  $vacatedTitles  titles vacated, keyed by save_wrestler_id
     */
    public function contractExpired(Save $save, Brand $brand, int $week, array $expired, array $vacatedTitles = []): ?Email
    {
        if ($expired === []) {
            return null;
        }

        $count = count($expired);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::ContractExpired,
            "Contract expired — {$count} superstar(s) left for Free Agency",
            [
                'rows' => array_map(fn (SaveWrestler $w): array => [
                    'save_wrestler_id' => $w->id,
                    'name' => $w->name(),
                    'vacated' => $vacatedTitles[$w->id] ?? [],
                ], $expired),
            ],
            'These contracts ran out this week. Each wrestler has been released to Free Agency.',
            $week,
        );
    }

    /**
     * JR/Tazz says hello at the start of every season.
     */
    public function welcome(Save $save, Brand $brand, int $week): Email
    {
        $season = $this->calendar->seasonOf($week);

        $body = $season === 1
            ? "Welcome to {$brand->label()}. The roster is set and the titles have their first champions, so the rest is up to you.\n\nIf you ever need help, check your email. I'll keep you posted on contracts that are running out, injuries, unhappy superstars, upcoming pay-per-views and anything else you should know about."
            : "WrestleMania is behind us and season {$season} starts now. Same {$brand->label()}, new year: the calendar starts over and the road leads to WrestleMania again.\n\nAs always, if you need help, check your email. I'll keep you posted.";

        return $this->send($save, $brand, EmailTemplate::Welcome, $season === 1 ? "Welcome to {$brand->label()}" : "Season {$season} starts now", ['season' => $season], $body, $week);
    }

    /**
     * How specialties work, optionally pointing at one of your specialists.
     */
    public function specialtyTip(Save $save, Brand $brand, int $week): Email
    {
        $body = "A quick word on superstar specialties. Some superstars are specialists in a type of match, like Hardcore, Ladder or Submission. Book a specialist in their kind of match and they get an edge on the outcome. Put two specialists in the same match and the edges cancel out.\n\nYou can see every superstar's specialties on their roster page.";

        $specialists = SaveWrestler::with('wrestler.specialties')
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->specialties->isNotEmpty())
            ->values();

        $rng = $save->rng("email.specialty.{$brand->value}", $week);
        $mentioned = null;

        if ($specialists->isNotEmpty() && $rng->chance((float) config('gm.emails.specialty_mention_chance'))) {
            /** @var SaveWrestler $mentioned */
            $mentioned = $rng->pick($specialists->all());
            $families = implode(' and ', array_map(fn (MatchFamily $f): string => $f->label(), $mentioned->wrestler->specialtyFamilies()));
            $body .= "\n\nFor example, {$mentioned->name()} is a {$families} specialist. Keep that in mind when you build the card.";
        }

        return $this->send($save, $brand, EmailTemplate::SpecialtyTip, 'Superstar specialties', ['save_wrestler_id' => $mentioned?->id], $body, $week);
    }

    /**
     * A nudge after a long stretch without an Interference promo.
     */
    public function interferenceReminder(Save $save, Brand $brand, int $weeksUnused, int $week): Email
    {
        $rival = $this->rival($brand);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::PromoReminder,
            'Time to crash the other show?',
            ['promo' => 'interference_promotion', 'weeks' => $weeksUnused],
            "We haven't sent anyone to crash {$rival->label()}'s show in {$weeksUnused} weeks. An Interference promo costs money and only works half the time, but when it lands it makes the news.\n\nYou'll find it in the promo slots on your next card.",
            $week,
        );
    }

    /**
     * The rival GM answers a trade offer.
     *
     * @param  list<string>  $out  the proposer's superstars on offer
     * @param  list<string>  $in  the superstars the proposer asked for
     */
    public function tradeAnswer(Save $save, Brand $proposer, bool $accepted, array $out, array $in, int $week): Email
    {
        $list = fn (array $names): string => $names === [] ? '(nobody)' : implode("\n", $names);
        $opening = $accepted ? 'I accept your trade. The roster change is effective immediately.' : 'I decline your trade.';

        return $this->send(
            $save,
            $proposer,
            EmailTemplate::Trade,
            $accepted ? 'Trade accepted' : 'Trade declined',
            ['accepted' => $accepted, 'out' => $out, 'in' => $in],
            "{$opening}\n\n{$list($out)}\n\nfor\n\n{$list($in)}",
            $week,
            $this->rivalGm($proposer),
        );
    }

    /**
     * The rival GM reacts to a Slander promo aimed at their show.
     */
    public function slanderResult(Save $save, Brand $brand, bool $succeeded, int $week): Email
    {
        $rival = $this->rival($brand);

        [$subject, $body] = $succeeded
            ? ['You just made an enemy', "I saw what you pulled with that smear campaign against {$rival->label()}. Cute. Enjoy it while it lasts, because I don't forget, and I will get you back for this."]
            : ['Nice try', "Was that smear campaign supposed to hurt {$rival->label()}? The whole locker room had a good laugh at it. Thanks for the free entertainment, and for wasting your money."];

        return $this->send($save, $brand, EmailTemplate::SlanderResult, $subject, ['succeeded' => $succeeded], $body, $week, $this->rivalGm($brand));
    }

    /**
     * Vince's reminder ahead of a shared (Raw and SmackDown) pay-per-view.
     */
    public function ppvReminder(Save $save, Brand $brand, PpvEvent $ppv, int $weeksOut, int $week): ?Email
    {
        $body = match ($ppv->key) {
            PpvKey::Summerslam => "SummerSlam is {$weeksOut} weeks away. It's the first pay-per-view of the season where Raw and Smackdown share the card, so the whole company is watching.\n\nGoing forward, the show with the most fans going in will get the main event slot. Make every week count.",
            PpvKey::SurvivorSeries => "Survivor Series is {$weeksOut} weeks away. The main event is our Traditional 3 vs 3 Survivor Series elimination tag match: three from Raw against three from Smackdown, eliminated one at a time until one side is left. It's a house rule, not something we've done before.\n\nChoose your team wisely.",
            PpvKey::RoyalRumble => "The Royal Rumble is {$weeksOut} weeks away. Thirty men, fifteen from each brand, and only one winner.\n\nWhoever wins the Rumble gets an automatic world title shot in the main event of WrestleMania.",
            PpvKey::Wrestlemania => "WrestleMania is {$weeksOut} weeks away. On top of the card, Raw and Smackdown go head to head in a three-match interbrand series.\n\nThe brand that wins the series gets the immunity tag (protect one superstar from the re-draft) and the manual draft pick tag (swap one random pick for one of your choosing).",
            default => null,
        };

        if ($body === null) {
            return null;
        }

        return $this->send($save, $brand, EmailTemplate::PpvReminder, "{$ppv->name} is coming", ['ppv_event_id' => $ppv->id, 'weeks' => $weeksOut], $body, $week, (string) config('gm.email_senders.vince'));
    }

    /**
     * Vince explains the WrestleMania re-draft.
     */
    public function draftReminder(Save $save, Brand $brand, int $week): Email
    {
        $max = (int) config('gm.ppv.redraft_max_picks');
        $cap = (int) config('gm.roster_cap');

        return $this->send(
            $save,
            $brand,
            EmailTemplate::DraftReminder,
            'The re-draft is open',
            [],
            "WrestleMania is in the books, and that means it's time for the re-draft.\n\nEach brand takes up to {$max} random picks from the other brand's non-champions. The brand that won the interbrand series can swap one random pick for a manual pick and protect one of its own superstars. Rosters are still capped at {$cap}, so plan accordingly.",
            $week,
            (string) config('gm.email_senders.vince'),
        );
    }

    /**
     * A superstar reminds the GM their contract is nearly up.
     */
    public function contractReminder(SaveWrestler $wrestler, Brand $brand, int $weeksLeft, int $week): Email
    {
        return $this->fromSuperstar(
            $wrestler,
            $brand,
            EmailTemplate::ContractReminder,
            "My contract is up in {$weeksLeft} weeks",
            "Just a heads-up: my contract runs out in {$weeksLeft} weeks. If you want me to stay on {$brand->label()}, we need to talk about a new deal soon. Otherwise I'll be testing the free agent market.",
            $week,
            ['weeks_left' => $weeksLeft],
        );
    }

    /**
     * A seriously injured superstar says they're nearly ready to return.
     */
    public function injuryReturning(SaveWrestler $wrestler, Brand $brand, int $weeksLeft, int $week): Email
    {
        return $this->fromSuperstar(
            $wrestler,
            $brand,
            EmailTemplate::InjuryReturning,
            "I'll be back in {$weeksLeft} weeks",
            "Rehab is going well. The doctors say I'll be cleared to compete in {$weeksLeft} weeks. Keep a spot open for me on the card.",
            $week,
            ['weeks_left' => $weeksLeft],
        );
    }

    /**
     * JR/Tazz warns the GM the budget is running low.
     */
    public function lowCash(Save $save, Brand $brand, int $budget, int $week): Email
    {
        $amount = '$'.number_format($budget);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::LowCash,
            "We're running low on cash ({$amount})",
            ['budget' => $budget],
            "We're down to {$amount}. Contracts and match costs come out every week, so it won't last long at this rate.\n\nAdvertising and Merchandise Sponsorship promos bring money in, and cheaper match types help. If we go into the red for too long, the board will make us choose.",
            $week,
        );
    }

    private function rival(Brand $brand): Brand
    {
        return $brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
    }
}
