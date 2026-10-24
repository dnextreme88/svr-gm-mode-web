<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A week whose shows are airing live: the player decides their own matches
 * one at a time on the results screen. Holds the week's resolution state
 * (`WeekContext::$data` and the show RNG streams) between requests.
 *
 * @property int $id
 * @property int $week_number
 * @property array<string, mixed> $context
 * @property array<string, string> $rng_states
 * @property Carbon|null $completed_at
 */
#[Unguarded]
class WeekAiring extends Model
{
    use BelongsToGame;

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'rng_states' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
