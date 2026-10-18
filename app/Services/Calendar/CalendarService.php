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
     * The calendar date a week airs. Every season runs on 2005's Sundays:
     * weeks that would spill into the next year (Jan–Mar) fold back 52 weeks
     * onto 2005, so the PPVs land on their listed dates (§13). Only the month
     * and day are shown in-game, and every season repeats the same dates.
     */
    public function date(int $week): CarbonImmutable
    {
        $start = CarbonImmutable::parse((string) config('gm.calendar.season_start_reference'));
        $date = $start->addWeeks($this->seasonWeek($week) - 1);

        return $date->year > $start->year ? $date->subWeeks(52) : $date;
    }

    /**
     * The night a brand's weekly show airs: its configured weekday before the
     * week's Sunday (Raw Monday, SmackDown Friday). PPVs air on the Sunday
     * itself, which is `date()`.
     */
    public function showDate(int $week, Brand $brand): CarbonImmutable
    {
        $day = (string) config("gm.calendar.weekly_show_days.{$brand->value}");

        return $this->date($week)->previous($day);
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
     * Weeks of a season grouped by month, for the Schedule screen. Pass a
     * brand to group by that brand's weekly show night instead of the Sunday.
     *
     * @return list<array{month: string, weeks: list<array{week: int, season_week: int, date: string, show_dates: array<string, string>, ppvs: list<array{key: string, name: string, brand_scope: string}>}>}>
     */
    public function seasonGrid(int $season, ?Brand $brand = null): array
    {
        $months = [];
        $current = null;
        $weeks = [];

        for ($sw = 1; $sw <= $this->weeksPerSeason(); $sw++) {
            $week = $this->absoluteWeek($season, $sw);
            $month = ($brand === null ? $this->date($week) : $this->showDate($week, $brand))->format('F');

            if ($current !== null && $current !== $month) {
                $months[] = ['month' => $current, 'weeks' => $weeks];
                $weeks = [];
            }

            $current = $month;
            $weeks[] = [
                'week' => $week,
                'season_week' => $sw,
                'date' => $this->date($week)->format('D M j'),
                'show_dates' => [
                    Brand::Raw->value => $this->showDate($week, Brand::Raw)->format('D M j'),
                    Brand::Smackdown->value => $this->showDate($week, Brand::Smackdown)->format('D M j'),
                ],
                'ppvs' => array_values($this->ppvsForWeek($week)->map(fn (PpvEvent $ppv): array => [
                    'key' => $ppv->key->value,
                    'name' => $ppv->name,
                    'brand_scope' => $ppv->brand_scope->value,
                ])->all()),
            ];
        }

        if ($current !== null) {
            $months[] = ['month' => $current, 'weeks' => $weeks];
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
