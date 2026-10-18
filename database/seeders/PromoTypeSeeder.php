<?php

namespace Database\Seeders;

use App\Enums\PromoTypeKey;
use App\Models\PromoType;
use Illuminate\Database\Seeder;

/**
 * Seeds the 11 promo types (§5). Amounts and success rates come from config.
 */
class PromoTypeSeeder extends Seeder
{
    /**
     * @var array<string, array{effect: string, wrestler?: bool, pair?: bool, rival?: bool, variable?: bool}>
     */
    private const PROMOS = [
        'new_superstar' => ['effect' => 'Only usable on a freshly-signed/traded-in wrestler; builds popularity without a match.', 'wrestler' => true],
        'continue_rivalry' => ['effect' => "Keeps an existing rivalry's heat alive without a match that week.", 'pair' => true],
        'start_rivalry' => ['effect' => 'Pairs two wrestlers into a rivalry without booking them together.', 'pair' => true],
        'inactive_superstar' => ['effect' => "Slows popularity decay for a wrestler who isn't being used.", 'wrestler' => true],
        'injury_return' => ['effect' => 'Restores popularity lost while a wrestler was out injured.', 'wrestler' => true],
        'advertising' => ['effect' => 'Guaranteed flat payout next week.'],
        'merchandise_sponsorship' => ['effect' => 'Always pays; the amount is low at first and scales up the more consistently it is used.', 'variable' => true],
        'main_event_hype' => ['effect' => "Boosts the rating of that week's main event."],
        'ppv_hype' => ['effect' => "Raises the upcoming PPV's overall rating; stacks with repeated use."],
        'interference_promotion' => ['effect' => 'Sabotages the rival show, lowering their rating that week.', 'rival' => true],
        'slander_promotion' => ['effect' => "Attacks the rival show's reputation; backfires automatically if used more than once in 4 weeks.", 'rival' => true],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::PROMOS as $key => $meta) {
            PromoType::updateOrCreate(
                ['key' => $key],
                [
                    'name' => PromoTypeKey::from($key)->label(),
                    'base_amount' => (int) config("gm.promos.amounts.{$key}"),
                    'targets_rival' => $meta['rival'] ?? false,
                    'variable_amount' => $meta['variable'] ?? false,
                    'needs_wrestler' => $meta['wrestler'] ?? false,
                    'needs_rival_pair' => $meta['pair'] ?? false,
                    'effect' => $meta['effect'],
                    'sort_order' => ++$order,
                ],
            );
        }
    }
}
