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
use App\Services\Calendar\CalendarService;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
});

function wrestler(string $name): Wrestler
{
    return Wrestler::where('name', $name)->firstOrFail();
}

describe('roster (§9)', function () {
    it('seeds 110 wrestlers split across the placeholder brands and Legends', function () {
        expect(Wrestler::count())->toBe(110)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Raw)->count())->toBe(34)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(36)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Raw)->count())->toBe(6)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(7)
            ->and(Wrestler::where('roster_type', RosterType::Legend)->count())->toBe(27);
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
        expect(Wrestler::distinct()->count('name'))->toBe(110);
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
        ['Matt Hardy', WeightClass::LightHeavyweight],
        ['Bubba Ray Dudley', WeightClass::Heavyweight],
        ['D-Von Dudley', WeightClass::Heavyweight],
        ['Mark Henry', WeightClass::SuperHeavyweight],
        ['The Boogeyman', WeightClass::Heavyweight],
        ['Super Crazy', WeightClass::Cruiserweight],
        ['Nunzio', WeightClass::Cruiserweight],
        ['Psicosis', WeightClass::Cruiserweight],
    ]);

    it('adds Bob Orton, Hacksaw Jim Duggan, Tatanka, Animal and Hawk as Legends', function (string $name) {
        expect(wrestler($name)->roster_type)->toBe(RosterType::Legend);
    })->with(['Bob Orton', 'Hacksaw Jim Duggan', 'Tatanka', 'Animal', 'Hawk']);

    it('makes every Diva a Featherweight', function () {
        expect(Wrestler::where('roster_type', RosterType::Diva)->where('weight_class', '!=', WeightClass::Featherweight)->count())->toBe(0);
    });

    it('limits Ultra Heavyweight to Andre The Giant and The Great Khali', function () {
        expect(Wrestler::where('weight_class', WeightClass::UltraHeavyweight)->orderBy('name')->pluck('name')->all())
            ->toBe(['Andre The Giant', 'The Great Khali']);
    });
});

describe('specialties (§21)', function () {
    it('seeds 33 specialties across 27 wrestlers', function () {
        expect(WrestlerSpecialty::count())->toBe(33)
            ->and(WrestlerSpecialty::distinct()->count('wrestler_id'))->toBe(27);
    });

    it('gives the listed wrestlers their specialties', function () {
        expect(wrestler('Shawn Michaels')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Ladder, MatchFamily::IronMan])
            ->and(wrestler('Edge')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Tlc, MatchFamily::Ladder])
            ->and(wrestler('Undertaker')->specialtyFamilies())->toBe([MatchFamily::HellInACell])
            ->and(wrestler('Bret Hart')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Submission, MatchFamily::IronMan])
            ->and(wrestler('Kurt Angle')->specialtyFamilies())->toBe([MatchFamily::Submission])
            ->and(collect(['Stevie Richards', 'Sabu', 'Terry Funk', 'Tommy Dreamer'])->map(fn ($n) => wrestler($n)->specialtyFamilies())->all())
            ->toBe(array_fill(0, 4, [MatchFamily::Hardcore]))
            ->and(collect(['Spike Dudley', 'Bubba Ray Dudley', 'D-Von Dudley'])->map(fn ($n) => wrestler($n)->specialtyFamilies())->all())
            ->toBe(array_fill(0, 3, [MatchFamily::Table]))
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
    it('seeds 15 PPVs: 6 Raw, 5 SmackDown, 4 shared', function () {
        expect(PpvEvent::count())->toBe(15)
            ->and(PpvEvent::where('brand_scope', BrandScope::Raw)->orderBy('season_week')->pluck('name')->all())
            ->toBe(['Backlash', 'Vengeance', 'Unforgiven', 'Cyber Sunday', 'Tribute to the Troops', "New Year's Revolution"])
            ->and(PpvEvent::where('brand_scope', BrandScope::Smackdown)->orderBy('season_week')->pluck('name')->all())
            ->toBe(['Judgment Day', 'The Great American Bash', 'No Mercy', 'Armageddon', 'No Way Out'])
            ->and(PpvEvent::where('brand_scope', BrandScope::Shared)->orderBy('season_week')->pluck('name')->all())
            ->toBe(['SummerSlam', 'Survivor Series', 'Royal Rumble', 'WrestleMania']);
    });

    it('ends the season on WrestleMania and gives each PPV its own week', function () {
        expect(PpvEvent::where('key', 'wrestlemania')->value('season_week'))->toBe(52)
            ->and(PpvEvent::distinct()->count('season_week'))->toBe(15);
    });

    it('airs every PPV on its Sunday of the 2005 calendar, every season', function (string $key, string $date, string $month) {
        $calendar = app(CalendarService::class);
        $week = (int) PpvEvent::where('key', $key)->value('season_week');

        foreach ([1, 2] as $season) {
            $absolute = $calendar->absoluteWeek($season, $week);

            expect($calendar->date($absolute)->format('M j'))->toBe($date)
                ->and($calendar->date($absolute)->isSunday())->toBeTrue()
                ->and($calendar->monthLabel($absolute))->toBe($month);
        }

        expect(PpvEvent::where('key', $key)->value('month'))->toBe($month);
    })->with([
        ['new_years_revolution', 'Jan 9', 'January'],
        ['royal_rumble', 'Jan 30', 'January'],
        ['no_way_out', 'Feb 20', 'February'],
        ['wrestlemania', 'Mar 27', 'March'],
        ['backlash', 'Apr 24', 'April'],
        ['judgment_day', 'May 22', 'May'],
        ['vengeance', 'Jun 26', 'June'],
        ['great_american_bash', 'Jul 24', 'July'],
        ['summerslam', 'Aug 21', 'August'],
        ['unforgiven', 'Sep 18', 'September'],
        ['no_mercy', 'Oct 9', 'October'],
        ['cyber_sunday', 'Nov 6', 'November'],
        ['survivor_series', 'Nov 27', 'November'],
        ['armageddon', 'Dec 18', 'December'],
        ['tribute_to_the_troops', 'Dec 25', 'December'],
    ]);

    it('airs Raw on Mondays and SmackDown on Fridays in the days before each Sunday', function () {
        $calendar = app(CalendarService::class);

        foreach (range(1, 104) as $week) {
            $sunday = $calendar->date($week);
            $raw = $calendar->showDate($week, Brand::Raw);
            $smackdown = $calendar->showDate($week, Brand::Smackdown);

            expect($sunday->isSunday())->toBeTrue()
                ->and($raw->isMonday())->toBeTrue()
                ->and($smackdown->isFriday())->toBeTrue()
                ->and($sunday->diffInDays($raw, absolute: true))->toEqual(6)
                ->and($sunday->diffInDays($smackdown, absolute: true))->toEqual(2);
        }

        // Week 1 opens with Raw the night after WrestleMania (Mar 27).
        expect($calendar->showDate(1, Brand::Raw)->format('D M j'))->toBe('Mon Mar 28')
            ->and(collect(range(1, 5))->map(fn ($w) => $calendar->showDate($w, Brand::Smackdown)->format('M j'))->all())
            ->toBe(['Apr 1', 'Apr 8', 'Apr 15', 'Apr 22', 'Apr 29'])
            ->and(collect(range(2, 5))->map(fn ($w) => $calendar->showDate($w, Brand::Raw)->format('M j'))->all())
            ->toBe(['Apr 4', 'Apr 11', 'Apr 18', 'Apr 25']);
    });

    it('starts each season the Sunday after WrestleMania', function () {
        $calendar = app(CalendarService::class);

        expect($calendar->date(1)->format('M j'))->toBe('Apr 3')
            ->and($calendar->date(53)->format('M j'))->toBe('Apr 3');
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
            'Elimination Chamber — Triple Threat Tornado Tag Chamber' => 'new_years_revolution',
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
            ->and(MatchType::where('family', MatchFamily::Single)->where('dq_enabled', false)->pluck('variant')->sort()->values()->all())->toBe(['Normal Fatal 4-Way', 'Normal Triple Threat']);
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

    expect(Wrestler::count())->toBe(110)
        ->and(MatchType::count())->toBe(97)
        ->and(WrestlerSpecialty::count())->toBe(33);
});

it('groups the Schedule by the brand’s show night', function () {
    $months = collect(app(CalendarService::class)->seasonGrid(1, Brand::Smackdown))
        ->mapWithKeys(fn ($m) => [$m['month'] => collect($m['weeks'])->pluck('season_week')->all()]);

    // Fri Apr 29 is week 5 (its Sunday is May 1) but still sits under April.
    expect($months['April'])->toBe([1, 2, 3, 4, 5])
        ->and($months['May'][0])->toBe(6);
});
