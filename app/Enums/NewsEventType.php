<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * News event catalog (§19).
 */
enum NewsEventType: string
{
    use HasValues;

    case TitleChange = 'title_change';
    case TitleRetained = 'title_retained';
    case RetainedByDq = 'retained_by_dq';
    case ContenderEarnedShot = 'contender_earned_shot';
    case LastChanceResult = 'last_chance_result';
    // House rules (2026-10-31): title stipulations.
    case ChampionSurvivedChallenge = 'champion_survived_challenge';
    case WinnerTakesAll = 'winner_takes_all';
    case NumberOneContender = 'number_one_contender';
    case ContendershipVoided = 'contendership_voided';
    case ContenderAwardedTitle = 'contender_awarded_title';
    case TitleVacated = 'title_vacated';
    case TitleAwarded = 'title_awarded';
    case ReignMilestone = 'reign_milestone';
    case RecordBroken = 'record_broken';
    case PpvUpcoming = 'ppv_upcoming';
    case PpvMainEventSet = 'ppv_main_event_set';
    case PpvResults = 'ppv_results';
    case SurvivorSeriesTeams = 'survivor_series_teams';
    case MitbQualified = 'mitb_qualified';
    case MitbWinner = 'mitb_winner';
    case MitbCashIn = 'mitb_cash_in';
    case MitbExpiring = 'mitb_expiring';
    case WinStreak = 'win_streak';
    case StreakSnapped = 'streak_snapped';
    case LosingStreak = 'losing_streak';
    case InterferenceFinish = 'interference_finish';
    case InterbrandSeriesResult = 'interbrand_series_result';
    case UpsetWin = 'upset_win';
    case RivalryStarted = 'rivalry_started';
    case RivalryHeatingUp = 'rivalry_heating_up';
    case RivalryBlowoff = 'rivalry_blowoff';
    case Debut = 'debut';
    case LegendSigned = 'legend_signed';
    case FreeAgentSigned = 'free_agent_signed';
    case Released = 'released';
    case LoserLeaves = 'loser_leaves';
    case TradeCompleted = 'trade_completed';
    case RedraftPick = 'redraft_pick';
    case Injury = 'injury';
    case InjuryEscalated = 'injury_escalated';
    case InjuryReturn = 'injury_return';
    case ContractDemand = 'contract_demand';
    case TitleDemand = 'title_demand';
    case MoraleUnrest = 'morale_unrest';
    case DefectionAttempt = 'defection_attempt';
    case DefectionCompleted = 'defection_completed';
    case DefectionBlocked = 'defection_blocked';
    case RatingsWar = 'ratings_war';
    case RatingsStreak = 'ratings_streak';
    case SlanderResult = 'slander_result';
    case InterferencePromoResult = 'interference_promo_result';
    case SeasonReset = 'season_reset';
    case MatchResult = 'match_result';
    case MainEventHypeResult = 'main_event_hype_result';
    case PromoResult = 'promo_result';
    case ContractExpiring = 'contract_expiring';
    case RivalryMilestone = 'rivalry_milestone';

    // Fan attendance (house rule, 2026-11-01).
    case Attendance = 'attendance';

    // Factions (house rule).
    case FactionFormed = 'faction_formed';
    case FactionMemberAdded = 'faction_member_added';
    case FactionTitleWon = 'faction_title_won';
    case FactionTitleLost = 'faction_title_lost';
    case FactionInterbrandWin = 'faction_interbrand_win';
    case FactionDisbanded = 'faction_disbanded';

    // Quiet-week fallbacks (house rule): written only for cards a week left empty.
    case TicketsOnSale = 'tickets_on_sale';
    case BrandSpotlight = 'brand_spotlight';
    case SuperstarRecap = 'superstar_recap';
    case InjuryReport = 'injury_report';
    case LockerRoomNote = 'locker_room_note';

    /**
     * Quiet-week filler: never promoted over real news.
     */
    public function isFallback(): bool
    {
        return in_array($this, [self::TicketsOnSale, self::BrandSpotlight, self::SuperstarRecap, self::InjuryReport, self::LockerRoomNote], true);
    }
}
