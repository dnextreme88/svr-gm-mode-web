<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Finish event (§20).
 */
enum FinishEvent: string
{
    use HasValues;

    case RefereeAttacked = 'referee_attacked';
    case RivalInterference = 'rival_interference';
    case ForeignObject = 'foreign_object';
    case Countout = 'countout';
    case CheapRollup = 'cheap_rollup';

    public function label(): string
    {
        return match ($this) {
            self::Countout => 'Count-Out',
            self::CheapRollup => 'Cheap Roll-Up',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
