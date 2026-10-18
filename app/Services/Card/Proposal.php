<?php

namespace App\Services\Card;

/**
 * A randomizer suggestion for one slot (§24). Nothing is saved until the GM
 * confirms through the normal booking endpoint.
 */
final readonly class Proposal
{
    /**
     * @param  list<list<int>>  $sides
     */
    public function __construct(
        public int $position,
        public Booking $booking,
        public array $sides,
    ) {}

    /**
     * @param  array<int, string>  $names  save-wrestler id => name
     * @return array{position: int, match_type_id: int, match_type: string, cost: int, sides: list<list<int>>, side_names: list<list<string>>, title_championship_id: int|null, managers: array<int, int>}
     */
    public function toArray(array $names = []): array
    {
        return [
            'position' => $this->position,
            'match_type_id' => $this->booking->matchType->id,
            'match_type' => $this->booking->matchType->name,
            'cost' => $this->booking->matchType->cost,
            'sides' => $this->sides,
            'side_names' => array_map(fn (array $side): array => array_map(fn (int $id): string => $names[$id] ?? (string) $id, $side), $this->sides),
            'title_championship_id' => $this->booking->titleChampionshipId,
            'managers' => [],
        ];
    }
}
