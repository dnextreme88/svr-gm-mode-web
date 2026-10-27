<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\SaveStage;
use App\Support\Rng\Rng;
use App\Support\Rng\RngFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A GM Mode save (§1, §17). Holds the seeded RNG for every roll.
 *
 * @property int $id
 * @property string $name
 * @property Brand $player_brand
 * @property string $rng_seed
 * @property SaveStage $stage
 * @property int $current_season
 * @property int $current_week
 * @property list<string> $draft_order
 * @property Carbon|null $saved_at
 */
#[Unguarded]
class Save extends Model
{
    /**
     * @return HasMany<SaveBrand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(SaveBrand::class);
    }

    /**
     * @return HasMany<SaveWrestler, $this>
     */
    public function wrestlers(): HasMany
    {
        return $this->hasMany(SaveWrestler::class);
    }

    /**
     * Alias used by scoped route bindings (`{save}/rosters/{saveWrestler}`).
     *
     * @return HasMany<SaveWrestler, $this>
     */
    public function saveWrestlers(): HasMany
    {
        return $this->wrestlers();
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasMany<Show, $this>
     */
    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * @return HasMany<NewsEvent, $this>
     */
    public function newsEvents(): HasMany
    {
        return $this->hasMany(NewsEvent::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return HasMany<TitleReign, $this>
     */
    public function titleReigns(): HasMany
    {
        return $this->hasMany(TitleReign::class);
    }

    /**
     * @return HasMany<DraftPick, $this>
     */
    public function draftPicks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    /**
     * @return HasMany<SaveFaction, $this>
     */
    public function saveFactions(): HasMany
    {
        return $this->hasMany(SaveFaction::class);
    }

    /**
     * @return HasMany<FactionPrompt, $this>
     */
    public function factionPrompts(): HasMany
    {
        return $this->hasMany(FactionPrompt::class);
    }

    public function brand(Brand $brand): SaveBrand
    {
        return $this->brands()->where('brand', $brand)->firstOrFail();
    }

    public function rivalBrand(): Brand
    {
        return $this->player_brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
    }

    public function isHuman(Brand $brand): bool
    {
        return $brand === $this->player_brand;
    }

    public function currentSeason(): Season
    {
        return $this->seasons()->where('number', $this->current_season)->firstOrFail();
    }

    /**
     * An RNG stream for this save. Same (week, stream) always replays the same rolls.
     */
    public function rng(string $stream, ?int $week = null): Rng
    {
        return app(RngFactory::class)->make($this->rng_seed, $week ?? $this->current_week, $stream);
    }

    protected function casts(): array
    {
        return [
            'player_brand' => Brand::class,
            'stage' => SaveStage::class,
            'draft_order' => 'array',
            'saved_at' => 'datetime',
        ];
    }
}
