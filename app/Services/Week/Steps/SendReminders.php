<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Models\Injury;
use App\Models\PromoSlot;
use App\Services\Calendar\CalendarService;
use App\Services\Mail\EmailService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Scheduled GM emails: the specialties tip twice a season, the Interference
 * reminder, Vince's shared-PPV reminders and superstars' return notices.
 */
class SendReminders implements WeekStep
{
    public function __construct(
        private CalendarService $calendar,
        private EmailService $emails,
    ) {}

    public function handle(WeekContext $context): void
    {
        $save = $context->save;
        $week = $context->week;

        foreach (Brand::cases() as $brand) {
            if (($this->calendar->seasonWeek($week) - 1) % (int) config('gm.emails.specialty_tip_every_weeks') === 0) {
                $this->emails->specialtyTip($save, $brand, $week);
            }

            $unused = $week - $this->lastInterference($context, $brand);

            if ($unused === (int) config('gm.emails.interference_reminder_weeks')) {
                $this->emails->interferenceReminder($save, $brand, $unused, $week);
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
     * The last week this brand aired an Interference promo (0 if never).
     */
    private function lastInterference(WeekContext $context, Brand $brand): int
    {
        return (int) PromoSlot::query()
            ->join('shows', 'shows.id', '=', 'promo_slots.show_id')
            ->where('shows.save_id', $context->save->id)
            ->where('shows.brand', $brand)
            ->where('shows.status', ShowStatus::Aired)
            ->whereNotNull('promo_slots.succeeded')
            ->whereHas('promoType', fn ($q) => $q->where('key', PromoTypeKey::InterferencePromotion))
            ->max('shows.week_number');
    }
}
