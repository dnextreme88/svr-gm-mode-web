<?php

namespace App\Models;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * A public news item (§19).
 *
 * @property int $id
 * @property int $season
 * @property int $week_number
 * @property NewsEventType $type
 * @property NewsTier $tier
 * @property NewsBrand $brand
 * @property int $priority
 * @property array<string, mixed> $subject_ids
 * @property array<string, mixed> $payload
 * @property string $rendered_text
 * @property string|null $dedupe_key
 * @property int|null $related_email_id
 */
#[Unguarded]
class NewsEvent extends Model
{
    use BelongsToGame;

    protected function casts(): array
    {
        return [
            'type' => NewsEventType::class,
            'tier' => NewsTier::class,
            'brand' => NewsBrand::class,
            'subject_ids' => 'array',
            'payload' => 'array',
        ];
    }
}
