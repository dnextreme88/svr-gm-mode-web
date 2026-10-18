<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Draft phase (§11).
 */
enum DraftPhase: string
{
    use HasValues;

    case Initial = 'initial';
    case WrestlemaniaReset = 'wrestlemania_reset';

    public function label(): string
    {
        return match ($this) {
            self::WrestlemaniaReset => 'WrestleMania Re-Draft',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
