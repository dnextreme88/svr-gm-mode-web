<?php

namespace Database\Seeders;

use App\Enums\PromoTypeKey;
use App\Models\PromoType;
use Illuminate\Database\Seeder;

/**
 * Seeds the 16 promo types (§5). Amounts, success rates and fatigue come from
 * config. Who each promo can target is decided by PromoEligibility.
 */
class PromoTypeSeeder extends Seeder
{
    /**
     * @var array<string, array{effect: string, requirement: string, wrestler?: bool, pair?: bool, diva_pair?: bool, championship?: bool, rival?: bool, variable?: bool}>
     */
    private const PROMOS = [
        'new_superstar' => [
            'effect' => 'Builds popularity without a match.',
            'requirement' => 'A superstar who signed or was traded in within the last 2 weeks.',
            'wrestler' => true,
        ],
        'continue_rivalry' => [
            'effect' => "Keeps an existing rivalry's heat alive without a match that week.",
            'requirement' => 'Two superstars already in a rivalry with each other.',
            'pair' => true,
        ],
        'start_rivalry' => [
            'effect' => 'Pairs two superstars into a rivalry without booking them together.',
            'requirement' => 'Two superstars who aren’t in a rivalry and aren’t seriously injured. Both take the fatigue.',
            'pair' => true,
        ],
        'inactive_superstar' => [
            'effect' => 'Rests a superstar who isn’t being used: they recover 2 extra fatigue bars at the end of the week, and their popularity decays more slowly.',
            'requirement' => 'A superstar who isn’t booked anywhere on this card.',
            'wrestler' => true,
        ],
        'advertising' => [
            'effect' => 'Always pays a flat $200,000 the week it airs.',
            'requirement' => 'Nothing.',
        ],
        'merchandise_sponsorship' => [
            'effect' => 'A free gamble: it draws a success chance (80%, 70%, 50% or 25%) and a payout ($10,000, $25,000, $50,000 or $100,000). A success pays it; a failure pays nothing.',
            'requirement' => 'Nothing.',
            'variable' => true,
        ],
        'main_event_hype' => [
            'effect' => "Boosts the rating of that week's main event.",
            'requirement' => 'Nothing.',
        ],
        'ppv_hype' => [
            'effect' => "Raises the upcoming PPV's overall rating; stacks with repeated use.",
            'requirement' => 'Nothing.',
        ],
        'interference_promotion' => [
            'effect' => 'Sabotages the rival show: a success earns $100,000, a failure or backfire costs $25,000 more. Backfires automatically on its 4th use within 5 weeks.',
            'requirement' => 'A non-Diva superstar who isn’t seriously injured. They take the fatigue and can get injured.',
            'wrestler' => true,
            'rival' => true,
        ],
        'slander_promotion' => [
            'effect' => "Attacks the rival show's reputation; backfires automatically on its 4th use within 5 weeks.",
            'requirement' => 'Nothing.',
            'rival' => true,
        ],
        'title_match_hype' => [
            'effect' => 'Hypes a title match on this card.',
            'requirement' => 'A championship with a saved title match on this card.',
            'championship' => true,
        ],
        'comeback_reminder' => [
            'effect' => 'Reminds the fans an injured star is coming back, keeping their popularity up.',
            'requirement' => 'A seriously injured superstar who has been out for more than 3 weeks.',
            'wrestler' => true,
        ],
        'interview_space' => [
            'effect' => 'Gives a superstar a sit-down interview to build popularity.',
            'requirement' => 'Any superstar who isn’t seriously injured.',
            'wrestler' => true,
        ],
        'diva_bikini_contest' => [
            'effect' => 'Two Divas compete in a bikini contest to build popularity. Two rival Divas also keep their rivalry going, like Continue Rivalry.',
            'requirement' => 'Two Divas who aren’t seriously injured.',
            'diva_pair' => true,
        ],
        'call_out' => [
            'effect' => 'A superstar calls out their rival, as a face or a heel. A success adds 10 heat to the rivalry and keeps it from going quiet that week.',
            'requirement' => 'An active rivalry; pick the rival who does the talking and how they play it.',
            'pair' => true,
        ],
        'speed_up_injury' => [
            'effect' => 'Extra treatment for an injured superstar. A success heals a minor injury outright, or takes 5 weeks off a serious one; a failure takes 2 weeks off either.',
            'requirement' => 'A superstar with a minor or serious injury.',
            'wrestler' => true,
        ],
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
                    'needs_championship' => $meta['championship'] ?? false,
                    'needs_diva_pair' => $meta['diva_pair'] ?? false,
                    'fatigue_bars' => (int) config("gm.promos.fatigue_bars.{$key}", 0),
                    'effect' => $meta['effect'],
                    'requirement' => $meta['requirement'],
                    'sort_order' => ++$order,
                ],
            );
        }
    }
}
