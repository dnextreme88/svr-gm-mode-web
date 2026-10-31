<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a save's faction came from (house rule): the seeded catalog, or
 * formed in game by the player or the rival GM.
 */
enum FactionOrigin: string
{
    use HasValues;

    case Seeded = 'seeded';
    case Player = 'player';
    case Rival = 'rival';
}
