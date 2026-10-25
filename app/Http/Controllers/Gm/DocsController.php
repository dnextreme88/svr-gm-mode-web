<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\Stipulation;
use App\Http\Controllers\Controller;
use App\Models\InjuryType;
use App\Models\MatchType;
use App\Models\PpvEvent;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\Wrestler;
use App\Services\Calendar\CalendarService;
use App\Services\News\FallbackStories;
use App\Services\News\NewsCatalog;
use App\Services\News\PromoStories;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reference pages built from the catalog: match types (§10), fatigue and
 * injuries (§6), specialties (§21), stipulations (§22), promos, news and email, and the season calendar. Nothing here is
 * per-save: it's served both inside a career (with the GM header and nav)
 * and from the save list, without one.
 */
class DocsController extends Controller
{
    /**
     * `$save` is only bound on the in-career route, so the GM header is shared.
     */
    public function __invoke(NewsCatalog $catalog, CalendarService $calendar, ?Save $save = null): Response
    {
        $matchTypes = MatchType::with('ppvExclusive')
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (MatchType $type): string => $type->family->value)
            ->map(fn ($types, string $family): array => [
                'family' => $family,
                'label' => $types->first()->family->label(),
                'variants' => $types->map(fn (MatchType $type): array => [
                    'id' => $type->id,
                    'variant' => $type->variant,
                    'sides' => $type->sides,
                    'competitors' => $type->competitorCount(),
                    'managers' => count($type->manager_sides),
                    'fatigue_bars' => $type->fatigue_cost_bars,
                    'cost' => $type->cost,
                    'dq' => $type->dq_enabled,
                    'countout' => $type->countout_enabled,
                    'rope_breaks' => $type->rope_breaks,
                    'ring_out' => $type->ring_out,
                    'captain_fall' => $type->captain_fall,
                    'timed' => $type->timed,
                    'titles_allowed' => $type->titles_allowed,
                    'divas_only' => $type->divas_only,
                    'divas_allowed' => $type->divas_allowed,
                    'elimination' => $type->is_elimination,
                    'ppv_only' => $type->ppv_only,
                    'ppv_exclusive' => $type->ppvExclusive?->name,
                    'brand' => $type->brand_restriction?->value,
                    'gm_bookable' => $type->gm_bookable,
                    'special_referee' => $type->hasSpecialReferee(),
                    'win_condition' => $type->win_condition,
                ])->values(),
            ])
            ->values();

        $injuries = InjuryType::with('escalatesTo')
            ->orderBy('min_weeks')
            ->orderBy('label')
            ->get()
            ->map(fn (InjuryType $type): array => [
                'label' => $type->label,
                'severity' => $type->severity->value,
                'min_weeks' => $type->min_weeks,
                'max_weeks' => $type->max_weeks,
                'escalates_to' => $type->escalatesTo?->label,
            ]);

        $specialists = Wrestler::with('specialties')
            ->whereHas('specialties')
            ->orderBy('name')
            ->get()
            ->map(fn (Wrestler $wrestler): array => [
                'name' => $wrestler->name,
                'roster_type' => $wrestler->roster_type->value,
                'weight_class' => $wrestler->weight_class->value,
                'specialties' => $wrestler->specialtyFamilies(),
            ]);

        return Inertia::render('gm/docs', [
            'match_types' => $matchTypes,
            'injuries' => [
                'minor' => $injuries->where('severity', InjurySeverity::Minor->value)->values(),
                'serious' => $injuries->where('severity', InjurySeverity::Serious->value)->values(),
            ],
            'fatigue' => [
                'bar_size' => (int) config('gm.fatigue.bar_size'),
                'max' => (int) config('gm.fatigue.max'),
                'safe_max' => (int) config('gm.fatigue.safe_max'),
                'serious_at' => (int) config('gm.fatigue.serious_at'),
                'rest_recovery_bars' => (int) config('gm.fatigue.rest_recovery_bars'),
                'serious_share' => (float) config('gm.injuries.serious_share'),
                'escalation_chance' => (float) config('gm.injuries.escalation_chance'),
            ],
            'specialists' => $specialists,
            'rosters' => [
                'cap' => (int) config('gm.roster_cap'),
                'starting_budget' => (int) config('gm.starting_budget'),
                'contract_weeks' => (int) config('gm.contracts.draft_default_length'),
                'legends_draftable' => (bool) config('gm.draft.legends_draftable'),
                'ai_roll_max' => (int) config('gm.draft.ai_roll_max'),
                'ai_focus_from' => (int) config('gm.draft.ai_focus_from'),
                'wwe' => collect(Brand::cases())->mapWithKeys(fn (Brand $brand): array => [
                    $brand->value => Wrestler::where('placeholder_brand', $brand)
                        ->orderBy('name')
                        ->get()
                        ->map(fn (Wrestler $wrestler): array => [
                            'name' => $wrestler->name,
                            'roster_type' => $wrestler->roster_type->value,
                            'weight_class' => $wrestler->weight_class->value,
                        ]),
                ]),
            ],
            'rival' => [
                'booking' => (array) config('gm.ai.booking'),
                'titles' => (array) config('gm.ai.titles'),
                'promos' => [
                    'interference_every_weeks' => (int) config('gm.ai.promos.interference_every_weeks'),
                    'slander_gap_weeks' => (array) config('gm.ai.promos.slander_gap_weeks'),
                    'title_hype_weights' => (array) config('gm.ai.promos.title_hype_weights'),
                ],
                'rivalries' => (array) config('gm.ai.rivalries'),
                'roster' => (array) config('gm.ai.roster'),
                'trades' => (array) config('gm.ai.trades'),
                'mitb' => (array) config('gm.ai.mitb'),
                'max_focus_picks' => (int) config('gm.ai.draft.max_focus_picks'),
                'low_cash' => (int) config('gm.emails.low_cash_threshold'),
            ],
            'promos' => [
                'slots' => (int) config('gm.card.weekly_promo_slots'),
                'merchandise_tiers' => array_values((array) config('gm.promos.merchandise_tiers')),
                'types' => PromoType::orderBy('name')->get()->map(fn (PromoType $t): array => [
                    'key' => $t->key->value,
                    'name' => $t->name,
                    'amount' => $t->base_amount,
                    'variable_amount' => $t->variable_amount,
                    'success_rate' => $t->successRate(),
                    'success_range' => $t->successRange(),
                    'fatigue_bars' => $t->fatigue_bars,
                    'recovery_bars' => (int) config('gm.promos.recovery_bars.'.$t->key->value, 0),
                    'targets_rival' => $t->targets_rival,
                    'requirement' => $t->requirement,
                    'effect' => $t->effect,
                ])->values(),
            ],
            'stipulations' => array_map(fn (Stipulation $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
            ], Stipulation::cases()),
            'quiet_stories' => FallbackStories::catalog(),
            'promo_stories' => collect(PromoStories::catalog())->map(fn (array $situations, string $key): array => [
                'key' => $key,
                'name' => PromoTypeKey::from($key)->label(),
                'situations' => $situations,
            ])->sortBy('name')->values(),
            'news' => [
                'stories_per_brand' => (int) config('gm.news.stories_per_brand'),
                'groups' => NewsCatalog::GROUPS,
                'events' => $catalog->entries(),
            ],
            'emails' => $this->emails(),
            'results' => [
                'booked_non_decisive_percent' => (int) round((float) config('gm.outcomes.booked_non_decisive_chance') * 100),
            ],
            'calendar' => [
                'weekly_show_days' => (array) config('gm.calendar.weekly_show_days'),
                'weeks_per_season' => $calendar->weeksPerSeason(),
                'season_start' => $calendar->date(1)->format('D M j, Y'),
                'season_end' => $calendar->date($calendar->weeksPerSeason())->format('D M j, Y'),
                'ppvs' => PpvEvent::orderBy('season_week')->get()->map(fn (PpvEvent $ppv): array => [
                    'key' => $ppv->key->value,
                    'name' => $ppv->name,
                    'season_week' => $ppv->season_week,
                    'date' => $calendar->date($ppv->season_week)->format('D M j, Y'),
                    'brand_scope' => $ppv->brand_scope->value,
                ])->values(),
            ],
        ]);
    }

    /**
     * Every GM email: who sends it and when, with the thresholds from config.
     *
     * @return list<array{email: string, from: string, when: string}>
     */
    private function emails(): array
    {
        $senders = (array) config('gm.email_senders');
        $jrTazz = "{$senders['raw']} (Raw) / {$senders['smackdown']} (Smackdown)";
        $rivalGm = 'The rival GM';
        $vince = (string) $senders['vince'];
        $trainer = (string) $senders['trainer'];
        $superstar = 'The superstar';
        $emails = (array) config('gm.emails');
        $lowCash = '$'.number_format((int) $emails['low_cash_threshold']);

        return [
            ['email' => 'Welcome', 'from' => $jrTazz, 'when' => 'At the start of every season: after the titles are awarded in season 1, and after the re-draft later on.'],
            ['email' => 'Superstar specialties', 'from' => $jrTazz, 'when' => "Every {$emails['specialty_tip_every_weeks']} weeks (season weeks 1 and 27). Explains specialties and sometimes names one of your specialists."],
            ['email' => 'Interference reminder', 'from' => $jrTazz, 'when' => "After {$emails['interference_reminder_weeks']} weeks without an Interference promo. Once per stretch; running the promo restarts the clock."],
            ['email' => 'Low on cash', 'from' => $jrTazz, 'when' => "When the budget is {$lowCash} or less, at most once every {$emails['low_cash_every_weeks']} weeks while it stays low."],
            ['email' => 'Expiring contracts', 'from' => $jrTazz, 'when' => 'Every week, listing everyone with '.(int) config('gm.contracts.expiring_threshold_weeks').' or fewer weeks left, with Renegotiate and Release buttons.'],
            ['email' => 'Contract expired', 'from' => $jrTazz, 'when' => 'The week a contract runs out and the wrestler leaves for Free Agency.'],
            ['email' => 'Deficit', 'from' => $jrTazz, 'when' => 'After '.(int) config('gm.finances.deficit_weeks_limit').' straight weeks in the red. You must pick a way to recover before the next week airs.'],
            ['email' => 'Defection attempt', 'from' => $jrTazz, 'when' => 'When an Unsatisfied or Upset superstar tries to jump to the other brand.'],
            ['email' => 'Trade answer', 'from' => $rivalGm, 'when' => 'Right after every trade offer: accepted or declined, with each superstar on its own line.'],
            ['email' => 'Slander reaction', 'from' => $rivalGm, 'when' => 'After your Slander promo airs. Furious and out for revenge if it worked, laughing at you if it didn’t.'],
            ['email' => 'Rival Slander', 'from' => $jrTazz, 'when' => 'After the rival GM’s Slander promo airs against your show: a warning if it landed, relief if it fell flat.'],
            ['email' => 'PPV reminder', 'from' => $vince, 'when' => "{$emails['ppv_reminder_weeks_before']} weeks before each shared PPV: SummerSlam, Survivor Series, Royal Rumble and WrestleMania."],
            ['email' => 'Re-draft reminder', 'from' => $vince, 'when' => 'Right after WrestleMania, explaining the re-draft rules.'],
            ['email' => 'Injury report', 'from' => $trainer, 'when' => 'Every injury or escalation: who, what, how serious and how many weeks out.'],
            ['email' => 'Morale complaint', 'from' => $superstar, 'when' => 'When one of their complaints reaches Unsatisfied or Upset (losing streak, contract demand, no title match, not being used).'],
            ['email' => 'Contract reminder', 'from' => $superstar, 'when' => "{$emails['contract_reminder_weeks']} weeks before their contract runs out."],
            ['email' => 'Return notice', 'from' => $superstar, 'when' => "{$emails['injury_return_notice_weeks']} weeks before a serious injury heals."],
        ];
    }
}
