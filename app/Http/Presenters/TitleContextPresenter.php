<?php

namespace App\Http\Presenters;

use App\Enums\Brand;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Services\Titles\TitleShotService;

/**
 * What the card builder needs to offer titles and stipulations (§4, §22).
 */
class TitleContextPresenter
{
    public function __construct(
        private TitleService $titles,
        private LastChanceService $lastChance,
        private TitleShotService $shots,
        private MitbService $mitb,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forShow(Save $save, Show $show, Brand $brand): array
    {
        $championships = Championship::where('brand', $brand)->orderBy('sort_order')->get();
        $lastChance = [];
        $titles = [];

        foreach ($championships as $c) {
            $reign = $this->titles->currentReign($save, $c);

            if ($reign !== null) {
                $lastChance = [...$lastChance, ...$this->lastChance->availableFor($save, $reign)];
            }

            $titles[] = [
                'id' => $c->id,
                'name' => $c->name,
                'is_tag' => $c->is_tag,
                'eligibility' => $c->eligibility->value,
                'holder_ids' => $reign?->holderIds() ?? [],
                'holder_name' => $reign?->holderName(),
            ];
        }

        $slots = MatchSlot::with('participants')->where('show_id', $show->id)->get();
        $names = fn (array $ids): string => SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()->map(fn (SaveWrestler $w): string => $w->name())->implode(' & ');

        $shots = $this->shots->due($save, $brand, $show->week_number)->map(fn ($shot): array => [
            'id' => $shot->id,
            'championship_id' => $shot->championship_id,
            'championship' => $shot->championship->name,
            'contender_ids' => $shot->contender_ids,
            'contenders' => $names($shot->contender_ids),
            'booked' => $this->shots->isBooked($shot, $slots),
        ])->values();

        $briefcase = $this->mitb->held($save)->first(fn ($b): bool => $b->brand === $brand);

        return [
            'championships' => $titles,
            'lockouts' => (object) $this->lastChance->lockedIds($save),
            'last_chance' => $lastChance,
            'title_shots' => $shots,
            'briefcase' => $briefcase === null ? null : [
                'id' => $briefcase->id,
                'holder' => $briefcase->holder->name(),
                'expires_week' => $briefcase->expires_week,
                'cashed_in_here' => $slots->contains(fn (MatchSlot $s): bool => $s->id === $briefcase->cash_in_match_id),
            ],
        ];
    }
}
