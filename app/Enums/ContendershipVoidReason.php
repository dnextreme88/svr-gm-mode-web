<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a #1 contendership ended before its title match (house rule).
 */
enum ContendershipVoidReason: string
{
    use HasValues;

    case Released = 'released';
    case ContractExpired = 'contract_expired';
    case Traded = 'traded';
    case Redrafted = 'redrafted';
    case Defected = 'defected';
    case LoserLeaves = 'loser_leaves';
    case SeriousInjury = 'serious_injury';
    case Forfeited = 'forfeited';

    public function label(): string
    {
        return match ($this) {
            self::Released => 'was released',
            self::ContractExpired => 'saw their contract run out',
            self::Traded => 'was traded',
            self::Redrafted => 'moved brands in the re-draft',
            self::Defected => 'defected to the other brand',
            self::LoserLeaves => 'lost a Loser Leaves Show match',
            self::SeriousInjury => 'suffered a serious injury',
            self::Forfeited => 'forfeited it when the title was vacated',
        };
    }
}
