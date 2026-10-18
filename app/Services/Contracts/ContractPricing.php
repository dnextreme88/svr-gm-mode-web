<?php

namespace App\Services\Contracts;

use App\Enums\ContractLength;
use App\Models\Wrestler;

/**
 * Contract prices (§2). Shorter deals are cheaper up front but cost more
 * per week. The actual numbers are config assumptions.
 */
class ContractPricing
{
    /**
     * @return array{length_weeks: int, upfront: int, weekly: int}
     */
    public function quote(Wrestler $wrestler, ContractLength $length): array
    {
        /** @var array{upfront: int, weekly: int} $base */
        $base = config('gm.contracts.pricing.'.$length->value);
        $multiplier = $wrestler->isLegend() ? (float) config('gm.contracts.legend_multiplier') : 1.0;

        return [
            'length_weeks' => $length->value,
            'upfront' => (int) round($base['upfront'] * $multiplier),
            'weekly' => (int) round($base['weekly'] * $multiplier),
        ];
    }

    /**
     * Quotes for every contract length, for the negotiation screen.
     *
     * @return list<array{length_weeks: int, label: string, upfront: int, weekly: int, total: int}>
     */
    public function allQuotes(Wrestler $wrestler): array
    {
        return array_map(function (ContractLength $length) use ($wrestler): array {
            $quote = $this->quote($wrestler, $length);

            return [
                ...$quote,
                'label' => $length->label(),
                'total' => $quote['upfront'] + $quote['weekly'] * $length->value,
            ];
        }, ContractLength::cases());
    }
}
