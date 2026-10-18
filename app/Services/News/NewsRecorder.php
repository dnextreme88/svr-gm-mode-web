<?php

namespace App\Services\News;

use App\Enums\Brand;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Services\Calendar\CalendarService;

/**
 * Writes §19 news events. Layout (slots, promotion, dedupe) happens when the
 * week's page is composed, so events are recorded as they happen.
 */
class NewsRecorder
{
    public function __construct(
        private NewsTemplates $templates,
        private CalendarService $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  template placeholders
     * @param  array<string, mixed>  $subjects  wrestler/title/show/PPV ids
     */
    public function record(
        Save $save,
        NewsEventType $type,
        NewsTier $tier,
        NewsBrand|Brand $brand,
        array $payload = [],
        array $subjects = [],
        int $priority = 0,
        ?int $week = null,
        ?string $dedupeKey = null,
        ?int $relatedEmailId = null,
    ): NewsEvent {
        $week ??= $save->current_week;
        $newsBrand = $brand instanceof Brand ? NewsBrand::from($brand->value) : $brand;

        return NewsEvent::create([
            'save_id' => $save->id,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'type' => $type,
            'tier' => $tier,
            'brand' => $newsBrand,
            'priority' => $priority,
            'subject_ids' => $subjects,
            'payload' => $payload,
            'rendered_text' => $this->templates->render($type, $payload),
            'dedupe_key' => $dedupeKey,
            'related_email_id' => $relatedEmailId,
        ]);
    }
}
