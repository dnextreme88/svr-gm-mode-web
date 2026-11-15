<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a superstar plays a Call-out promo (house rule, 2026-11-15): as a
 * face (the fans' hero) or a heel (the villain). Wrestlers have no
 * alignment of their own yet; the GM picks it per promo.
 */
enum PromoAlignment: string
{
    use HasValues;

    case Face = 'face';
    case Heel = 'heel';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
