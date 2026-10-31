<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An in-game email to a GM (§2, §3, §11). Deleting one from the inbox only
 * soft-deletes it: game rules that read past emails (the Low on Cash cadence)
 * still see it.
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
 * @property Carbon|null $deleted_at
 */
#[Unguarded]
class Email extends Model
{
    use BelongsToGame;
    use SoftDeletes;

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
