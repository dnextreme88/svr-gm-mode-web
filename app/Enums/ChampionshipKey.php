<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The 8 GM Mode championships (§14).
 */
enum ChampionshipKey: string
{
    use HasValues;

    case Wwe = 'wwe';
    case Intercontinental = 'intercontinental';
    case WorldTagTeam = 'world_tag_team';
    case Womens = 'womens';
    case WorldHeavyweight = 'world_heavyweight';
    case UnitedStates = 'united_states';
    case WweTagTeam = 'wwe_tag_team';
    case Cruiserweight = 'cruiserweight';

    public function label(): string
    {
        return match ($this) {
            self::Wwe => 'WWE Championship',
            self::Intercontinental => 'Intercontinental Championship',
            self::WorldTagTeam => 'World Tag Team Championship',
            self::Womens => 'Women\'s Championship',
            self::WorldHeavyweight => 'World Heavyweight Championship',
            self::UnitedStates => 'United States Championship',
            self::WweTagTeam => 'WWE Tag Team Championship',
            self::Cruiserweight => 'Cruiserweight Championship',
        };
    }
}
