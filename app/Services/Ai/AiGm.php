<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Enums\WeightClass;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractPricing;
use App\Services\Promos\PromoService;
use App\Services\Roster\RosterService;
use Illuminate\Support\Collection;

/**
 * Decisions for the brand no human runs. Its cards follow the strategic
 * house rules (`gm.ai`): `AiRivalryPlanner` decides which rivalries get
 * matches or start, `AiBooker` books the matches and `AiPromoPlanner` the
 * promos. When the simulator books the player's own cards (Simulate to
 * here) it keeps the plain Preset rules. Choices that need luck go through
 * the save's seeded RNG.
 */
class AiGm
{
    public function __construct(
        private RosterService $roster,
        private ContractPricing $pricing,
        private CardRandomizer $randomizer,
        private CardService $cards,
        private PromoService $promos,
        private AiBooker $booker,
        private AiRivalryPlanner $rivalryPlanner,
        private AiPromoPlanner $promoPlanner,
        private AiRosterManager $rosterManager,
    ) {}

    /**
     * Fill a brand's empty slots: strategically for the rival brand, with
     * Preset (§24) for the player's cards during a simulation.
     */
    public function bookShow(Show $show, ?Brand $brand = null): void
    {
        $brand ??= $show->brand;

        if ($brand === null) {
            return;
        }

        $this->cards->ensureSlots($show);

        if ($brand !== $show->game->rivalBrand()) {
            $this->bookPreset($show, $brand);

            return;
        }

        $promos = $show->brand === $brand && $show->promoSlotCount() > 0 && ! $this->rosterManager->needsCash($show->game, $brand);
        $plan = $this->rivalryPlanner->plan($show, $brand, $this->booker->plannedFatigue($show, $brand), $promos);

        $this->booker->book($show, $brand, $plan['matches'], $plan['fresh']);
        $this->promoPlanner->book($show, $brand, $plan['promo_starts']);
    }

    private function bookPreset(Show $show, Brand $brand): void
    {
        $this->bookPromos($show);

        try {
            $preset = $this->randomizer->preset($show, $show->game->rng("ai.card.{$show->streamKey()}", $show->week_number), $brand);
        } catch (GameRuleException) {
            return;
        }

        foreach ($preset['proposals'] as $proposal) {
            try {
                $this->cards->book($show, $proposal->position, $proposal->booking, $brand);
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * ASSUMPTION: the AI keeps the money flowing with Advertising and Merchandise.
     */
    private function bookPromos(Show $show): void
    {
        foreach ([1 => PromoTypeKey::Advertising, 2 => PromoTypeKey::MerchandiseSponsorship] as $position => $key) {
            if ($position > $show->promoSlotCount() || PromoSlot::where('show_id', $show->id)->where('position', $position)->whereNotNull('promo_type_id')->exists()) {
                continue;
            }

            try {
                $this->promos->book($show, $position, PromoType::where('key', $key)->firstOrFail());
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * Pick an affordable, draftable free agent for the initial draft, or null
     * to stop. House rule: a roll of 1..`ai_roll_max` at or above
     * `ai_focus_from` makes the pick come from the brand's focus class when
     * any are left (Raw: Divas, SmackDown: Cruiserweights and Light
     * Heavyweights), up to `gm.ai.draft.max_focus_picks` of them. Otherwise
     * it's any wrestler at random. Its last picks first fill any title the
     * roster has no eligible holder for.
     */
    public function draftPick(Save $save, Brand $brand, int $pickNumber): ?SaveWrestler
    {
        $budget = $save->brand($brand)->budget;
        $length = ContractLength::from((int) config('gm.contracts.draft_default_length'));

        $pool = $this->roster->freeAgentsQuery($save, $save->current_week)
            ->with('wrestler')
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->isDraftable()
                && $this->pricing->quote($w->wrestler, $length)['upfront'] <= $budget)
            ->values();

        if ($pool->isEmpty()) {
            return null;
        }

        $rng = $save->rng("ai.draft.{$brand->value}.{$pickNumber}", 0);
        $roster = SaveWrestler::with('wrestler')->where('save_id', $save->id)->where('brand', $brand)->get();

        $uncovered = Championship::where('brand', $brand)->orderBy('sort_order')->get()
            ->reject(fn (Championship $c): bool => $roster->contains(fn (SaveWrestler $w): bool => $c->allowsWrestler($w->wrestler)))
            ->values();
        $picksLeft = (int) config('gm.initial_draft_max_picks') - $pickNumber + 1;

        if ($uncovered->isNotEmpty() && $picksLeft >= 1 && $picksLeft <= $uncovered->count()) {
            $fillers = $pool->filter(fn (SaveWrestler $w): bool => $uncovered->contains(fn (Championship $c): bool => $c->allowsWrestler($w->wrestler)))->values();

            if ($fillers->isNotEmpty()) {
                return $rng->pick($fillers->all());
            }
        }

        // Once it has a member of a tag team or stable, it sometimes goes
        // for a teammate instead (house rule): 25% teammate, else the
        // division roll below. With no teammate left, anyone at random.
        $mates = $this->draftTeammates($save, $roster, $pool);

        if ($mates !== null && $rng->int(1, 100) <= (int) config('gm.ai.draft.teammate_chance')) {
            return $rng->pick($mates->isNotEmpty() ? $mates->all() : $pool->all());
        }

        $focusTaken = $roster->filter(fn (SaveWrestler $w): bool => self::isDraftFocus($brand, $w))->count();

        if ($rng->int(1, (int) config('gm.draft.ai_roll_max')) >= (int) config('gm.draft.ai_focus_from')
            && $focusTaken < (int) config('gm.ai.draft.max_focus_picks')) {
            $focus = $pool->filter(fn (SaveWrestler $w): bool => self::isDraftFocus($brand, $w))->values();

            if ($focus->isNotEmpty()) {
                return $rng->pick($focus->all());
            }
        }

        return $rng->pick($pool->all());
    }

    /**
     * Free agents in the pool who share an active faction with someone on
     * the rival's roster, or null while it has nobody from any faction
     * (no basis to look for teammates, e.g. its first pick).
     *
     * @param  Collection<int, SaveWrestler>  $roster
     * @param  Collection<int, SaveWrestler>  $pool
     * @return Collection<int, SaveWrestler>|null
     */
    private function draftTeammates(Save $save, Collection $roster, Collection $pool): ?Collection
    {
        $rosterIds = $roster->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $factions = SaveFaction::with('members')->where('save_id', $save->id)->whereNull('disbanded_week')->orderBy('id')->get()
            ->filter(fn (SaveFaction $f): bool => $f->isActiveAt($save->current_week)
                && array_intersect($f->memberIds(), $rosterIds) !== []);

        if ($factions->isEmpty()) {
            return null;
        }

        $mateIds = $factions->flatMap(fn (SaveFaction $f): array => $f->memberIds())->unique()->all();

        return $pool->filter(fn (SaveWrestler $w): bool => in_array((int) $w->id, $mateIds, true))->values();
    }

    /**
     * The class each brand's AI chases in the draft: its colour-coded
     * division (Raw: Divas, SmackDown: Cruiserweights and Light Heavyweights).
     */
    public static function isDraftFocus(Brand $brand, SaveWrestler $wrestler): bool
    {
        $w = $wrestler->wrestler;

        return match ($brand) {
            Brand::Raw => $w->roster_type === RosterType::Diva,
            Brand::Smackdown => $w->roster_type !== RosterType::Diva
                && in_array($w->weight_class, [WeightClass::Cruiserweight, WeightClass::LightHeavyweight], true),
        };
    }
}
