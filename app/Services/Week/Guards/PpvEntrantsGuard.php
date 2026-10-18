<?php

namespace App\Services\Week\Guards;

use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\Show;
use App\Services\Ppv\PpvCardService;
use App\Services\Week\WeekGuard;

/**
 * Each GM picks their Royal Rumble entrants and Survivor Series team before
 * the PPV airs (§10).
 */
class PpvEntrantsGuard implements WeekGuard
{
    public function __construct(private PpvCardService $ppvCards) {}

    public function check(Save $save, int $week): void
    {
        $shows = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->get();

        foreach ($shows as $show) {
            if ($show->isInterbrandMainEvent() && ! $this->ppvCards->entrantsSet($show, $save->player_brand)
                && $this->ppvCards->rumbleEntrantsFor($show, $save->player_brand) > 0) {
                throw new GameRuleException("Pick your entrants for {$show->name} before the PPV airs.");
            }
        }
    }
}
