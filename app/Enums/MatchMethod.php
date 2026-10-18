<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * How a match was won (§20).
 */
enum MatchMethod: string
{
    use HasValues;

    case Pinfall = 'pinfall';
    case Submission = 'submission';
    case Countout = 'countout';
    case Dq = 'dq';
    case MatchSpecific = 'match_specific';

    public function label(): string
    {
        return match ($this) {
            self::Countout => 'Count-Out',
            self::Dq => 'Disqualification',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
