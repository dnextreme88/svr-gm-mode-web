<?php

namespace App\Services\Week;

use App\Models\Save;
use App\Support\Rng\Rng;
use App\Support\Rng\RngFactory;

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

    /**
     * Streams that must carry on across requests while the week airs live
     * (see `stream()`).
     *
     * @var array<string, Rng>
     */
    private array $streams = [];

    public function __construct(
        public Save $save,
        public int $week,
        public int $season,
    ) {}

    public function rng(string $stream): Rng
    {
        return $this->save->rng($stream, $this->week);
    }

    /**
     * Like `rng()`, but the same generator for the whole week: a show that
     * airs one match per request keeps drawing where it stopped.
     */
    public function stream(string $stream): Rng
    {
        return $this->streams[$stream] ??= $this->rng($stream);
    }

    /**
     * @return array<string, string> stream => `Rng::state()`
     */
    public function streamStates(): array
    {
        return array_map(fn (Rng $rng): string => $rng->state(), $this->streams);
    }

    /**
     * @param  array<string, string>  $states
     */
    public function restoreStreams(array $states): void
    {
        $factory = app(RngFactory::class);

        foreach ($states as $stream => $state) {
            $this->streams[$stream] = $factory->restore($state);
        }
    }
}
