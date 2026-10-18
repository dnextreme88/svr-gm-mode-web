<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Shared helpers for the game's backed enums.
 */
trait HasValues
{
    /**
     * @return list<string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Human-readable label shown in the UI.
     */
    public function label(): string
    {
        return Str::of($this->name)->headline()->toString();
    }
}
