<?php

namespace App\Services\Week\Guards;

use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use App\Services\Titles\TitleShotService;
use App\Services\Week\WeekGuard;

/**
 * §22: an earned title shot is a required match, and the card can't air
 * until the title match is booked.
 */
class TitleShotGuard implements WeekGuard
{
    public function __construct(private TitleShotService $shots) {}

    public function check(Save $save, int $week): void
    {
        $brand = $save->player_brand;
        $showIds = Show::where('save_id', $save->id)->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand'))
            ->pluck('id');
        $slots = MatchSlot::with('participants')->whereIn('show_id', $showIds)->get();

        foreach ($this->shots->due($save, $brand, $week) as $shot) {
            if (! $this->shots->isBooked($shot, $slots)) {
                throw new GameRuleException("Book the earned {$shot->championship->name} title shot before this week airs.");
            }
        }
    }
}
