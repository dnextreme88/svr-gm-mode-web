<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * How a reign began (§16).
 */
enum WonVia: string
{
    use HasValues;

    case Match = 'match';
    case Awarded = 'awarded';
    case VacancyMatch = 'vacancy_match';
    case MitbCashIn = 'mitb_cash_in';

    public function label(): string
    {
        return match ($this) {
            self::MitbCashIn => 'Money in the Bank Cash-In',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
