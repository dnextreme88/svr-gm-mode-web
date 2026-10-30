<?php

namespace App\Services\Card;

/**
 * A randomizer suggestion for one slot (§24). Nothing is saved until the GM
 * confirms through the normal booking endpoint. A `titleShot` proposal is an
 * earned title match (Champion vs Contender) that Preset had to book.
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
        public bool $titleShot = false,
    ) {}

    /**
     * @param  array<int, string>  $names  save-wrestler id => name
     * @return array{position: int, match_type_id: int, match_type: string, cost: int, sides: list<list<int>>, side_names: list<list<string>>, title_championship_id: int|null, managers: array<int, int>, manager_names: array<int, string>, referee_id: int|null, referee_name: string|null, note: string|null}
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
            'managers' => $this->booking->managers,
            'manager_names' => array_map(fn (int $id): string => $names[$id] ?? (string) $id, $this->booking->managers),
            'referee_id' => $this->booking->refereeId,
            'referee_name' => $this->booking->refereeId === null ? null : ($names[$this->booking->refereeId] ?? null),
            'note' => $this->titleShot ? $this->titleShotNote($names) : null,
        ];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function titleShotNote(array $names): string
    {
        $contenders = array_map(fn (int $id): string => $names[$id] ?? (string) $id, $this->sides[1] ?? []);

        return sprintf(
            'Title shot: %s earned this match by winning a Champion vs Contender match, so it has to be booked.',
            implode(' & ', $contenders),
        );
    }
}
