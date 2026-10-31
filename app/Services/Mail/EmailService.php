<?php

namespace App\Services\Mail;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Enums\MatchFamily;
use App\Enums\PpvKey;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PendingRosterMove;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Attendance\SummerSlamMainEvent;
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
        private SummerSlamMainEvent $summerSlam,
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
     * One variant of an email body (`EmailTexts`), drawn from the save's RNG.
     * `$who` keeps two emails of the same kind in one week from sharing a draw.
     *
     * @param  array<string, string|int>  $vars
     */
    public function text(Save $save, string $key, Brand $brand, int $week, array $vars = [], string $who = ''): string
    {
        return EmailTexts::pick($key, $save->rng("email.{$key}.{$brand->value}.{$who}", $week), $vars);
    }

    /**
     * "1 week" / "3 weeks".
     */
    public static function weeks(int $weeks): string
    {
        return $weeks === 1 ? '1 week' : "{$weeks} weeks";
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
            $this->text($save, 'contract_expired', $brand, $week),
            $week,
        );
    }

    /**
     * JR/Tazz says hello at the start of every season.
     */
    public function welcome(Save $save, Brand $brand, int $week): Email
    {
        $season = $this->calendar->seasonOf($week);

        $body = $this->text($save, $season === 1 ? 'welcome.first' : 'welcome.next', $brand, $week, ['brand' => $brand->label(), 'season' => $season]);

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
            $this->text($save, 'interference_reminder', $brand, $week, ['rival' => $rival->label(), 'weeks' => self::weeks($weeksUnused)]),
            $week,
        );
    }

    /**
     * A nudge after a long stretch without a Slander promo.
     */
    public function slanderReminder(Save $save, Brand $brand, int $weeksUnused, int $week): Email
    {
        return $this->send(
            $save,
            $brand,
            EmailTemplate::PromoReminder,
            'Time to take a shot at the other show?',
            ['promo' => 'slander_promotion', 'weeks' => $weeksUnused],
            $this->text($save, 'slander_reminder', $brand, $week, ['rival' => $this->rival($brand)->label(), 'weeks' => self::weeks($weeksUnused)]),
            $week,
        );
    }

    /**
     * A nudge after a long stretch without a trade offer to the rival GM.
     */
    public function tradeReminder(Save $save, Brand $brand, int $weeksIdle, int $week): Email
    {
        return $this->send(
            $save,
            $brand,
            EmailTemplate::TradeReminder,
            'Thought about making a trade?',
            ['weeks' => $weeksIdle],
            $this->text($save, 'trade_reminder', $brand, $week, ['rival' => $this->rival($brand)->label(), 'weeks' => self::weeks($weeksIdle)]),
            $week,
        );
    }

    /**
     * A nudge to look for specialists and Legends in Free Agency, naming a
     * couple who are free right now.
     */
    public function freeAgentReminder(Save $save, Brand $brand, int $weeksIdle, int $week): Email
    {
        $pool = SaveWrestler::with('wrestler.specialties')
            ->where('save_id', $save->id)
            ->whereNull('brand')
            ->whereNotIn('id', PendingRosterMove::where('save_id', $save->id)->whereNull('applied_week')->select('save_wrestler_id'))
            ->where(fn ($q) => $q->whereNull('unsignable_until_week')->orWhere('unsignable_until_week', '<=', $week))
            ->orderBy('id')
            ->get();
        $rng = $save->rng("email.free_agent_reminder.{$brand->value}", $week);
        $specialists = $pool->filter(fn (SaveWrestler $w): bool => ! $w->wrestler->isLegend() && $w->wrestler->specialties->isNotEmpty())->values()->all();
        $legends = $pool->filter(fn (SaveWrestler $w): bool => $w->wrestler->isLegend())->values()->all();

        $body = $this->text($save, 'free_agent_reminder', $brand, $week, ['weeks' => self::weeks($weeksIdle)]);
        $names = [];

        if ($specialists !== []) {
            /** @var SaveWrestler $specialist */
            $specialist = $rng->pick($specialists);
            $families = implode(' and ', array_map(fn (MatchFamily $f): string => $f->label(), $specialist->wrestler->specialtyFamilies()));
            $names[] = "{$specialist->name()} (a {$families} specialist)";
        }

        if ($legends !== []) {
            /** @var SaveWrestler $legend */
            $legend = $rng->pick($legends);
            $names[] = "the Legend {$legend->name()}";
        }

        if ($names !== []) {
            $body .= "\n\nRight now, ".implode(' and ', $names).' '.(count($names) === 1 ? 'is' : 'are').' sitting in the Free Agency pool.';
        }

        return $this->send($save, $brand, EmailTemplate::FreeAgentReminder, 'Anyone worth signing?', ['weeks' => $weeksIdle], $body, $week);
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
        $opening = $this->text($save, $accepted ? 'trade.accepted' : 'trade.declined', $proposer, $week);

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

        $subject = $succeeded ? 'You just made an enemy' : 'Nice try';
        $body = $this->text($save, $succeeded ? 'slander.success' : 'slander.failure', $brand, $week, ['rival' => $rival->label()]);

        return $this->send($save, $brand, EmailTemplate::SlanderResult, $subject, ['succeeded' => $succeeded], $body, $week, $this->rivalGm($brand));
    }

    /**
     * The rival GM reacts to the player's Interference promo on their show:
     * gloating when it was foiled, furious about the bill when it worked.
     */
    public function interferenceResult(Save $save, Brand $brand, bool $succeeded, int $week): Email
    {
        $rival = $this->rival($brand)->label();
        $show = $brand->label();

        $subject = $succeeded ? 'You owe me, and I always collect' : "Is that the best you've got?";
        $body = $this->text($save, $succeeded ? 'interference.success' : 'interference.failure', $brand, $week, ['rival' => $rival, 'brand' => $show]);

        return $this->send($save, $brand, EmailTemplate::InterferenceResult, $subject, ['succeeded' => $succeeded], $body, $week, $this->rivalGm($brand));
    }

    /**
     * The Trainer clears a seriously injured superstar: they can be booked
     * again from next week.
     */
    public function injuryCleared(Save $save, Brand $brand, SaveWrestler $wrestler, string $injury, int $week): Email
    {
        $name = $wrestler->name();
        $body = $this->text($save, 'injury.cleared', $brand, $week, ['name' => $name, 'injury' => $injury], $name);

        return $this->send($save, $brand, EmailTemplate::InjuryCleared, "{$name} is cleared to return", ['save_wrestler_id' => $wrestler->id, 'label' => $injury], $body, $week, (string) config('gm.email_senders.trainer'));
    }

    /**
     * JR / Tazz warn the player that the rival GM ran a Slander promo
     * against their show (house rule).
     */
    public function rivalSlander(Save $save, Brand $target, Brand $slanderer, bool $succeeded, int $week): Email
    {
        $subject = $succeeded ? "{$slanderer->label()} ran a smear campaign against us" : "{$slanderer->label()} tried to smear us";
        $body = $this->text($save, $succeeded ? 'rival_slander.success' : 'rival_slander.failure', $target, $week, ['rival' => $slanderer->label(), 'brand' => $target->label()]);

        return $this->send($save, $target, EmailTemplate::RivalSlander, $subject, ['succeeded' => $succeeded, 'rival_brand' => $slanderer->value], $body, $week);
    }

    /**
     * Who's ahead for the SummerSlam main event so far (house rule, 2026-11-01).
     */
    private function summerSlamRace(Save $save, Brand $brand, int $week): string
    {
        $totals = $this->summerSlam->totals($save, $this->calendar->seasonOf($week), $week + 1);
        $rival = $this->rival($brand);
        $mine = $totals[$brand->value]['crowd'];
        $theirs = $totals[$rival->value]['crowd'];

        return match (true) {
            $mine > $theirs => 'Right now you\'re ahead: '.number_format($mine).' fans to '.number_format($theirs).'.',
            $mine < $theirs => "Right now {$rival->label()} is ahead: ".number_format($theirs).' fans to your '.number_format($mine).'.',
            default => "Right now it's dead even.",
        };
    }

    /**
     * Vince's reminder ahead of a shared (Raw and SmackDown) pay-per-view.
     */
    public function ppvReminder(Save $save, Brand $brand, PpvEvent $ppv, int $weeksOut, int $week): ?Email
    {
        $body = match ($ppv->key) {
            PpvKey::Summerslam => "SummerSlam is {$weeksOut} weeks away. It's the first pay-per-view of the season where Raw and Smackdown share the card, so the whole company is watching.\n\nThe brand whose weekly shows draw the most fans this season gets the main event slot. {$this->summerSlamRace($save, $brand, $week)} Make every week count.",
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
            $this->text($wrestler->game, 'contract_reminder', $brand, $week, ['brand' => $brand->label(), 'weeks' => self::weeks($weeksLeft)], $wrestler->name()),
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
            $this->text($wrestler->game, 'injury_returning', $brand, $week, ['weeks' => self::weeks($weeksLeft)], $wrestler->name()),
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
            $this->text($save, 'low_cash', $brand, $week, ['amount' => $amount, 'brand' => $brand->label()]),
            $week,
        );
    }

    private function rival(Brand $brand): Brand
    {
        return $brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
    }
}
