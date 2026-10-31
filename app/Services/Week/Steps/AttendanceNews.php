<?php

namespace App\Services\Week\Steps;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Services\Attendance\AttendanceNights;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Fan attendance (house rule, 2026-11-01): once every show has aired, the
 * week's biggest crowd makes the WWE card, naming the other nights' crowds
 * in its summary. It takes the WWE headline unless a PPV main event or a
 * Slander/Interference result does (`NewsComposer`).
 */
class AttendanceNews implements WeekStep
{
    public const VARIANTS = ['packed', 'biggest', 'spoken', 'house'];

    public function __construct(
        private AttendanceNights $nights,
        private NewsRecorder $news,
    ) {}

    public function handle(WeekContext $context): void
    {
        $dedupe = "attendance:{$context->week}";
        $nights = $this->nights->forWeek($context->save, $context->week);

        if ($nights === [] || NewsEvent::where('save_id', $context->save->id)->where('dedupe_key', $dedupe)->exists()) {
            return;
        }

        usort($nights, fn (array $a, array $b): int => $b['crowd'] <=> $a['crowd']);
        $top = array_shift($nights);
        $others = array_map(fn (array $n): string => "{$n['name']} drew ".number_format($n['crowd']), $nights);

        $this->news->record($context->save, NewsEventType::Attendance, NewsTier::Headline, NewsBrand::Both, [
            'show' => $top['name'],
            'crowd' => number_format($top['crowd']),
            'others' => $others === [] ? '' : ' '.$this->sentence($others).'.',
            'outcome' => $context->rng('news.attendance')->pick(self::VARIANTS),
        ], ['show_ids' => $top['show_ids']], priority: (int) config('gm.news.attendance_priority'), week: $context->week, dedupeKey: $dedupe);
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $parts
     */
    private function sentence(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts)." and {$last}";
    }
}
