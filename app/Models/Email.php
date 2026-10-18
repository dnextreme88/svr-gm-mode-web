<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An in-game email to a GM (§2, §3, §11).
 *
 * @property int $id
 * @property Brand $brand
 * @property int $season
 * @property int $week_number
 * @property EmailTemplate $template
 * @property string $sender
 * @property string $subject
 * @property string|null $body
 * @property array<string, mixed> $payload
 * @property Carbon|null $read_at
 */
#[Unguarded]
class Email extends Model
{
    use BelongsToGame;

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'template' => EmailTemplate::class,
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
