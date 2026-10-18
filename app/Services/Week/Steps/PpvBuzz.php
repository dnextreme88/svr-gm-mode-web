<?php

namespace App\Services\Week\Steps;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Services\Calendar\CalendarService;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * "{ppv} is {n} weeks away" news at 2 weeks (story) and 1 week (headline) out (§19).
 */
class PpvBuzz implements WeekStep
{
    public function __construct(
        private CalendarService $calendar,
        private NewsRecorder $news,
    ) {}

    public function handle(WeekContext $context): void
    {
        foreach ((array) config('gm.news.ppv_upcoming_weeks') as $n) {
            $n = (int) $n;

            foreach ($this->calendar->ppvsForWeek($context->week + $n) as $ppv) {
                $brand = $ppv->isShared() ? NewsBrand::Both : NewsBrand::from($ppv->brand_scope->value);

                $this->news->record($context->save, NewsEventType::PpvUpcoming, $n === 1 ? NewsTier::Headline : NewsTier::Story, $brand, [
                    'ppv' => $ppv->name,
                    'n' => $n,
                ], ['ppv_event_id' => $ppv->id], priority: $n === 1 ? 55 : 15, week: $context->week);
            }
        }
    }
}
