<?php

namespace App\Http\Presenters;

use App\Models\Email;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Roster\RosterService;

/**
 * The broadcast header shown on every GM screen.
 */
class GamePresenter
{
    public function __construct(
        private CalendarService $calendar,
        private RosterService $roster,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shell(Save $save): array
    {
        $week = $save->current_week;
        $player = $save->brand($save->player_brand);
        $rival = $save->brand($save->rivalBrand());
        $next = $this->calendar->nextPpv($week, $save->player_brand);
        $limit = (int) config('gm.finances.deficit_weeks_limit');

        return [
            'id' => $save->id,
            'name' => $save->name,
            'player_brand' => $save->player_brand->value,
            'rival_brand' => $save->rivalBrand()->value,
            'stage' => $save->stage->value,
            'season' => $this->calendar->seasonOf($week),
            'week' => $week,
            'season_week' => $this->calendar->seasonWeek($week),
            'month' => $this->calendar->monthLabel($week),
            'date' => $this->calendar->date($week)->format('M j'),
            'budget' => $player->budget,
            'rival_budget' => $rival->budget,
            'roster_count' => $this->roster->rosterCount($save, $save->player_brand),
            'rival_roster_count' => $this->roster->rosterCount($save, $save->rivalBrand()),
            'roster_cap' => $this->roster->cap(),
            'unread_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->whereNull('read_at')->count(),
            'next_ppv' => $next === null ? null : [
                'name' => $next['ppv']->name,
                'week' => $next['week'],
                'weeks_away' => $next['week'] - $week,
            ],
            'fatigue_config' => [
                'bar_size' => (int) config('gm.fatigue.bar_size'),
                'safe_max' => (int) config('gm.fatigue.safe_max'),
                'serious_at' => (int) config('gm.fatigue.serious_at'),
                'max' => (int) config('gm.fatigue.max'),
            ],
            'deficit' => [
                'weeks' => $player->deficit_weeks,
                'limit' => $limit,
                'choice' => $player->deficit_choice,
                'blocked' => $player->deficit_weeks >= $limit && $player->deficit_choice === null,
            ],
        ];
    }
}
