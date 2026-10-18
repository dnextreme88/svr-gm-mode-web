<?php

namespace App\Services\Card;

use App\Enums\Stipulation;
use App\Models\MatchType;

/**
 * A proposed booking for one match slot, before it's validated and saved.
 */
final readonly class Booking
{
    /**
     * @param  list<list<int>>  $sides  competitor save-wrestler ids per side
     * @param  array<int, int>  $managers  side index => manager save-wrestler id
     */
    public function __construct(
        public MatchType $matchType,
        public array $sides,
        public array $managers = [],
        public ?int $titleChampionshipId = null,
        public ?Stipulation $stipulation = null,
    ) {}

    /**
     * @return list<int>
     */
    public function competitorIds(): array
    {
        return array_merge(...$this->sides);
    }

    /**
     * @return list<int>
     */
    public function allIds(): array
    {
        return array_values(array_unique([...$this->competitorIds(), ...array_values($this->managers)]));
    }

    /**
     * @param  array{match_type_id: int, sides: list<list<int>>, managers?: array<int|string, int|null>, title_championship_id?: int|null, stipulation?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        $managers = [];

        foreach ($data['managers'] ?? [] as $side => $id) {
            if ($id !== null) {
                $managers[(int) $side] = (int) $id;
            }
        }

        return new self(
            MatchType::findOrFail($data['match_type_id']),
            array_map(fn (array $side): array => array_map('intval', $side), $data['sides']),
            $managers,
            isset($data['title_championship_id']) ? (int) $data['title_championship_id'] : null,
            isset($data['stipulation']) ? Stipulation::from($data['stipulation']) : null,
        );
    }
}
