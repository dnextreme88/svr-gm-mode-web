<?php

namespace App\Http\Support;

use App\Enums\SaveStage;
use App\Models\Save;
use App\Models\WeekResolution;
use Illuminate\Http\Request;

/**
 * The end of an aired week (house rule, 2026-11-17): from the live results'
 * Continue the player goes through the week's Financials, then the News,
 * then the inbox, then back to the Schedule, with the GM header and menu bar
 * hidden the whole way so they stay on the week that just aired. Each step
 * carries `?wrap=1`; it only counts for the week just resolved.
 */
final class WrapUp
{
    public static function active(Request $request, Save $save): bool
    {
        return $request->boolean('wrap')
            && $save->stage === SaveStage::InSeason
            && $save->current_week > 1
            && WeekResolution::where('save_id', $save->id)->where('week_number', $save->current_week - 1)->exists();
    }
}
