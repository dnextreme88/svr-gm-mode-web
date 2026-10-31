<?php

namespace App\Http\Presenters;

use App\Enums\Brand;
use App\Enums\TitleShotKind;
use App\Enums\TitleShotStatus;
use App\Models\Championship;
use App\Models\LastChanceLockout;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use App\Models\TitleReign;
use App\Models\TitleShot;
use App\Services\Factions\FactionNamer;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleContention;
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
        private FactionNamer $factions,
        private TitleContention $contention,
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
        $names = fn (array $ids): string => $this->factions->name($save->id, array_map('intval', $ids), $show->week_number);

        $shots = $this->shots->due($save, $brand, $show->week_number)->map(fn ($shot): array => [
            'id' => $shot->id,
            'championship_id' => $shot->championship_id,
            'championship' => $shot->championship->name,
            'contender_ids' => $shot->contender_ids,
            'contenders' => $names($shot->contender_ids),
            'booked' => $this->shots->isBooked($shot, $slots),
        ])->values();

        $briefcase = $this->mitb->held($save)->first(fn ($b): bool => $b->brand === $brand);

        // House rule (2026-10-31): #1 contenders waiting for their title
        // match, titles with an earned shot still to come, and everyone who
        // holds a title (a Champion vs. Contender contender can't).
        $contenderships = $this->contention->contenderships($save)
            ->filter(fn (TitleShot $shot): bool => $shot->brand === $brand)
            ->map(fn (TitleShot $shot): array => [
                'championship_id' => $shot->championship_id,
                'championship' => $shot->championship->name,
                'contender_ids' => $shot->contender_ids,
                'contenders' => $names($shot->contender_ids),
            ])->values();
        $earned = TitleShot::where('save_id', $save->id)
            ->where('kind', TitleShotKind::ChampionVsContender)
            ->where('status', TitleShotStatus::Pending)
            ->pluck('championship_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $holders = $this->titles->currentReigns($save)
            ->flatMap(fn (TitleReign $reign): array => $reign->holderIds())
            ->unique()
            ->values();

        return [
            'championships' => $titles,
            'lockouts' => (object) $this->lastChance->lockedIds($save),
            'last_chance' => $lastChance,
            'title_shots' => $shots,
            'contenderships' => $contenderships,
            'earned_shot_title_ids' => $earned,
            'title_holder_ids' => $holders,
            // Active Effects: who lost a Last Chance match and can't challenge
            // that champion until the title changes hands.
            'last_chance_lockouts' => LastChanceLockout::with('reign.championship')
                ->where('save_id', $save->id)
                ->orderBy('id')
                ->get()
                ->filter(fn (LastChanceLockout $l): bool => $l->reign->lost_week === null && $l->reign->championship->brand === $brand)
                ->map(fn (LastChanceLockout $l): array => [
                    'id' => $l->id,
                    'championship' => $l->reign->championship->name,
                    'contenders' => $names($l->wrestler_ids),
                    'champion' => $l->reign->holderName(),
                ])
                ->values(),
            'briefcase' => $briefcase === null ? null : [
                'id' => $briefcase->id,
                'holder' => $briefcase->holder->name(),
                'expires_week' => $briefcase->expires_week,
                'cashed_in_here' => $slots->contains(fn (MatchSlot $s): bool => $s->id === $briefcase->cash_in_match_id),
            ],
        ];
    }
}
