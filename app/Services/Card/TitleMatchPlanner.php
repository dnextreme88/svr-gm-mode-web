<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Models\MatchType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * Title-match decisions for the randomizer (§24). Filled in with the title
 * rules in phase 4.
 */
class TitleMatchPlanner
{
    /**
     * Maybe turn a randomized slot into a title match.
     *
     * @param  Collection<int, SaveWrestler>  $candidates
     * @param  list<int>  $titlesOnCard
     * @return array{championship_id: int, sides: list<list<int>>}|null
     */
    public function plan(Show $show, MatchType $type, Collection $candidates, array $titlesOnCard, Rng $rng): ?array
    {
        return null;
    }

    /**
     * Pending title-shot obligations to book first (§22).
     *
     * @return list<Booking>
     */
    public function obligations(Show $show, ?Brand $brand): array
    {
        return [];
    }
}
