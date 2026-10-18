<?php

use App\Enums\Brand;
use App\Enums\BrandScope;
use App\Enums\ChampionshipEligibility;
use App\Enums\InjurySeverity;
use App\Enums\MatchFamily;
use App\Enums\PpvKey;
use App\Enums\RosterType;
use App\Enums\WeightClass;
use App\Models\Championship;
use App\Models\InjuryType;
use App\Models\MatchType;
use App\Models\PpvEvent;
use App\Models\PromoType;
use App\Models\Wrestler;
use App\Models\WrestlerSpecialty;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
});

function wrestler(string $name): Wrestler
{
    return Wrestler::where('name', $name)->firstOrFail();
}

describe('roster (§9)', function () {
    it('seeds 98 wrestlers split across the placeholder brands and Legends', function () {
        expect(Wrestler::count())->toBe(98)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Raw)->count())->toBe(32)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(31)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Raw)->count())->toBe(6)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(7)
            ->and(Wrestler::where('roster_type', RosterType::Legend)->count())->toBe(22);
    });

    it('puts every Legend in the Free Agency pool, not on a brand', function () {
        expect(Wrestler::where('roster_type', RosterType::Legend)->whereNotNull('placeholder_brand')->count())->toBe(0);
    });

    it('moves Mick Foley to Raw as a Superstar', function () {
        $foley = wrestler('Mick Foley');

        expect($foley->roster_type)->toBe(RosterType::Superstar)
            ->and($foley->placeholder_brand)->toBe(Brand::Raw)
            ->and(Wrestler::where('name', 'Mankind')->exists())->toBeFalse();
    });

    it('uses plain names without nicknames', function () {
        expect(Wrestler::where('name', 'like', '%"%')->count())->toBe(0)
            ->and(Wrestler::where('name', 'Roddy Piper')->exists())->toBeTrue()
            ->and(Wrestler::where('name', 'Jerry Lawler')->exists())->toBeTrue()
            ->and(Wrestler::where('name', 'Stevie Richards')->exists())->toBeTrue();
    });

    it('has unique names', function () {
        expect(Wrestler::distinct()->count('name'))->toBe(98);
    });
});

describe('weight classes (§12)', function () {
    it('applies the Light Heavyweight rebalance', function (string $name) {
        expect(wrestler($name)->weight_class)->toBe(WeightClass::LightHeavyweight);
    })->with(['Carlito', 'Chris Jericho', 'Rob Van Dam', 'Shawn Michaels', 'Mr. Kennedy', 'Johnny Nitro', 'Christian', 'Eddie Guerrero', 'Stevie Richards']);

    it('classes the added wrestlers as specified', function (string $name, WeightClass $class) {
        expect(wrestler($name)->weight_class)->toBe($class);
    })->with([
        ['Jeff Hardy', WeightClass::LightHeavyweight],
        ['Val Venis', WeightClass::LightHeavyweight],
        ['Mick Foley', WeightClass::Heavyweight],
        ['Viscera', WeightClass::SuperHeavyweight],
        ['Umaga', WeightClass::SuperHeavyweight],
        ['JTG', WeightClass::Cruiserweight],
        ['Shad', WeightClass::Heavyweight],
        ['Bobby Lashley', WeightClass::Heavyweight],
        ['The Great Khali', WeightClass::UltraHeavyweight],
        ['Brian Kendrick', WeightClass::Cruiserweight],
        ['Deuce', WeightClass::LightHeavyweight],
        ['Domino', WeightClass::LightHeavyweight],
        ['Chavo Guerrero', WeightClass::Cruiserweight],
        ['Rey Mysterio', WeightClass::Cruiserweight],
        ['Sabu', WeightClass::LightHeavyweight],
    ]);

    it('makes every Diva a Featherweight', function () {
        expect(Wrestler::where('roster_type', RosterType::Diva)->where('weight_class', '!=', WeightClass::Featherweight)->count())->toBe(0);
    });

    it('limits Ultra Heavyweight to Andre The Giant and The Great Khali', function () {
        expect(Wrestler::where('weight_class', WeightClass::UltraHeavyweight)->orderBy('name')->pluck('name')->all())
            ->toBe(['Andre The Giant', 'The Great Khali']);
    });
});

describe('specialties (§21)', function () {
    it('seeds 30 specialties across 24 wrestlers', function () {
        expect(WrestlerSpecialty::count())->toBe(30)
            ->and(WrestlerSpecialty::distinct()->count('wrestler_id'))->toBe(24);
    });

    it('gives the listed wrestlers their specialties', function () {
        expect(wrestler('Shawn Michaels')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Ladder, MatchFamily::IronMan])
            ->and(wrestler('Edge')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Tlc, MatchFamily::Ladder])
            ->and(wrestler('Undertaker')->specialtyFamilies())->toBe([MatchFamily::HellInACell])
            ->and(wrestler('Bret Hart')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Submission, MatchFamily::IronMan])
            ->and(wrestler('Kurt Angle')->specialtyFamilies())->toBe([MatchFamily::Submission])
            ->and(collect(['Stevie Richards', 'Sabu', 'Terry Funk', 'Tommy Dreamer'])->map(fn ($n) => wrestler($n)->specialtyFamilies())->all())
            ->toBe(array_fill(0, 4, [MatchFamily::Hardcore]))
            ->and(wrestler('Batista')->specialtyFamilies())->toBe([]);
    });
});

describe('injury types (§6)', function () {
    it('seeds 13 minor and 22 serious injuries', function () {
        expect(InjuryType::where('severity', InjurySeverity::Minor)->count())->toBe(13)
            ->and(InjuryType::where('severity', InjurySeverity::Serious)->count())->toBe(22);
    });

    it('keeps minor durations within 1–4 weeks and serious within 5–52', function () {
        expect(InjuryType::where('severity', InjurySeverity::Minor)->where(fn ($q) => $q->where('min_weeks', '<', 1)->orWhere('max_weeks', '>', 4))->count())->toBe(0)
            ->and(InjuryType::where('severity', InjurySeverity::Serious)->where(fn ($q) => $q->where('min_weeks', '<', 5)->orWhere('max_weeks', '>', 52))->count())->toBe(0);
    });

    it('links each minor injury to its serious escalation', function () {
        $wrist = InjuryType::where('label', 'Sprained Wrist')->firstOrFail();
        $laceration = InjuryType::where('label', 'Laceration (Stitches)')->firstOrFail();

        expect($wrist->escalatesTo?->label)->toBe('Broken Wrist')
            ->and($wrist->escalatesTo?->severity)->toBe(InjurySeverity::Serious)
            ->and($laceration->escalates_to_id)->toBeNull()
            ->and(InjuryType::where('severity', InjurySeverity::Minor)->whereNotNull('escalates_to_id')->count())->toBe(12)
            ->and(InjuryType::where('severity', InjurySeverity::Serious)->whereNotNull('escalates_to_id')->count())->toBe(0);
    });
});

describe('PPVs (§13)', function () {
    it('seeds 14 PPVs: 5 Raw, 5 SmackDown, 4 shared', function () {
        expect(PpvEvent::count())->toBe(14)
            ->and(PpvEvent::where('brand_scope', BrandScope::Raw)->count())->toBe(5)
            ->and(PpvEvent::where('brand_scope', BrandScope::Smackdown)->count())->toBe(5)
            ->and(PpvEvent::where('brand_scope', BrandScope::Shared)->orderBy('season_week')->pluck('name')->all())
            ->toBe(['SummerSlam', 'Survivor Series', 'Royal Rumble', 'WrestleMania']);
    });

    it('ends the season on WrestleMania and gives each PPV its own week', function () {
        expect(PpvEvent::where('key', 'wrestlemania')->value('season_week'))->toBe(52)
            ->and(PpvEvent::distinct()->count('season_week'))->toBe(14);
    });
});

describe('match types (§10)', function () {
    it('seeds the full taxonomy', function () {
        expect(MatchType::count())->toBe(97)
            ->and(MatchType::where('family', MatchFamily::Hardcore)->count())->toBe(8)
            ->and(MatchType::where('family', MatchFamily::Ladder)->count())->toBe(8)
            ->and(MatchType::where('family', MatchFamily::RoyalRumble)->count())->toBe(5);
    });

    it('keeps every GM-bookable type within 2–6 competitors except the 30-man Rumble', function () {
        $outliers = MatchType::where('gm_bookable', true)
            ->where(fn ($q) => $q->where('max_competitors', '>', 6)->orWhere('min_competitors', '<', 2))
            ->pluck('name')->all();

        expect($outliers)->toBe(['Royal Rumble — 30-Man']);
    });

    it('assigns the PPV-exclusive matches to their PPVs', function () {
        $exclusive = MatchType::with('ppvExclusive')->whereNotNull('ppv_exclusive_id')->get()
            ->mapWithKeys(fn (MatchType $t) => [$t->name => $t->ppvExclusive?->key->value])->all();

        expect($exclusive)->toBe([
            'Royal Rumble — 30-Man' => 'royal_rumble',
            'Buried Alive — Single' => 'no_mercy',
            'Elimination Chamber — Normal 6-Man Chamber' => 'new_years_revolution',
            'Elimination Chamber — Tornado 6-Man Tag Chamber' => 'new_years_revolution',
            'Ladder — Money in the Bank' => 'wrestlemania',
            'Traditional Survivor Series Elimination — Traditional 3 vs 3' => 'survivor_series',
        ]);
    });

    it("makes Buried Alive SmackDown's No Mercy exclusive, as Elimination Chamber is Raw's", function () {
        $buried = MatchType::with('ppvExclusive')->where('family', MatchFamily::BuriedAlive)->firstOrFail();
        $chamber = MatchType::where('family', MatchFamily::EliminationChamber)->firstOrFail();

        expect($buried->ppv_only)->toBeTrue()
            ->and($buried->ppvExclusive?->key)->toBe(PpvKey::NoMercy)
            ->and($buried->brand_restriction)->toBe(Brand::Smackdown)
            ->and($chamber->brand_restriction)->toBe(Brand::Raw);
    });

    it('sets up Money in the Bank as a 6-man ladder variant with no titles or Divas', function () {
        $mitb = MatchType::where('variant', 'Money in the Bank')->firstOrFail();

        expect($mitb->isMoneyInTheBank())->toBeTrue()
            ->and($mitb->competitorCount())->toBe(6)
            ->and($mitb->titles_allowed)->toBeFalse()
            ->and($mitb->divas_allowed)->toBeFalse();
    });

    it('makes Fulfill Your Fantasy Divas-only and bookable on any card', function () {
        $fyf = MatchType::where('family', MatchFamily::FulfillYourFantasy)->firstOrFail();

        expect($fyf->divas_only)->toBeTrue()->and($fyf->ppv_only)->toBeFalse();
    });

    it('disables DQ and count-out on no-DQ families', function () {
        expect(MatchType::whereIn('family', [MatchFamily::Hardcore, MatchFamily::Tlc, MatchFamily::Ladder])->where('dq_enabled', true)->count())->toBe(0)
            ->and(MatchType::where('family', MatchFamily::Single)->where('dq_enabled', false)->count())->toBe(0);
    });

    it('gives every type a fatigue cost in whole bars and a price within $0–$50,000', function () {
        expect(MatchType::where('fatigue_cost_bars', '<', 1)->count())->toBe(0)
            ->and(MatchType::where('cost', '>', 50_000)->count())->toBe(0);
    });

    it('records managers only on w/Manager variants', function () {
        $withManagers = MatchType::all()->filter->hasManagers();

        expect($withManagers)->not->toBeEmpty();
        $withManagers->each(fn (MatchType $t) => expect($t->variant)->toContain('Manager'));
    });
});

describe('championships (§14)', function () {
    it('seeds 4 titles per brand with the right eligibility', function () {
        expect(Championship::where('brand', Brand::Raw)->count())->toBe(4)
            ->and(Championship::where('brand', Brand::Smackdown)->count())->toBe(4)
            ->and(Championship::where('key', 'womens')->value('eligibility'))->toBe(ChampionshipEligibility::DivasOnly)
            ->and(Championship::where('key', 'cruiserweight')->value('eligibility'))->toBe(ChampionshipEligibility::CruiserweightOrLightHeavyweight)
            ->and(Championship::where('is_world', true)->orderBy('sort_order')->pluck('key')->map->value->all())->toBe(['wwe', 'world_heavyweight'])
            ->and(Championship::where('is_tag', true)->count())->toBe(2);
    });

    it('applies the Cruiserweight eligibility rule', function () {
        $cruiser = Championship::where('key', 'cruiserweight')->firstOrFail();

        expect($cruiser->allowsWrestler(wrestler('Rey Mysterio')))->toBeTrue()
            ->and($cruiser->allowsWrestler(wrestler('Eddie Guerrero')))->toBeTrue()
            ->and($cruiser->allowsWrestler(wrestler('Batista')))->toBeFalse()
            ->and($cruiser->allowsWrestler(wrestler('Melina')))->toBeFalse();
    });

    it('limits the Women’s Championship to Divas', function () {
        $womens = Championship::where('key', 'womens')->firstOrFail();

        expect($womens->allowsWrestler(wrestler('Trish Stratus')))->toBeTrue()
            ->and($womens->allowsWrestler(wrestler('Edge')))->toBeFalse();
    });
});

describe('promo types (§5)', function () {
    it('seeds 11 promos with spec amounts and config success rates', function () {
        expect(PromoType::count())->toBe(11)
            ->and(PromoType::where('key', 'advertising')->value('base_amount'))->toBe(150_000)
            ->and(PromoType::where('key', 'new_superstar')->value('base_amount'))->toBe(-50_000)
            ->and(PromoType::where('targets_rival', true)->pluck('key')->map->value->sort()->values()->all())->toBe(['interference_promotion', 'slander_promotion']);

        PromoType::where('targets_rival', true)->get()->each(fn (PromoType $p) => expect($p->successRate())->toBe(0.5));
    });
});

it('is safe to run the catalog seeder twice', function () {
    $this->seed(CatalogSeeder::class);

    expect(Wrestler::count())->toBe(98)
        ->and(MatchType::count())->toBe(97)
        ->and(WrestlerSpecialty::count())->toBe(30);
});
