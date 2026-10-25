<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * What a superstar grabs in a Foreign Object finish (house rule), drawn with
 * the seeded RNG.
 */
enum Weapon: string
{
    use HasValues;

    case SteelChair = 'steel_chair';
    case Sledgehammer = 'sledgehammer';
    case RingBell = 'ring_bell';
    case SteelSteps = 'steel_steps';

    public function label(): string
    {
        return Str::of($this->name)->headline()->toString();
    }

    /**
     * The weapon with its article: "a steel chair", "the ring bell".
     */
    public function noun(): string
    {
        return match ($this) {
            self::SteelChair => 'a steel chair',
            self::Sledgehammer => 'a sledgehammer',
            self::RingBell => 'the ring bell',
            self::SteelSteps => 'the steel steps',
        };
    }

    /**
     * The attack as a `FinishText` template, e.g. "[levels|level] {L} with a
     * steel chair".
     */
    public function attack(): string
    {
        return match ($this) {
            self::SteelChair => '[levels|level] {L} with a steel chair',
            self::Sledgehammer => '[swings|swing] a sledgehammer into {L}',
            self::RingBell => '[clocks|clock] {L} with the ring bell',
            self::SteelSteps => '[drives|drive] {L} into the steel steps',
        };
    }
}
