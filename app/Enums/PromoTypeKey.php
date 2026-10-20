<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Promo types (§5).
 */
enum PromoTypeKey: string
{
    use HasValues;

    case NewSuperstar = 'new_superstar';
    case ContinueRivalry = 'continue_rivalry';
    case StartRivalry = 'start_rivalry';
    case InactiveSuperstar = 'inactive_superstar';
    case InjuryReturn = 'injury_return';
    case Advertising = 'advertising';
    case MerchandiseSponsorship = 'merchandise_sponsorship';
    case MainEventHype = 'main_event_hype';
    case PpvHype = 'ppv_hype';
    case InterferencePromotion = 'interference_promotion';
    case SlanderPromotion = 'slander_promotion';
    case TitleMatchHype = 'title_match_hype';
    case ComebackReminder = 'comeback_reminder';
    case InterviewSpace = 'interview_space';
    case DivaBikiniContest = 'diva_bikini_contest';

    public function label(): string
    {
        return match ($this) {
            self::StartRivalry => 'Start a Rivalry',
            self::PpvHype => 'PPV Hype',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
