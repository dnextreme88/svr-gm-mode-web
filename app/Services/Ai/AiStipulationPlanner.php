<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ChampionshipKey;
use App\Enums\ShowType;
use App\Models\Championship;
use App\Models\Save;
use App\Models\Show;
use App\Support\Rng\Rng;

/**
 * The rival GM's stipulation plans (house rule, 2026-11-13; numbers in
 * `config('gm.ai.stipulations')`).
 *
 * - Loser Leaves Show every 26 weeks and Last Chance every 17: a roll at
 *   the first card it books once the roll is due. A won roll (85%) keeps
 *   trying each card until one is eligible and booked; the next roll is a
 *   full interval after that. A lost roll waits a full interval.
 * - A #1 Contender's Match on its weekly shows in the 3 weeks before each
 *   of its brand-only PPVs, for a title drawn by weight, held for the PPV.
 *
 * The plans live on the brand (`save_brands.ai_plans`).
 */
class AiStipulationPlanner
{
    public const LOSER_LEAVES = 'loser_leaves';

    public const LAST_CHANCE = 'last_chance';

    /**
     * Whether the brand wants this stipulation on a card this week: a won
     * roll still to book, or a roll that's due and won now.
     */
    public function wants(Save $save, Brand $brand, string $kind, int $week): bool
    {
        $plan = $this->plan($save, $brand, $kind);

        if ($plan['pending']) {
            return true;
        }

        if ($plan['due'] !== null && $week < $plan['due']) {
            return false;
        }

        $won = $save->rng("ai.stip.{$kind}.{$brand->value}", $week)->chance((float) config("gm.ai.stipulations.{$kind}.chance"));
        $this->store($save, $brand, $kind, [
            'pending' => $won,
            'due' => $won ? null : $week + $this->every($kind),
        ]);

        return $won;
    }

    /**
     * The stipulation went on a card: the next roll is a full interval away.
     */
    public function booked(Save $save, Brand $brand, string $kind, int $week): void
    {
        $this->store($save, $brand, $kind, ['pending' => false, 'due' => $week + $this->every($kind)]);
    }

    /**
     * The brand's own brand-only PPV this card leads up to, within
     * `weeks_before` (a weekly show only), or null.
     */
    public function upcomingPpv(Show $show, Brand $brand): ?Show
    {
        if ($show->show_type !== ShowType::Weekly) {
            return null;
        }

        $lead = (int) config('gm.ai.stipulations.number_one_contender_ppv.weeks_before');

        return Show::where('save_id', $show->save_id)
            ->where('brand', $brand)
            ->where('show_type', ShowType::Ppv)
            ->where('week_number', '>', $show->week_number)
            ->where('week_number', '<=', $show->week_number + $lead)
            ->orderBy('week_number')
            ->first();
    }

    /**
     * Whether the #1 Contender's Match for this PPV has been booked already.
     */
    public function contenderBookedFor(Save $save, Brand $brand, Show $ppv): bool
    {
        return ($save->brand($brand)->ai_plans['number_one_contender_ppv'] ?? null) === $ppv->week_number;
    }

    public function contenderBooked(Save $save, Brand $brand, Show $ppv): void
    {
        $this->storeValue($save, $brand, 'number_one_contender_ppv', $ppv->week_number);
    }

    /**
     * The titles to try, drawn by tier weight (world 45, second 25, tag 15,
     * special 15) and then the rest in that order.
     *
     * @param  list<Championship>  $titles
     * @return list<Championship>
     */
    public function titleOrder(array $titles, Rng $rng): array
    {
        $weights = (array) config('gm.ai.stipulations.number_one_contender_ppv.title_weights');
        $byTier = [];

        foreach ($titles as $title) {
            $byTier[self::tier($title)][] = $title;
        }

        $available = array_intersect_key($weights, $byTier);

        if ($available === []) {
            return [];
        }

        $first = (string) $rng->weightedPick(array_map('intval', $available));
        $order = [...$byTier[$first]];

        foreach (array_keys($weights) as $tier) {
            if ($tier !== $first) {
                array_push($order, ...($byTier[$tier] ?? []));
            }
        }

        return $order;
    }

    /**
     * A title's tier: world, second (Intercontinental, United States), tag
     * or special (Women's, Cruiserweight).
     */
    public static function tier(Championship $title): string
    {
        return match ($title->key) {
            ChampionshipKey::Wwe, ChampionshipKey::WorldHeavyweight => 'world',
            ChampionshipKey::Intercontinental, ChampionshipKey::UnitedStates => 'second',
            ChampionshipKey::WorldTagTeam, ChampionshipKey::WweTagTeam => 'tag',
            default => 'special',
        };
    }

    /**
     * @return array{pending: bool, due: int|null}
     */
    private function plan(Save $save, Brand $brand, string $kind): array
    {
        $plan = $save->brand($brand)->ai_plans[$kind] ?? [];

        return ['pending' => (bool) ($plan['pending'] ?? false), 'due' => isset($plan['due']) ? (int) $plan['due'] : null];
    }

    /**
     * @param  array{pending: bool, due: int|null}  $plan
     */
    private function store(Save $save, Brand $brand, string $kind, array $plan): void
    {
        $this->storeValue($save, $brand, $kind, $plan);
    }

    private function storeValue(Save $save, Brand $brand, string $key, mixed $value): void
    {
        $row = $save->brand($brand);
        $plans = $row->ai_plans ?? [];
        $plans[$key] = $value;
        $row->update(['ai_plans' => $plans]);
    }

    private function every(string $kind): int
    {
        return (int) config("gm.ai.stipulations.{$kind}.every_weeks");
    }
}
