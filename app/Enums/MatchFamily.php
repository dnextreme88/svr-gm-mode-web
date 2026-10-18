<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Match type family from the §10 taxonomy. Also used for specialties (§21).
 */
enum MatchFamily: string
{
    use HasValues;

    case Single = 'single';
    case TagTeam = 'tag_team';
    case SixManTag = 'six_man_tag';
    case EliminationTag = 'elimination_tag';
    case TripleThreat = 'triple_threat';
    case FatalFourWay = 'fatal_four_way';
    case Handicap = 'handicap';
    case RoyalRumble = 'royal_rumble';
    case ThreeStagesOfHell = 'three_stages_of_hell';
    case BackstageBrawl = 'backstage_brawl';
    case BattleRoyal = 'battle_royal';
    case BuriedAlive = 'buried_alive';
    case EliminationChamber = 'elimination_chamber';
    case FirstBlood = 'first_blood';
    case FulfillYourFantasy = 'fulfill_your_fantasy';
    case Hardcore = 'hardcore';
    case HellInACell = 'hell_in_a_cell';
    case IronMan = 'iron_man';
    case Ladder = 'ladder';
    case LastManStanding = 'last_man_standing';
    case SlobberKnocker = 'slobber_knocker';
    case SpecialReferee = 'special_referee';
    case SteelCage = 'steel_cage';
    case Submission = 'submission';
    case Table = 'table';
    case Tlc = 'tlc';
    case SurvivorSeriesElimination = 'survivor_series_elimination';

    public function label(): string
    {
        return match ($this) {
            self::Tlc => 'TLC',
            self::FatalFourWay => 'Fatal 4-Way',
            self::SixManTag => '6-Man Tag',
            self::SurvivorSeriesElimination => 'Traditional Survivor Series Elimination',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
