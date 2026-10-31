<?php

namespace App\Services\Attendance;

use App\Enums\Brand;
use App\Models\Save;
use App\Models\Show;
use Illuminate\Support\Collection;

/**
 * A week's aired nights with their crowds (house rule, 2026-11-01): each
 * weekly show is a night, and a PPV's cards together are one.
 *
 * @phpstan-type Night array{key: string, week: int, name: string, ppv: bool, brands: list<Brand>, crowd: int, gate: int, kind: string, show_ids: list<int>}
 */
class AttendanceNights
{
    /**
     * @return list<Night>
     */
    public function forWeek(Save $save, int $week): array
    {
        return $this->group(Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->whereNotNull('attendance')
            ->orderBy('id')
            ->get()
            ->toBase());
    }

    /**
     * @param  Collection<int, Show>  $shows  shows with attendance
     * @return list<Night>
     */
    public function group(Collection $shows): array
    {
        $nights = [];

        foreach ($shows->groupBy(fn (Show $s): string => $s->week_number.':'.AttendanceService::nightKey($s)) as $key => $cards) {
            $first = $cards->first();
            $brands = $cards->contains(fn (Show $s): bool => $s->brand === null)
                ? Brand::cases()
                : array_map(fn (string $b): Brand => Brand::from($b), array_values(array_unique(array_map(fn (Show $s): string => $s->brand->value ?? '', $cards->all()))));

            $nights[] = [
                'key' => (string) $key,
                'week' => (int) $first->week_number,
                'name' => $first->isPpv() ? ($first->ppvEvent->name ?? $first->name) : $first->name,
                'ppv' => $first->isPpv(),
                'brands' => $brands,
                'crowd' => (int) $first->attendance,
                'gate' => (int) ($first->attendance_breakdown['gate'] ?? 0),
                'kind' => (string) ($first->attendance_breakdown['kind'] ?? 'weekly'),
                'show_ids' => array_values(array_map(fn (Show $s): int => $s->id, $cards->all())),
            ];
        }

        return $nights;
    }
}
