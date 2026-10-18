<?php

namespace App\Services\Game;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\SaveStage;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\User;
use App\Models\Wrestler;
use App\Services\Draft\DraftService;
use App\Services\Finance\LedgerService;
use App\Support\Rng\RngFactory;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new save (§1): brand pick, $15M starting budget, every wrestler
 * in the Free Agency pool, and a randomized draft order.
 */
class NewGameService
{
    public function __construct(
        private RngFactory $rngFactory,
        private LedgerService $ledger,
        private DraftService $draft,
    ) {}

    /**
     * @param  bool  $startDraft  let the AI make its opening picks right away
     */
    public function create(User $user, string $name, Brand $playerBrand, ?string $seed = null, bool $startDraft = true): Save
    {
        return DB::transaction(function () use ($user, $name, $playerBrand, $seed, $startDraft): Save {
            $seed ??= $this->rngFactory->newSeed();
            $order = $this->rngFactory->make($seed, 0, 'draft.order')->shuffle(Brand::values());

            $save = Save::create([
                'user_id' => $user->id,
                'name' => $name,
                'player_brand' => $playerBrand,
                'rng_seed' => $seed,
                'stage' => SaveStage::Drafting,
                'current_season' => 1,
                'current_week' => 1,
                'draft_order' => $order,
            ]);

            foreach (Brand::cases() as $brand) {
                SaveBrand::create([
                    'save_id' => $save->id,
                    'brand' => $brand,
                    'budget' => 0,
                    'is_human' => $brand === $playerBrand,
                ]);
            }

            $budget = (int) config('gm.starting_budget');

            Season::create([
                'save_id' => $save->id,
                'number' => 1,
                'started_week' => 1,
                'starting_budget' => $budget,
            ]);

            $now = now();
            SaveWrestler::insert(Wrestler::query()->orderBy('id')->pluck('id')->map(fn (int $id): array => [
                'save_id' => $save->id,
                'wrestler_id' => $id,
                'morale' => 'happy',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            // §1: the $15M is granted once, at the initial draft of a new game.
            foreach (Brand::cases() as $brand) {
                $this->ledger->post($save, $brand, LedgerCategory::StartingBudget, $budget, 'Starting budget', 1);
            }

            if ($startDraft) {
                $this->draft->runAi($save);
            }

            return $save->refresh();
        });
    }
}
