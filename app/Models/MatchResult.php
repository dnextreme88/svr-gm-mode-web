<?php

namespace App\Models;

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a match ended (§20).
 *
 * @property int $id
 * @property int $match_slot_id
 * @property int $winner_side
 * @property MatchMethod $method
 * @property FinishEvent|null $finish_event
 * @property int|null $interferer_id
 * @property bool $booked the player booked the winner (Book to Win)
 * @property bool $title_changed
 * @property int|null $champion_reign_id the reign on the line going in
 * @property string|null $title_banner
 * @property string|null $stipulation_outcome
 * @property list<array{championship_id: int, reign_id: int}>|null $titles_won
 * @property string $summary
 * @property int $rng_seed_step
 * @property-read MatchSlot $matchSlot
 * @property-read SaveWrestler|null $interferer
 * @property-read TitleReign|null $championReign
 */
#[Unguarded]
class MatchResult extends Model
{
    /**
     * @return BelongsTo<MatchSlot, $this>
     */
    public function matchSlot(): BelongsTo
    {
        return $this->belongsTo(MatchSlot::class);
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function interferer(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'interferer_id');
    }

    /**
     * @return BelongsTo<TitleReign, $this>
     */
    public function championReign(): BelongsTo
    {
        return $this->belongsTo(TitleReign::class, 'champion_reign_id');
    }

    /**
     * Titles change hands only on a decisive win (§20).
     */
    public function isDecisive(): bool
    {
        return ! in_array($this->method, [MatchMethod::Dq, MatchMethod::Countout], true);
    }

    protected function casts(): array
    {
        return [
            'method' => MatchMethod::class,
            'finish_event' => FinishEvent::class,
            'title_changed' => 'boolean',
            'titles_won' => 'array',
            'booked' => 'boolean',
        ];
    }
}
