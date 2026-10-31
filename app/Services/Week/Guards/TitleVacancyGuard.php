<?php

namespace App\Services\Week\Guards;

use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Services\Titles\ContendershipService;
use App\Services\Week\WeekGuard;

/**
 * House rule: a vacated title with a #1 contender waiting needs the
 * player's answer (award it or forfeit the contendership) before the week
 * airs.
 */
class TitleVacancyGuard implements WeekGuard
{
    public function __construct(private ContendershipService $contenderships) {}

    public function check(Save $save, int $week): void
    {
        $prompt = $this->contenderships->pendingPrompt($save);

        if ($prompt !== null) {
            throw new GameRuleException("Decide whether the vacant {$prompt->championship->name} goes to its #1 contender before this week airs.");
        }
    }
}
