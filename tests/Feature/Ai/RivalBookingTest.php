<?php

use App\Enums\Brand;
use App\Enums\ParticipantRole;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Ai\IntergenderRules;
use Illuminate\Support\Collection;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The rival GM's match booking (house rules, gm.ai.booking / gm.ai.titles).
| The player runs SmackDown here, so the rival is Raw.
*/

const RIVAL_MEN = ['Big Show', 'Lance Cade', 'Trevor Murdoch', 'Carlito', 'Chris Masters', 'Edge', 'Eugene', 'The Hurricane', 'John Cena', 'Kane', 'Kurt Angle', 'Rene Dupree', 'Ric Flair', 'Rob Conway', 'Rob Van Dam', 'Shawn Michaels', 'Shelton Benjamin', 'Snitsky', 'Tajiri', 'Triple H'];
const RIVAL_DIVAS = ['Trish Stratus', 'Lita', 'Mickie James', 'Victoria', 'Torrie Wilson'];

/**
 * @param  list<string>  $raw
 */
function rivalSave(array $raw, string $seed = GameBuilder::SEED): Save
{
    return GameBuilder::inSeason(raw: $raw, smackdown: ['Batista', 'Rey Mysterio'], player: Brand::Smackdown, seed: $seed);
}

function rivalCard(Save $save): Show
{
    $show = Card::show($save, Brand::Raw);
    app(AiGm::class)->bookShow($show);

    return $show;
}

/**
 * @return Collection<int, MatchSlot>
 */
function rivalSlots(Show $show): Collection
{
    return MatchSlot::with(['participants.saveWrestler.wrestler', 'matchType'])
        ->where('show_id', $show->id)
        ->orderBy('position')
        ->get()
        ->filter(fn (MatchSlot $s): bool => $s->isBooked())
        ->values()
        ->toBase();
}

/**
 * @return list<int>
 */
function divaIds(Save $save): array
{
    return array_values(array_map('intval', SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('roster_type', 'diva'))->pluck('id')->all()));
}

function isAllDiva(MatchSlot $slot): bool
{
    return $slot->competitors()->every(fn ($p): bool => $p->saveWrestler->wrestler->isDiva());
}

/**
 * @return array<int, int> side => manager id
 */
function slotManagers(MatchSlot $slot): array
{
    return $slot->participants->where('role', ParticipantRole::Manager)->mapWithKeys(fn ($p): array => [$p->side => $p->save_wrestler_id])->all();
}

describe('gender', function () {
    it('books every match single-gender, managers included, with intergender off', function () {
        config(['gm.ai.booking.intergender_chance' => 0.0]);

        foreach (range(1, 5) as $seed) {
            $save = rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "gender-{$seed}");
            $divas = divaIds($save);

            foreach (rivalSlots(rivalCard($save)) as $slot) {
                $everyone = [...$slot->competitorIds(), ...array_values(slotManagers($slot))];
                $count = count(array_intersect($everyone, $divas));

                expect($count === 0 || $count === count($everyone))->toBeTrue();
            }
        }
    });

    it('always books an all-Diva match with 2 or more bookable Divas, and none with fewer', function () {
        foreach (range(1, 4) as $seed) {
            $two = rivalSlots(rivalCard(rivalSave([...RIVAL_MEN, 'Trish Stratus', 'Lita'], "divas-{$seed}")));
            expect($two->filter(fn (MatchSlot $s): bool => isAllDiva($s)))->not->toBeEmpty();
        }

        $one = rivalSlots(rivalCard(rivalSave([...RIVAL_MEN, 'Trish Stratus'], 'divas-one')));
        $none = rivalSlots(rivalCard(rivalSave(RIVAL_MEN, 'divas-none')));

        expect($one->filter(fn (MatchSlot $s): bool => isAllDiva($s)))->toBeEmpty()
            ->and($none->filter(fn (MatchSlot $s): bool => isAllDiva($s)))->toBeEmpty();
    });

    it('moves the Diva match around the card, and puts a Women’s title match on match 5 or the main event', function () {
        config(['gm.ai.booking.intergender_chance' => 0.0]);
        $positions = [];

        foreach (range(1, 8) as $seed) {
            $slot = rivalSlots(rivalCard(rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "divaslot-{$seed}")))->first(fn (MatchSlot $s): bool => isAllDiva($s));
            $positions[] = $slot->position;
        }

        expect(array_unique($positions))->not->toHaveCount(1);

        config(['gm.ai.titles.other_slot_chance' => 1.0]);

        foreach (range(1, 6) as $seed) {
            $save = rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "womens-{$seed}");
            GameBuilder::giveTitle($save, 'womens', ['Trish Stratus']);

            $womens = rivalSlots(rivalCard($save))->first(fn (MatchSlot $s): bool => $s->titleChampionship?->key->value === 'womens');

            expect($womens)->not->toBeNull()
                ->and($womens->position)->toBeIn([5, 6]);
        }
    });

    it('only uses the one-Diva-manager shapes with a single Diva', function () {
        config(['gm.ai.booking.intergender_chance' => 1.0]);
        $rules = app(IntergenderRules::class);
        $shapes = [];

        foreach (range(1, 6) as $seed) {
            $save = rivalSave([...RIVAL_MEN, 'Trish Stratus'], "onediva-{$seed}");
            $divas = divaIds($save);

            foreach (rivalSlots(rivalCard($save)) as $slot) {
                $everyone = [...$slot->competitorIds(), ...array_values(slotManagers($slot))];

                if (array_intersect($everyone, $divas) !== []) {
                    $shapes[] = $rules->shape($slot->matchType);
                }
            }
        }

        expect($shapes)->not->toBeEmpty()
            ->and(array_diff($shapes, [2, 6]))->toBeEmpty();
    });

    it('fits every intergender match to one of the seven shapes', function () {
        config(['gm.ai.booking.intergender_chance' => 1.0]);
        $rules = app(IntergenderRules::class);
        $mixed = 0;

        foreach (range(1, 6) as $seed) {
            $save = rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "shapes-{$seed}");
            $divas = divaIds($save);

            foreach (rivalSlots(rivalCard($save)) as $slot) {
                $sides = array_values($slot->sides());
                $managers = slotManagers($slot);

                expect($rules->allows($slot->matchType, $sides, $managers, $divas))->toBeTrue();

                if (array_intersect([...array_merge(...$sides), ...array_values($managers)], $divas) !== [] && ! isAllDiva($slot)) {
                    $mixed++;
                }
            }
        }

        expect($mixed)->toBeGreaterThan(0);
    });
});

describe('fatigue', function () {
    function tireRoster(Save $save): void
    {
        foreach (SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->orderBy('id')->get() as $i => $w) {
            $w->update(['fatigue' => [0, 30, 50, 60, 70][$i % 5]]);
        }
    }

    it('keeps everyone at or under 70% in careful matches', function () {
        config(['gm.ai.booking.careful_chance' => 1.0]);

        foreach (range(1, 4) as $seed) {
            $save = rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "careful-{$seed}");
            tireRoster($save);

            foreach (rivalSlots(rivalCard($save)) as $slot) {
                foreach ($slot->competitors() as $p) {
                    expect($p->saveWrestler->fatigue + $slot->matchType->fatigueCost())->toBeLessThanOrEqual(70);
                }
            }
        }
    });

    it('lets aggressive matches go up to 110% and prefers heavy gimmicks', function () {
        config(['gm.ai.booking.careful_chance' => 0.0]);
        $heavy = 0;

        foreach (range(1, 4) as $seed) {
            $save = rivalSave([...RIVAL_MEN, ...RIVAL_DIVAS], "aggressive-{$seed}");
            tireRoster($save);

            foreach (rivalSlots(rivalCard($save)) as $slot) {
                foreach ($slot->competitors() as $p) {
                    expect($p->saveWrestler->fatigue + $slot->matchType->fatigueCost())->toBeLessThanOrEqual(110);
                }

                $heavy += $slot->matchType->fatigue_cost_bars >= 3 ? 1 : 0;
            }
        }

        expect($heavy)->toBeGreaterThan(8);
    });
});

describe('titles', function () {
    it('puts the world title on the main event when the champion is fit', function () {
        config(['gm.ai.titles.main_event_chance.weekly' => 1.0, 'gm.ai.booking.careful_chance' => 1.0]);
        $save = rivalSave(RIVAL_MEN);
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);

        $main = rivalSlots(rivalCard($save))->firstWhere('position', 6);

        expect($main->titleChampionship?->key->value)->toBe('wwe')
            ->and($main->competitorIds())->toContain(GameBuilder::wrestler($save, 'Edge')->id);
    });

    it('skips a worn-out champion on a careful slot', function () {
        config(['gm.ai.titles.main_event_chance.weekly' => 1.0, 'gm.ai.titles.other_slot_chance' => 1.0, 'gm.ai.booking.careful_chance' => 1.0]);
        $save = rivalSave(RIVAL_MEN);
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        GameBuilder::giveTitle($save, 'intercontinental', ['Kane']);
        GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 70]);

        $slots = rivalSlots(rivalCard($save));
        $edge = GameBuilder::wrestler($save, 'Edge')->id;

        expect($slots->filter(fn (MatchSlot $s): bool => $s->titleChampionship?->key->value === 'wwe'))->toBeEmpty()
            ->and($slots->filter(fn (MatchSlot $s): bool => in_array($edge, $s->competitorIds(), true)))->toBeEmpty()
            ->and($slots->firstWhere('position', 6)->titleChampionship?->key->value)->toBe('intercontinental');
    });
});
