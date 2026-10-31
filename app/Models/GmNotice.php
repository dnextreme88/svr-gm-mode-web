<?php

namespace App\Models;

use App\Enums\GmNoticeKind;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-off notice for the GM, shown in a modal until seen (house rule).
 *
 * @property int $id
 * @property int $save_id
 * @property GmNoticeKind $kind
 * @property array<string, mixed> $payload
 * @property int $week_number
 * @property Carbon|null $seen_at
 */
#[Unguarded]
class GmNotice extends Model
{
    use BelongsToGame;

    protected function casts(): array
    {
        return [
            'kind' => GmNoticeKind::class,
            'payload' => 'array',
            'seen_at' => 'datetime',
        ];
    }
}
