<?php

namespace App\Services\Contracts;

use App\Enums\ContractLength;
use App\Models\Wrestler;

/**
 * Contract prices (house rule, 2026-11-15). A contract costs the
 * superstar's monthly salary × its months, paid in equal weekly cuts; there
 * is no up-front fee.
 */
class ContractPricing
{
    /**
     * @return array{length_weeks: int, weekly: int, total: int}
     */
    public function quote(Wrestler $wrestler, ContractLength $length): array
    {
        $total = (int) $wrestler->monthly_salary * $length->months();

        return [
            'length_weeks' => $length->value,
            'weekly' => (int) round($total / $length->value),
            'total' => $total,
        ];
    }

    /**
     * Quotes for every contract length, for the negotiation screen.
     *
     * @return list<array{length_weeks: int, label: string, weekly: int, total: int}>
     */
    public function allQuotes(Wrestler $wrestler): array
    {
        return array_map(fn (ContractLength $length): array => [
            ...$this->quote($wrestler, $length),
            'label' => $length->label(),
        ], ContractLength::cases());
    }
}
