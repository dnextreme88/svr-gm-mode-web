<?php

namespace App\Models;

use App\Enums\InjurySeverity;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wrestler injury (§6, §11). Active while the current week is before `ends_week`
 * and it hasn't been superseded by an escalation.
 *
 * @property int $id
 * @property int $save_wrestler_id
 * @property int $injury_type_id
 * @property InjurySeverity $severity
 * @property int $started_week
 * @property int $ends_week
 * @property bool $escalated_from_minor
 * @property int|null $escalated_from_id
 * @property int|null $healed_week
 * @property int|null $match_slot_id
 * @property int|null $fatigue_after uncapped fatigue right after the match that caused it
 * @property-read InjuryType $type
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
class Injury extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<InjuryType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(InjuryType::class, 'injury_type_id');
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    public function weeksLeft(int $week): int
    {
        return max(0, $this->ends_week - $week);
    }

    public function durationWeeks(): int
    {
        return $this->ends_week - $this->started_week;
    }

    protected function casts(): array
    {
        return [
            'severity' => InjurySeverity::class,
            'escalated_from_minor' => 'boolean',
        ];
    }
}
