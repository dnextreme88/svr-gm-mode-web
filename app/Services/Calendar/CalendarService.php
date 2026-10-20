<?php

namespace App\Services\Calendar;

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Models\PpvEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Maps absolute save weeks onto seasons, months and PPVs (§13).
 *
 * Week numbers are absolute across a save. Each season is
 * `gm.calendar.weeks_per_season` weeks long and ends on WrestleMania.
 */
class CalendarService
{
    /** @var Collection<int, PpvEvent>|null */
    private ?Collection $ppvs = null;

    public function weeksPerSeason(): int
    {
        return (int) config('gm.calendar.weeks_per_season');
    }

    public function seasonOf(int $week): int
    {
        return intdiv($week - 1, $this->weeksPerSeason()) + 1;
    }

    public function seasonWeek(int $week): int
    {
        return (($week - 1) % $this->weeksPerSeason()) + 1;
    }

    public function absoluteWeek(int $season, int $seasonWeek): int
    {
        return ($season - 1) * $this->weeksPerSeason() + $seasonWeek;
    }

    public function firstWeekOfSeason(int $season): int
    {
        return $this->absoluteWeek($season, 1);
    }

    /**
     * The Sunday a week starts on, when its PPVs air. Every season runs on
     * the same continuous calendar, April 2005 to March 2006 (§13): week 1
     * is Sunday Apr 3, 2005 and WrestleMania's week 52 is Sunday Mar 26, 2006.
     */
    public function date(int $week): CarbonImmutable
    {
        $start = CarbonImmutable::parse((string) config('gm.calendar.season_start_reference'));

        return $start->addWeeks($this->seasonWeek($week) - 1);
    }

    /**
     * The night a brand's weekly show airs: its configured weekday after the
     * week's Sunday (Raw Monday, SmackDown Friday). Weeks start on Sunday,
     * so a week's PPV always airs before its weekly shows.
     */
    public function showDate(int $week, Brand $brand): CarbonImmutable
    {
        $day = (string) config("gm.calendar.weekly_show_days.{$brand->value}");

        return $this->date($week)->next($day);
    }

    public function monthLabel(int $week): string
    {
        return $this->date($week)->format('F');
    }

    /**
     * PPVs that air in a week. Pass a brand to only get PPVs that brand runs.
     *
     * @return Collection<int, PpvEvent>
     */
    public function ppvsForWeek(int $week, ?Brand $brand = null): Collection
    {
        $seasonWeek = $this->seasonWeek($week);

        return $this->ppvs()
            ->filter(fn (PpvEvent $ppv): bool => $ppv->season_week === $seasonWeek)
            ->filter(fn (PpvEvent $ppv): bool => $brand === null || $ppv->runsOn($brand))
            ->values();
    }

    public function ppvForWeek(int $week, Brand $brand): ?PpvEvent
    {
        return $this->ppvsForWeek($week, $brand)->first();
    }

    /**
     * The next PPV a brand runs at or after $week, with its absolute week.
     *
     * @return array{ppv: PpvEvent, week: int}|null
     */
    public function nextPpv(int $week, Brand $brand): ?array
    {
        for ($w = $week; $w < $week + $this->weeksPerSeason(); $w++) {
            $ppv = $this->ppvForWeek($w, $brand);

            if ($ppv !== null) {
                return ['ppv' => $ppv, 'week' => $w];
            }
        }

        return null;
    }

    public function isPpvWeek(int $week, PpvKey $key): bool
    {
        return $this->ppvsForWeek($week)->contains(fn (PpvEvent $ppv): bool => $ppv->key === $key);
    }

    public function isWrestleManiaWeek(int $week): bool
    {
        return $this->isPpvWeek($week, PpvKey::Wrestlemania);
    }

    /**
     * The season as calendar months, April 2005 to March 2006, for the
     * Schedule screen. Each month is a Sunday-first grid of whole weeks
     * (with the neighbouring months' days filling the first and last rows),
     * and every show night carries what airs on it.
     *
     * @return list<array{key: string, label: string, rows: list<list<array{date: string, day: int, in_month: bool, airs: list<array{week: int, season_week: int, kind: string, brand: string|null, ppv: array{key: string, name: string, brand_scope: string}|null}>}>>}>
     */
    public function seasonMonths(int $season): array
    {
        $airs = [];

        for ($sw = 1; $sw <= $this->weeksPerSeason(); $sw++) {
            $week = $this->absoluteWeek($season, $sw);

            foreach ($this->ppvsForWeek($week) as $ppv) {
                $airs[$this->date($week)->toDateString()][] = [
                    'week' => $week,
                    'season_week' => $sw,
                    'kind' => 'ppv',
                    'brand' => $ppv->isShared() ? null : $ppv->brand_scope->value,
                    'ppv' => ['key' => $ppv->key->value, 'name' => $ppv->name, 'brand_scope' => $ppv->brand_scope->value],
                ];
            }

            foreach (Brand::cases() as $brand) {
                $airs[$this->showDate($week, $brand)->toDateString()][] = [
                    'week' => $week,
                    'season_week' => $sw,
                    'kind' => 'weekly',
                    'brand' => $brand->value,
                    'ppv' => null,
                ];
            }
        }

        $first = $this->date($this->absoluteWeek($season, 1))->startOfMonth();
        $months = [];

        for ($m = 0; $m < 12; $m++) {
            $month = $first->addMonthsNoOverflow($m);
            $day = $month->startOfWeek(CarbonImmutable::SUNDAY);
            $last = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);
            $rows = [];

            while ($day <= $last) {
                $row = [];

                for ($i = 0; $i < 7; $i++) {
                    $row[] = [
                        'date' => $day->toDateString(),
                        'day' => $day->day,
                        'in_month' => $day->month === $month->month,
                        'airs' => $day->month === $month->month ? ($airs[$day->toDateString()] ?? []) : [],
                    ];
                    $day = $day->addDay();
                }

                $rows[] = $row;
            }

            $months[] = ['key' => $month->format('Y-m'), 'label' => $month->format('F Y'), 'rows' => $rows];
        }

        return $months;
    }

    /**
     * @return Collection<int, PpvEvent>
     */
    private function ppvs(): Collection
    {
        return $this->ppvs ??= PpvEvent::query()->orderBy('season_week')->get();
    }
}
