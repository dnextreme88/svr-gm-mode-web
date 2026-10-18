<?php

namespace App\Services\Week\Guards;

use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Services\Week\WeekGuard;

/**
 * §7: running a deficit too long forces a choice before the next show airs.
 */
class DeficitChoiceGuard implements WeekGuard
{
    public function check(Save $save, int $week): void
    {
        $limit = (int) config('gm.finances.deficit_weeks_limit');

        $blocked = SaveBrand::where('save_id', $save->id)
            ->where('is_human', true)
            ->where('deficit_weeks', '>=', $limit)
            ->whereNull('deficit_choice')
            ->exists();

        if ($blocked) {
            throw new GameRuleException('You have been in deficit too long. Choose how to recover on the Financials screen before the next show.');
        }
    }
}
