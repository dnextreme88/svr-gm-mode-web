<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\TitleService;
use App\Services\Titles\TitleShotService;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * Title-match decisions for Randomize and Preset (§24).
 */
class TitleMatchPlanner
{
    public function __construct(
        private TitleService $titles,
        private LastChanceService $lastChance,
        private TitleShotService $shots,
    ) {}

    /**
     * Maybe turn a randomized slot into a title match. Only titles not already
     * on the card qualify; the champion(s) must be in the pool and every
     * challenger must be eligible and not locked out.
     *
     * The rival GM passes `$force` (it rolls the chance itself) and an
     * `$order` of championship ids to try, taking the first that fits
     * instead of a random one.
     *
     * @param  Collection<int, SaveWrestler>  $candidates
     * @param  list<int>  $titlesOnCard
     * @param  list<int>|null  $order
     * @return array{championship_id: int, sides: list<list<int>>}|null
     */
    public function plan(Show $show, Brand $brand, MatchType $type, Collection $candidates, array $titlesOnCard, Rng $rng, bool $force = false, ?array $order = null): ?array
    {
        if (! $type->titles_allowed || (! $force && ! $rng->chance((float) config('gm.card.randomize_title_chance')))) {
            return null;
        }

        $sizes = array_unique($type->sides);
        $ids = array_values(array_map('intval', $candidates->pluck('id')->all()));
        $options = [];
        $championships = Championship::where('brand', $brand)->orderBy('sort_order')->get();

        if ($order !== null) {
            $championships = $championships->filter(fn (Championship $c): bool => in_array($c->id, $order, true))
                ->sortBy(fn (Championship $c): int => (int) array_search($c->id, $order, true))
                ->values();
        }

        foreach ($championships as $championship) {
            if (in_array($championship->id, $titlesOnCard, true) || ($championship->is_tag ? $sizes !== [2] : $sizes !== [1])) {
                continue;
            }

            $reign = $this->titles->currentReign($show->game, $championship);

            if ($reign === null || array_diff($reign->holderIds(), $ids) !== []) {
                continue;
            }

            $locked = $this->lastChance->lockedIds($show->game)[$championship->id] ?? [];
            $challengers = $candidates
                ->reject(fn (SaveWrestler $w): bool => in_array($w->id, $reign->holderIds(), true) || in_array($w->id, $locked, true))
                ->filter(fn (SaveWrestler $w): bool => $championship->allowsWrestler($w->wrestler))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            $needed = $type->competitorCount() - count($reign->holderIds());

            if (count($challengers) >= $needed) {
                $options[] = ['championship' => $championship, 'holders' => $reign->holderIds(), 'challengers' => $challengers];
            }
        }

        if ($options === []) {
            return null;
        }

        $choice = $order !== null ? $options[0] : $rng->pick($options);
        $pool = $rng->shuffle($choice['challengers']);
        $sides = [$choice['holders']];

        foreach (array_slice($type->sides, 1) as $size) {
            $sides[] = array_splice($pool, 0, $size);
        }

        return ['championship_id' => $choice['championship']->id, 'sides' => $sides];
    }

    /**
     * Earned title shots due on this card, to be booked before anything else.
     * They go on the first card of the week (the weekly show).
     *
     * @return list<Booking>
     */
    public function obligations(Show $show, ?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }

        if ($show->isPpv() && Show::where('save_id', $show->save_id)->where('week_number', $show->week_number)->where('brand', $brand)->where('show_type', 'weekly')->exists()) {
            return [];
        }

        $slots = MatchSlot::with('participants')->where('show_id', $show->id)->get();
        $bookings = [];

        foreach ($this->shots->due($show->game, $brand, $show->week_number) as $shot) {
            if ($this->shots->isBooked($shot, $slots)) {
                continue;
            }

            $type = $shot->championship->is_tag
                ? MatchType::where('family', 'tag_team')->where('variant', 'Normal Tag')->firstOrFail()
                : MatchType::where('family', 'single')->where('variant', 'No Manager')->firstOrFail();

            $champions = $this->titles->currentReign($show->game, $shot->championship)?->holderIds() ?? $shot->champion_ids;

            $bookings[] = new Booking($type, [$champions, $shot->contender_ids], [], $shot->championship_id);
        }

        return $bookings;
    }
}
