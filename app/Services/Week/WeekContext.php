<?php

namespace App\Services\Week;

use App\Models\Save;
use App\Support\Rng\Rng;

/**
 * State shared by the steps of one week's resolution.
 */
class WeekContext
{
    /**
     * Free-form results steps hand to later steps and to the summary.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public function __construct(
        public Save $save,
        public int $week,
        public int $season,
    ) {}

    public function rng(string $stream): Rng
    {
        return $this->save->rng($stream, $this->week);
    }
}
