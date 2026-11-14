<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Injury;
use App\Models\PendingRosterMove;
use App\Models\PromoSlot;
use App\Models\Show;
use App\Models\Trade;
use App\Services\Attendance\RepeatMatches;
use App\Services\Calendar\CalendarService;
use App\Services\Mail\EmailService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Scheduled GM emails: the specialties tip twice a season, JR / Tazz's
 * reminders (Interference, Slander, trades, free agents: every N weeks since
 * the GM last did it), JR / Tazz's booking tips, Vince's shared-PPV
 * reminders, the rival GM's shared-PPV emails and superstars' return
 * notices.
 */
class SendReminders implements WeekStep
{
    public function __construct(
        private CalendarService $calendar,
        private EmailService $emails,
        private RepeatMatches $repeats,
    ) {}

    public function handle(WeekContext $context): void
    {
        $save = $context->save;
        $week = $context->week;

        foreach (Brand::cases() as $brand) {
            if (($this->calendar->seasonWeek($week) - 1) % (int) config('gm.emails.specialty_tip_every_weeks') === 0) {
                $this->emails->specialtyTip($save, $brand, $week);
            }

            $unused = $week - $this->lastPromo($context, $brand, PromoTypeKey::InterferencePromotion);

            if ($this->due($unused, 'interference_reminder_weeks')) {
                $this->emails->interferenceReminder($save, $brand, $unused, $week);
            }
        }

        // The rest only make sense for the player: the rival GM doesn't
        // read its email.
        $player = $save->player_brand;
        $unused = $week - $this->lastPromo($context, $player, PromoTypeKey::SlanderPromotion);

        if ($this->due($unused, 'slander_reminder_weeks')) {
            $this->emails->slanderReminder($save, $player, $unused, $week);
        }

        $idle = $week - (int) Trade::where('save_id', $save->id)->where('proposer_brand', $player)->max('week_number');

        if ($this->due($idle, 'trade_reminder_weeks')) {
            $this->emails->tradeReminder($save, $player, $idle, $week);
        }

        // A signing joins the roster the week after it was made.
        $signed = PendingRosterMove::where('save_id', $save->id)->where('brand', $player)->max('effective_week');
        $idle = $week - ($signed === null ? 0 : (int) $signed - 1);

        if ($this->due($idle, 'free_agent_reminder_weeks')) {
            $this->emails->freeAgentReminder($save, $player, $idle, $week);
        }

        // House rule (2026-11-11): JR / Tazz's booking tips, by season week.
        $seasonWeek = $this->calendar->seasonWeek($week);
        $variety = (int) config('gm.emails.variety_tip_weeks');
        $slots = (int) config('gm.emails.slot_tip_weeks');

        if ($variety > 0 && $seasonWeek % $variety === 0) {
            $this->emails->bookingTip($save, $player, 'variety', $week, $this->repeatsThisWeek($context, $player));
        }

        if ($slots > 0 && $seasonWeek % $slots === (int) config('gm.emails.slot_tip_offset') % $slots) {
            $this->emails->bookingTip($save, $player, 'slots', $week);
        }

        // House rule (2026-11-11): the rival GM writes before each shared PPV.
        foreach ($this->calendar->ppvsForWeek($week + (int) config('gm.emails.rival_ppv_weeks_before')) as $ppv) {
            if ($ppv->isShared()) {
                $this->emails->rivalPpvTaunt($save, $player, $ppv, $week);
            }
        }

        $weeksOut = (int) config('gm.emails.ppv_reminder_weeks_before');

        foreach ($this->calendar->ppvsForWeek($week + $weeksOut) as $ppv) {
            if ($ppv->isShared()) {
                foreach (Brand::cases() as $brand) {
                    $this->emails->ppvReminder($save, $brand, $ppv, $weeksOut, $week);
                }
            }
        }

        $notice = (int) config('gm.emails.injury_return_notice_weeks');

        // Return news fires the week before `ends_week`, so the notice comes
        // `$notice` weeks ahead of that.
        $returning = Injury::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('severity', InjurySeverity::Serious)
            ->whereNull('healed_week')
            ->where('ends_week', $week + 1 + $notice)
            ->get();

        foreach ($returning as $injury) {
            $wrestler = $injury->saveWrestler;

            if ($wrestler->brand !== null) {
                $this->emails->injuryReturning($wrestler, $wrestler->brand, $notice, $week);
            }
        }
    }

    /**
     * Every `gm.emails.{$key}` weeks since the GM last did it.
     */
    private function due(int $weeks, string $key): bool
    {
        $every = (int) config("gm.emails.{$key}");

        return $weeks > 0 && $every > 0 && $weeks % $every === 0;
    }

    /**
     * The last week this brand aired a promo of this type (0 if never).
     */
    private function lastPromo(WeekContext $context, Brand $brand, PromoTypeKey $key): int
    {
        return (int) PromoSlot::query()
            ->join('shows', 'shows.id', '=', 'promo_slots.show_id')
            ->where('shows.save_id', $context->save->id)
            ->where('shows.brand', $brand)
            ->where('shows.status', ShowStatus::Aired)
            ->whereNotNull('promo_slots.succeeded')
            ->whereHas('promoType', fn ($q) => $q->where('key', $key))
            ->max('shows.week_number');
    }

    /**
     * Slots on the brand's weekly show this week booked in the same match
     * category as the week before (house rule, 2026-11-08).
     */
    private function repeatsThisWeek(WeekContext $context, Brand $brand): int
    {
        $show = Show::where('save_id', $context->save->id)
            ->where('week_number', $context->week)
            ->where('brand', $brand)
            ->where('show_type', ShowType::Weekly)
            ->first();

        return $show === null ? 0 : count($this->repeats->forShow($show));
    }
}
