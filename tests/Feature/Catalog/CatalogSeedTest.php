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
    it('seeds 160 wrestlers: the Nov 2005 brands, free agents and Legends', function () {
        expect(Wrestler::count())->toBe(160)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Raw)->count())->toBe(25)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(28)
            ->and(Wrestler::where('roster_type', RosterType::Superstar)->whereNull('placeholder_brand')->count())->toBe(48)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Raw)->count())->toBe(5)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->where('placeholder_brand', Brand::Smackdown)->count())->toBe(2)
            ->and(Wrestler::where('roster_type', RosterType::Diva)->whereNull('placeholder_brand')->count())->toBe(15)
            ->and(Wrestler::where('roster_type', RosterType::Legend)->count())->toBe(37);
    });

    it('fills each brand to the 30-wrestler roster cap', function () {
        expect(Wrestler::where('placeholder_brand', Brand::Raw)->count())->toBe(30)
            ->and(Wrestler::where('placeholder_brand', Brand::Smackdown)->count())->toBe(30);
    });

    it('places wrestlers on their Nov 14, 2005 brands', function (string $name, ?Brand $brand) {
        expect(wrestler($name)->placeholder_brand)->toBe($brand);
    })->with([
        ['Hacksaw Jim Duggan', Brand::Raw],
        ['Tatanka', Brand::Raw],
        ['Torrie Wilson', Brand::Raw],
        ['Rene Dupree', Brand::Raw],
        ['Road Warrior Animal', Brand::Smackdown],
        ['Eddie Guerrero', Brand::Smackdown],
        ['Sylvain Grenier', Brand::Smackdown],
        ['Stevie Richards', Brand::Smackdown],
        ['Chavo Guerrero', null],
        ['Jerry Lawler', null],
        ['Mark Henry', null],
        ['Matt Hardy', null],
        ['Jeff Hardy', null],
        ['The Great Khali', null],
        ['Cherry', null],
        ['Mae Young', null],
        ['Goldust', null],
        ['Al Snow', null],
    ]);

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
        expect(Wrestler::distinct()->count('name'))->toBe(160);
    });
});

describe('weight classes (§12)', function () {
    it('applies the Light Heavyweight rebalance', function (string $name) {
        expect(wrestler($name)->weight_class)->toBe(WeightClass::LightHeavyweight);
    })->with(['Carlito', 'Chris Jericho', 'Rob Van Dam', 'Shawn Michaels', 'Mr. Kennedy', 'Johnny Nitro', 'Christian', 'Eddie Guerrero', 'Stevie Richards', 'Chris Benoit', 'Joey Mercury', 'Eugene']);

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

    it('keeps Bob Orton, Roddy Piper, Tazz, Sabu and Road Warrior Hawk as Legends', function (string $name) {
        expect(wrestler($name)->roster_type)->toBe(RosterType::Legend);
    })->with(['Bob Orton', 'Roddy Piper', 'Tazz', 'Sabu', 'Road Warrior Hawk']);

    it('turns Legends who were active in 2005 into Superstars', function (string $name) {
        expect(wrestler($name)->roster_type)->toBe(RosterType::Superstar);
    })->with(['Hacksaw Jim Duggan', 'Tatanka', 'Road Warrior Animal', 'Goldust', 'Jimmy Wang Yang', 'Tommy Dreamer', 'Al Snow']);

    it('classes the 2005 additions as listed', function (string $name, WeightClass $class) {
        expect(wrestler($name)->weight_class)->toBe($class);
    })->with([
        ['Dolph Ziggler', WeightClass::LightHeavyweight],
        ['Kenzo Suzuki', WeightClass::Heavyweight],
        ['Lance Storm', WeightClass::LightHeavyweight],
        ['Marty Jannetty', WeightClass::LightHeavyweight],
        ['Maven', WeightClass::LightHeavyweight],
        ['Rhino', WeightClass::Heavyweight],
        ['Rosey', WeightClass::SuperHeavyweight],
        ['Tyson Tomko', WeightClass::Heavyweight],
        ['Billy Kidman', WeightClass::Cruiserweight],
        ['The Blue Meanie', WeightClass::SuperHeavyweight],
        ['Frankie Kazarian', WeightClass::Cruiserweight],
        ['Funaki', WeightClass::Cruiserweight],
        ['Hardcore Holly', WeightClass::Heavyweight],
        ['Jamie Noble', WeightClass::Cruiserweight],
        ['Juventud', WeightClass::Cruiserweight],
        ['Kid Kash', WeightClass::Cruiserweight],
        ['Luther Reigns', WeightClass::Heavyweight],
        ['Matt Morgan', WeightClass::SuperHeavyweight],
        ['Paul Burchill', WeightClass::Heavyweight],
        ['Shannon Moore', WeightClass::Cruiserweight],
        ['Simon Dean', WeightClass::Cruiserweight],
        ['Vito', WeightClass::Heavyweight],
        ['Al Snow', WeightClass::LightHeavyweight],
        ['Arn Anderson', WeightClass::Heavyweight],
        ['The Brooklyn Brawler', WeightClass::Heavyweight],
        ['Dean Malenko', WeightClass::Cruiserweight],
        ['Fit Finlay', WeightClass::LightHeavyweight],
        ['Gerald Brisco', WeightClass::LightHeavyweight],
        ['Jerry Lawler', WeightClass::LightHeavyweight],
        ['Goldust', WeightClass::LightHeavyweight],
        ['Jack Brisco', WeightClass::LightHeavyweight],
        ['Jimmy Snuka', WeightClass::LightHeavyweight],
        ['Jimmy Wang Yang', WeightClass::Cruiserweight],
        ['Michael Hayes', WeightClass::Heavyweight],
        ['Mr. McMahon', WeightClass::Heavyweight],
        ['Pat Patterson', WeightClass::LightHeavyweight],
        ['Ricky Steamboat', WeightClass::LightHeavyweight],
    ]);

    it('adds the 2005 extras as Legends', function (string $name) {
        expect(wrestler($name)->roster_type)->toBe(RosterType::Legend);
    })->with([
        'Arn Anderson', 'The Brooklyn Brawler', 'Dean Malenko', 'Fit Finlay', 'Gerald Brisco', 'Jack Brisco',
        'Jimmy Snuka', 'Michael Hayes', 'Mr. McMahon', 'Pat Patterson', 'Ricky Steamboat', 'Jerry Lawler',
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
    it('seeds 44 specialties across 33 wrestlers', function () {
        expect(WrestlerSpecialty::count())->toBe(44)
            ->and(WrestlerSpecialty::distinct()->count('wrestler_id'))->toBe(33);
    });

    it('gives the listed wrestlers their specialties', function () {
        expect(wrestler('Shawn Michaels')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Ladder, MatchFamily::IronMan])
            ->and(wrestler('Edge')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Tlc, MatchFamily::Ladder])
            ->and(wrestler('Undertaker')->specialtyFamilies())->toBe([MatchFamily::HellInACell])
            ->and(wrestler('Bret Hart')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Submission, MatchFamily::IronMan])
            ->and(wrestler('Kurt Angle')->specialtyFamilies())->toBe([MatchFamily::Submission])
            ->and(collect(['Stevie Richards', 'Sabu', 'Terry Funk', 'Tommy Dreamer'])->map(fn ($n) => wrestler($n)->specialtyFamilies())->all())
            ->toBe(array_fill(0, 4, [MatchFamily::Hardcore]))
            ->and(collect(['Bubba Ray Dudley', 'D-Von Dudley'])->map(fn ($n) => collect(wrestler($n)->specialtyFamilies())->sortBy('value')->values()->all())->all())
            ->toBe(array_fill(0, 2, [MatchFamily::Hardcore, MatchFamily::Table]))
            ->and(collect(['Hardcore Holly', 'Rhino', 'Bam Bam Bigelow'])->map(fn ($n) => wrestler($n)->specialtyFamilies())->all())
            ->toBe(array_fill(0, 3, [MatchFamily::Hardcore]))
            ->and(wrestler('The Blue Meanie')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::FirstBlood, MatchFamily::Hardcore])
            ->and(wrestler('Spike Dudley')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Table, MatchFamily::Hardcore])
            ->and(wrestler('Mick Foley')->specialtyFamilies())->toEqualCanonicalizing([MatchFamily::Hardcore, MatchFamily::HellInACell])
            ->and(wrestler('Kane')->specialtyFamilies())->toBe([MatchFamily::FirstBlood])
            ->and(wrestler('Stone Cold Steve Austin')->specialtyFamilies())->toBe([MatchFamily::FirstBlood])
            ->and(wrestler('Batista')->specialtyFamilies())->toBe([]);
    });
});

describe('injury types (§6)', function () {
    it('seeds 13 minor and 22 serious injuries', function () {
        expect(InjuryType::where('severity', InjurySeverity::Minor)->count())->toBe(13)
            ->and(InjuryType::where('severity', InjurySeverity::Serious)->count())->toBe(22);
    });

    it('keeps minor durations within 1–5 weeks and serious within 5–52', function () {
        expect(InjuryType::where('severity', InjurySeverity::Minor)->where(fn ($q) => $q->where('min_weeks', '<', 1)->orWhere('max_weeks', '>', 5))->count())->toBe(0)
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

    it('airs every PPV on its Sunday of the April 2005 – March 2006 calendar, every season', function (string $key, string $date, string $month) {
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
        ['new_years_revolution', 'Jan 8', 'January'],
        ['royal_rumble', 'Jan 29', 'January'],
        ['no_way_out', 'Feb 19', 'February'],
        ['wrestlemania', 'Mar 26', 'March'],
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

    it('starts each week on Sunday, with Raw the Monday and SmackDown the Friday after', function () {
        $calendar = app(CalendarService::class);

        foreach (range(1, 104) as $week) {
            $sunday = $calendar->date($week);
            $raw = $calendar->showDate($week, Brand::Raw);
            $smackdown = $calendar->showDate($week, Brand::Smackdown);

            expect($sunday->isSunday())->toBeTrue()
                ->and($raw->isMonday())->toBeTrue()
                ->and($smackdown->isFriday())->toBeTrue()
                ->and($sunday->diffInDays($raw, absolute: false))->toEqual(1)
                ->and($sunday->diffInDays($smackdown, absolute: false))->toEqual(5);
        }

        // Backlash (week 4, Sunday Apr 24) airs before that week's Raw and SmackDown.
        expect($calendar->showDate(1, Brand::Raw)->format('D M j Y'))->toBe('Mon Apr 4 2005')
            ->and(collect(range(1, 5))->map(fn ($w) => $calendar->showDate($w, Brand::Smackdown)->format('M j'))->all())
            ->toBe(['Apr 8', 'Apr 15', 'Apr 22', 'Apr 29', 'May 6'])
            ->and($calendar->showDate(4, Brand::Raw)->format('M j'))->toBe('Apr 25')
            ->and($calendar->showDate(52, Brand::Smackdown)->format('M j Y'))->toBe('Mar 31 2006');
    });

    it('starts each season the Sunday after WrestleMania', function () {
        $calendar = app(CalendarService::class);

        expect($calendar->date(1)->format('M j Y'))->toBe('Apr 3 2005')
            ->and($calendar->date(52)->format('M j Y'))->toBe('Mar 26 2006')
            ->and($calendar->date(53)->format('M j Y'))->toBe('Apr 3 2005');
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

    it('gives every type a fatigue cost in whole bars and a price within $0–$55,000', function () {
        expect(MatchType::where('fatigue_cost_bars', '<', 1)->count())->toBe(0)
            ->and(MatchType::where('cost', '>', 55_000)->pluck('name')->all())->toBe([]);
    });

    it('prices match types per the 2026-10-20 house rules', function (string $family, string $variant, int $cost) {
        expect(MatchType::where('family', $family)->where('variant', $variant)->value('cost'))->toBe($cost);
    })->with([
        ['single', 'No Manager', 0],
        ['single', 'Normal Triple Threat', 1_000],
        ['tag_team', 'Tornado Tag Elimination', 2_000],
        ['six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination', 4_000],
        ['handicap', 'Two on Trio', 1_000],
        ['backstage_brawl', 'Backstage Brawl', 16_000],
        ['backstage_brawl', 'Bar Brawl', 31_000],
        ['backstage_brawl', 'Parking Lot Brawl', 28_000],
        ['battle_royal', '6-Man Over the Top Rope', 25_000],
        ['first_blood', 'Single', 1_000],
        ['fulfill_your_fantasy', 'Single', 22_000],
        ['hardcore', 'Single w/Manager vs. w/Manager', 19_000],
        ['hardcore', 'Tornado 6-Man Tag', 25_000],
        ['hardcore', 'Triple Threat Tornado Tag', 25_000],
        ['hell_in_a_cell', 'Fatal 4-Way', 45_000],
        ['hell_in_a_cell', 'Armageddon Hell in a Cell', 55_000],
        ['iron_man', 'Single', 37_000],
        ['ladder', 'Fatal 4-Way', 12_000],
        ['ladder', 'Triple Threat Tornado Tag', 16_000],
        ['last_man_standing', 'Single', 40_000],
        ['special_referee', 'Single', 0],
        ['special_referee', 'Hell in a Cell', 45_000],
        ['special_referee', 'Iron Man', 37_000],
        ['special_referee', 'Last Man Standing', 40_000],
        ['steel_cage', 'Fatal 4-Way Pin & Give Up', 25_000],
        ['submission', 'Single', 1_000],
        ['submission', 'Ultimate Submission', 2_000],
        ['table', 'Fatal 4-Way', 5_000],
        ['table', 'Triple Threat Tornado Tag', 8_000],
        ['tlc', 'Fatal 4-Way', 8_000],
        ['tlc', 'Triple Threat Tornado Tag', 12_000],
    ]);

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
    it('seeds 14 promos with spec amounts and config success rates', function () {
        expect(PromoType::count())->toBe(14)
            ->and(PromoType::where('key', 'advertising')->value('base_amount'))->toBe(150_000)
            ->and(PromoType::where('key', 'new_superstar')->value('base_amount'))->toBe(-50_000)
            ->and(PromoType::where('targets_rival', true)->pluck('key')->map->value->sort()->values()->all())->toBe(['interference_promotion', 'slander_promotion']);

        PromoType::where('targets_rival', true)->get()->each(fn (PromoType $p) => expect($p->successRate())->toBe(0.5));
    });
});

it('is safe to run the catalog seeder twice', function () {
    $this->seed(CatalogSeeder::class);

    expect(Wrestler::count())->toBe(160)
        ->and(MatchType::count())->toBe(97)
        ->and(WrestlerSpecialty::count())->toBe(44);
});

it('lays the season out as Sunday-first months from April 2005 to March 2006', function () {
    $months = collect(app(CalendarService::class)->seasonMonths(1));
    $days = fn (string $key) => collect($months->firstWhere('key', $key)['rows'])->flatten(1);
    $airs = fn (string $key, string $date) => collect($days($key)->firstWhere('date', $date)['airs']);

    expect($months->pluck('label')->first())->toBe('April 2005')
        ->and($months->pluck('label')->last())->toBe('March 2006')
        ->and($months)->toHaveCount(12)
        // April 1, 2005 is a Friday, so the grid starts on Sunday Mar 27.
        ->and($days('2005-04')->first())->toMatchArray(['date' => '2005-03-27', 'in_month' => false])
        ->and(collect($months->firstWhere('key', '2005-04')['rows'])->every(fn ($row) => count($row) === 7))->toBeTrue()
        // Week 4: Backlash on Sunday, then Raw on Monday and Smackdown on Friday.
        ->and($airs('2005-04', '2005-04-24')->pluck('ppv.name')->all())->toBe(['Backlash'])
        ->and($airs('2005-04', '2005-04-25')->first())->toMatchArray(['week' => 4, 'kind' => 'weekly', 'brand' => 'raw'])
        ->and($airs('2005-04', '2005-04-29')->first())->toMatchArray(['week' => 4, 'kind' => 'weekly', 'brand' => 'smackdown'])
        // Shared PPVs have no brand; WrestleMania closes the season on Mar 26, 2006.
        ->and($airs('2006-03', '2006-03-26')->first())->toMatchArray(['season_week' => 52, 'kind' => 'ppv', 'brand' => null]);
});
