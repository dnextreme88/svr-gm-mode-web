<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Conditional match stories (house rule, 2026-11-07): things that happen in a
 * simulated match because of who's in it, with their own news text and a
 * pop-up on the live results screen.
 */
enum ConditionalStory: string
{
    use HasValues;

    /** Hornswoggle slips Finlay his shillelagh (story only). */
    case HornswoggleShillelagh = 'hornswoggle_shillelagh';
    /** The lights go out on Undertaker's rival. */
    case UndertakerLightsOut = 'undertaker_lights_out';
    /** Kane's pyro goes off on his rival. */
    case KanePyro = 'kane_pyro';
    /** The Dudley Boyz get the tables. */
    case DudleyTables = 'dudley_tables';
    /** Team Xtreme's ladder spot. */
    case HardyLadder = 'hardy_ladder';

    public function label(): string
    {
        return match ($this) {
            self::HornswoggleShillelagh => "Hornswoggle's shillelagh",
            self::UndertakerLightsOut => "Undertaker's lights-out",
            self::KanePyro => "Kane's pyro",
            self::DudleyTables => 'Dudley Boyz: get the tables!',
            self::HardyLadder => "Team Xtreme's ladder spot",
        };
    }
}
