<?php

namespace App\Services\News;

use App\Enums\Brand;
use App\Enums\ShowType;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;

/**
 * Each brand's News ticker (house rule, 2026-11-17): a summarized line for
 * every match on its weekly show that week ("Edge def. Kane · Ladder —
 * Single"). In a week the brand is on a PPV (its own or a shared one) the
 * PPV's matches are left to the headlines and stories, and the ticker lists
 * its weekly show from the week before instead.
 */
class BrandTickers
{
    /**
     * @return array<string, list<string>> brand => result lines, in card order
     */
    public function forWeek(Save $save, int $week): array
    {
        $lines = [];

        foreach (Brand::cases() as $brand) {
            $lines[$brand->value] = $this->lines($save, $brand, $this->sourceWeek($save, $brand, $week));
        }

        return $lines;
    }

    /**
     * The week whose weekly show the brand's ticker lists.
     */
    public function sourceWeek(Save $save, Brand $brand, int $week): int
    {
        $onPpv = Show::where('save_id', $save->id)
            ->where('week_number', $week)
            ->where('show_type', ShowType::Ppv)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand'))
            ->exists();

        return $onPpv ? max(1, $week - 1) : $week;
    }

    /**
     * @return list<string>
     */
    private function lines(Save $save, Brand $brand, int $week): array
    {
        $show = Show::where('save_id', $save->id)
            ->where('week_number', $week)
            ->where('show_type', ShowType::Weekly)
            ->where('brand', $brand)
            ->first();

        if ($show === null) {
            return [];
        }

        $lines = [];
        $slots = MatchSlot::with(['participants.saveWrestler.wrestler', 'result.championReign', 'matchType', 'titleChampionship', 'show'])
            ->where('show_id', $show->id)
            ->whereHas('result')
            ->orderBy('position')
            ->get();

        foreach ($slots as $slot) {
            $outcome = CardPresenter::outcome($slot);
            $losers = implode(', ', $outcome['losers']);
            $title = $slot->titleChampionship?->name;
            $lines[] = "{$outcome['winners_label']} def. {$losers} · {$outcome['match_type']}".($title === null ? '' : " · {$title}");
        }

        return $lines;
    }
}
