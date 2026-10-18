<?php

namespace App\Services\Ppv;

use App\Enums\Brand;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\InterbrandSeries;
use App\Models\MatchSlot;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\News\NewsRecorder;

/**
 * Scores the WrestleMania brand battle (§23): 2 of 3 wins takes the series.
 * Money in the Bank counts for the brand of the wrestler who retrieves the briefcase.
 */
class InterbrandSeriesService
{
    public function __construct(
        private PpvCardService $ppvCards,
        private NewsRecorder $news,
    ) {}

    public function settle(Show $wrestleMania): InterbrandSeries
    {
        $wins = ['raw' => 0, 'smackdown' => 0];
        $slots = $this->ppvCards->seriesSlots($wrestleMania);

        foreach ($slots as $slot) {
            $brand = $this->winningBrand($slot);

            if ($brand !== null) {
                $wins[$brand->value]++;
            }
        }

        $winner = match (true) {
            $wins['raw'] >= 2 => Brand::Raw,
            $wins['smackdown'] >= 2 => Brand::Smackdown,
            $wins['raw'] > $wins['smackdown'] => Brand::Raw,
            $wins['smackdown'] > $wins['raw'] => Brand::Smackdown,
            default => null,
        };

        $series = InterbrandSeries::updateOrCreate(
            ['save_id' => $wrestleMania->save_id, 'season' => $wrestleMania->season],
            [
                'week_number' => $wrestleMania->week_number,
                'match_ids' => array_values(array_map('intval', $slots->pluck('id')->all())),
                'raw_wins' => $wins['raw'],
                'smackdown_wins' => $wins['smackdown'],
                'winner_brand' => $winner,
            ],
        );

        if ($winner !== null) {
            $this->news->record($wrestleMania->game, NewsEventType::InterbrandSeriesResult, NewsTier::Headline, NewsBrand::Both, [
                'brand' => $winner->label(),
                'n' => max($wins),
                'm' => min($wins),
            ], [], priority: 95, week: $wrestleMania->week_number);
        }

        return $series;
    }

    /**
     * Side 0 is Raw and side 1 SmackDown in the 1v1 and 2v2; in the six-way
     * ladder match the winner's own brand takes the point.
     */
    public function winningBrand(MatchSlot $slot): ?Brand
    {
        $result = $slot->result;

        if ($result === null) {
            return null;
        }

        $winnerId = $slot->sides()[$result->winner_side][0] ?? null;

        return $winnerId === null ? null : SaveWrestler::find($winnerId)?->brand;
    }
}
