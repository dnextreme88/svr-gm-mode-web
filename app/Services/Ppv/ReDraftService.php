<?php

namespace App\Services\Ppv;

use App\Enums\Brand;
use App\Enums\ContendershipVoidReason;
use App\Enums\DraftPhase;
use App\Enums\DraftPickSource;
use App\Enums\DraftPickType;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\DraftPick;
use App\Models\InterbrandSeries;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Ai\AiTradeJudge;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\ContendershipService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * The post-WrestleMania re-draft (house rule, 2026-11-15; bigger since
 * 2026-11-17): 10 superstars swap brands each way, one pick at a time,
 * alternating. No roster cap applies, since every pick is one for one.
 *
 * - The **immunity tag** goes to the brand whose weekly shows drew more fans
 *   this season before WrestleMania (`SummerSlamMainEvent::totals`): it
 *   protects 3 of its own non-champions from being picked.
 * - The **manual draft pick** goes to the interbrand series winner: it
 *   picks first, and its first 2 picks are the superstars it chose.
 *
 * The rival GM tags its most valuable superstars (`AiTradeJudge`). The
 * page steps the picks one at a time (`step()`) so it can animate them;
 * `finish()` writes the WWE headline and opens the new season.
 */
class ReDraftService
{
    public function __construct(
        private TitleService $titles,
        private MitbService $mitb,
        private NewsRecorder $news,
        private EmailService $emails,
        private ContendershipService $contenderships,
        private AiTradeJudge $judge,
        private SummerSlamMainEvent $crowds,
    ) {}

    public function series(Save $save): ?InterbrandSeries
    {
        return InterbrandSeries::where('save_id', $save->id)->where('redraft_done', false)->latest('id')->first();
    }

    public function perBrand(): int
    {
        return (int) config('gm.ppv.redraft_picks_per_brand');
    }

    /** How many superstars the immunity tag protects. */
    public function immunityCount(): int
    {
        return (int) config('gm.ppv.redraft_immunity_count');
    }

    /** How many of its first picks the manual draft pick chooses. */
    public function manualCount(): int
    {
        return min((int) config('gm.ppv.redraft_manual_picks'), $this->perBrand());
    }

    /**
     * How many the immunity tag must protect: its count, or everyone
     * eligible when the brand has fewer.
     */
    public function immunityNeeded(Save $save, InterbrandSeries $series): int
    {
        return $series->immunity_brand === null ? 0 : min($this->immunityCount(), $this->eligibleFrom($save, $series->immunity_brand)->count());
    }

    /**
     * How many the manual draft pick must take: its count, or everyone in
     * its pool when there are fewer.
     */
    public function manualNeeded(Save $save, InterbrandSeries $series): int
    {
        return min($this->manualCount(), $this->handpickPool($save, $series)->count());
    }

    /**
     * Non-champions on a brand who can be drafted away.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, SaveWrestler>
     */
    public function eligibleFrom(Save $save, Brand $brand, array $exclude = []): Collection
    {
        $champions = $this->titles->currentReigns($save)->flatMap(fn ($r) => $r->holderIds())->all();

        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->whereNotIn('id', [...$champions, ...$exclude])
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Each brand's weekly-show crowds this season before WrestleMania week.
     *
     * @return array<string, int>
     */
    public function crowds(Save $save, InterbrandSeries $series): array
    {
        $totals = $this->crowds->totals($save, $series->season, $series->week_number);

        return array_map(fn (array $t): int => $t['crowd'], $totals);
    }

    /**
     * Award the immunity tag (if it isn't yet) and let the rival GM use the
     * tags it holds. Idempotent: the page calls it on every visit.
     */
    public function prepare(Save $save): ?InterbrandSeries
    {
        $series = $this->series($save);

        if ($series === null || $save->stage !== SaveStage::Redrafting) {
            return $series;
        }

        if ($series->immunity_brand === null) {
            $series->update(['immunity_brand' => $this->immunityBrand($save, $series)]);
        }

        $rng = $save->rng('redraft.tags', $series->week_number);
        $immunity = $series->immunity_brand;

        if ($immunity !== null && ! $save->isHuman($immunity) && $series->exemptIds() === []) {
            $series->update(['exemption_wrestler_ids' => $this->mostValuableMany($save, $immunity, $this->eligibleFrom($save, $immunity), $this->immunityCount(), $rng)]);
        }

        // The manual pick waits for the immunity tag, so it can't take the
        // protected superstars.
        $winner = $series->winner_brand;

        if ($winner !== null && ! $save->isHuman($winner) && $series->handpickIds() === [] && $this->immunityDecided($save, $series)) {
            $series->update(['handpick_wrestler_ids' => $this->mostValuableMany($save, $winner, $this->handpickPool($save, $series), $this->manualCount(), $rng)]);
        }

        return $series->refresh();
    }

    /**
     * Where the re-draft stands: `immunity` (the player protects someone),
     * `manual` (the player hand-picks), `picks`, or `done`.
     */
    public function phase(Save $save, InterbrandSeries $series): string
    {
        if (! $this->immunityDecided($save, $series)) {
            return 'immunity';
        }

        if ($series->winner_brand !== null && $save->isHuman($series->winner_brand) && $series->handpickIds() === [] && $this->handpickPool($save, $series)->isNotEmpty()) {
            return 'manual';
        }

        return $this->picks($save)->count() < count($this->order($save, $series)) ? 'picks' : 'done';
    }

    /**
     * @param  list<int>  $wrestlerIds
     */
    public function setImmunity(Save $save, array $wrestlerIds): InterbrandSeries
    {
        $series = $this->openSeries($save);
        $brand = $series->immunity_brand;

        if ($brand === null || ! $save->isHuman($brand)) {
            throw new GameRuleException('The other brand holds the immunity tag.');
        }

        if ($series->exemptIds() !== []) {
            throw new GameRuleException('The immunity tag is already used.');
        }

        $ids = array_values(array_unique(array_map('intval', $wrestlerIds)));
        $needed = $this->immunityNeeded($save, $series);

        if (count($ids) !== $needed) {
            throw new GameRuleException('The immunity tag protects exactly '.$needed.' '.($needed === 1 ? 'superstar' : 'superstars').'.');
        }

        $eligible = $this->eligibleFrom($save, $brand);

        foreach ($ids as $id) {
            if (! $eligible->contains('id', $id)) {
                throw new GameRuleException('The immunity tag must go to your own non-champions.');
            }
        }

        $series->update(['exemption_wrestler_ids' => $ids]);

        return $this->prepare($save) ?? $series;
    }

    /**
     * @param  list<int>  $wrestlerIds  in pick order
     */
    public function setHandpick(Save $save, array $wrestlerIds): InterbrandSeries
    {
        $series = $this->openSeries($save);
        $brand = $series->winner_brand;

        if ($brand === null || ! $save->isHuman($brand)) {
            throw new GameRuleException('The other brand holds the manual draft pick.');
        }

        if (! $this->immunityDecided($save, $series)) {
            throw new GameRuleException('The immunity tag comes first.');
        }

        if ($series->handpickIds() !== []) {
            throw new GameRuleException('The manual draft pick is already made.');
        }

        $ids = array_values(array_unique(array_map('intval', $wrestlerIds)));
        $needed = $this->manualNeeded($save, $series);

        if (count($ids) !== $needed) {
            throw new GameRuleException('The manual draft pick takes exactly '.$needed.' '.($needed === 1 ? 'superstar' : 'superstars').'.');
        }

        $pool = $this->handpickPool($save, $series);

        if (collect($ids)->contains(fn (int $id): bool => ! $pool->contains('id', $id))) {
            throw new GameRuleException('The manual pick must be one of the other brand’s non-champions, and not the one it protected.');
        }

        $series->update(['handpick_wrestler_ids' => $ids]);

        return $series->refresh();
    }

    /**
     * The brand making each pick, in order: the manual pick's brand first
     * (else a seeded coin flip), then alternating, `perBrand()` picks each.
     *
     * @return list<Brand>
     */
    public function order(Save $save, InterbrandSeries $series): array
    {
        $first = $series->winner_brand
            ?? ($save->rng('redraft.order', $series->week_number)->chance(0.5) ? Brand::Raw : Brand::Smackdown);
        $second = $first->rival();
        $order = [];

        foreach (range(1, $this->perBrand()) as $_) {
            $order[] = $first;
            $order[] = $second;
        }

        return $order;
    }

    /**
     * This re-draft's picks so far.
     *
     * @return Collection<int, DraftPick>
     */
    public function picks(Save $save): Collection
    {
        return DraftPick::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('phase', DraftPhase::WrestlemaniaReset)
            ->where('season', $save->current_season)
            ->orderBy('pick_number')
            ->get();
    }

    /**
     * Make the next pick: a manual one for each of the tag holder's first
     * picks it chose, else a random non-champion from the other brand who
     * isn't protected and hasn't moved already. A brand with nobody left
     * skips the pick.
     */
    public function step(Save $save): ?DraftPick
    {
        $series = $this->openSeries($save);

        if ($this->phase($save, $series) !== 'picks') {
            return null;
        }

        $done = $this->picks($save);
        $number = $done->count() + 1;
        $brand = $this->order($save, $series)[$number - 1];
        $from = $brand->rival();
        $moved = array_values(array_filter(array_map(fn (DraftPick $p): ?int => $p->save_wrestler_id, $done->all())));
        $pool = $this->eligibleFrom($save, $from, [...$moved, ...$series->exemptIds()]);
        // The tag holder's nth pick takes its nth manual choice, while it has one.
        $nth = $done->where('brand', $brand)->count();
        $choice = $brand === $series->winner_brand ? ($series->handpickIds()[$nth] ?? null) : null;
        $chosen = $choice === null ? null : $pool->firstWhere('id', $choice);
        $manual = $chosen !== null;

        $wrestler = $manual
            ? $chosen
            : ($pool->isEmpty() ? null : $save->rng("redraft.pick.{$number}", $series->week_number)->pick($pool->all()));

        $pick = DraftPick::create([
            'save_id' => $save->id,
            'season' => $save->current_season,
            'phase' => DraftPhase::WrestlemaniaReset,
            'pick_number' => $number,
            'brand' => $brand,
            'save_wrestler_id' => $wrestler?->id,
            'source' => DraftPickSource::RivalRoster,
            'pick_type' => $manual ? DraftPickType::Manual : DraftPickType::Random,
            'skipped' => $wrestler === null,
        ]);

        if ($wrestler !== null) {
            $this->move($wrestler, $brand, $save->current_week);
        }

        return $pick->load('saveWrestler.wrestler');
    }

    /**
     * Close the re-draft: the WWE headline for WrestleMania week, then the
     * new season.
     */
    public function finish(Save $save): void
    {
        $series = $this->openSeries($save);

        if ($this->phase($save, $series) !== 'done') {
            throw new GameRuleException('The re-draft isn’t finished yet.');
        }

        // House rule (2026-11-21): one Re-draft Picks headline leads the WWE
        // card after the re-draft (it replaced a ticker line per pick and the
        // old Re-draft Results headline).
        $this->news->record($save, NewsEventType::RedraftPick, NewsTier::Headline, NewsBrand::Both, $this->headlinePayload($save, $series), [
            'wrestler_ids' => array_values(array_filter($this->picks($save)->pluck('save_wrestler_id')->all())),
        ], priority: 100, week: $series->week_number);

        $series->update(['redraft_done' => true]);
        $save->update(['stage' => SaveStage::InSeason]);
        $this->emails->welcome($save, $save->player_brand, $save->current_week);
    }

    /**
     * The whole re-draft in one go (Simulate, the demo save, tests): the
     * player's tags go to their most valuable superstars, like the rival's.
     */
    public function run(Save $save): void
    {
        $series = $this->prepare($save) ?? throw new GameRuleException('There is no re-draft in progress.');
        $rng = $save->rng('redraft.player-tags', $series->week_number);

        if ($this->phase($save, $series) === 'immunity' && $series->immunity_brand !== null) {
            $ids = $this->mostValuableMany($save, $series->immunity_brand, $this->eligibleFrom($save, $series->immunity_brand), $this->immunityCount(), $rng);
            $ids === [] ? $series->update(['immunity_brand' => null]) : $this->setImmunity($save, $ids);
            $series->refresh();
        }

        if ($this->phase($save, $series) === 'manual' && $series->winner_brand !== null) {
            $ids = $this->mostValuableMany($save, $series->winner_brand, $this->handpickPool($save, $series), $this->manualCount(), $rng);

            if ($ids !== []) {
                $this->setHandpick($save, $ids);
            }
        }

        while ($this->step($save) !== null) {
            // one pick at a time
        }

        $this->finish($save);
    }

    /**
     * @param  Collection<int, SaveWrestler>  $pool
     */
    public function mostValuable(Save $save, Brand $judge, Collection $pool, Rng $rng): ?SaveWrestler
    {
        if ($pool->isEmpty()) {
            return null;
        }

        $values = $pool->mapWithKeys(fn (SaveWrestler $w): array => [$w->id => $this->judge->value($save, $judge, [(int) $w->id])]);
        $best = $values->max();

        return $rng->pick($pool->filter(fn (SaveWrestler $w): bool => $values[$w->id] === $best)->values()->all());
    }

    /**
     * The `$count` most valuable in `$pool`, best first, one at a time
     * (ties to the RNG).
     *
     * @param  Collection<int, SaveWrestler>  $pool
     * @return list<int>
     */
    public function mostValuableMany(Save $save, Brand $judge, Collection $pool, int $count, Rng $rng): array
    {
        $ids = [];

        while (count($ids) < $count) {
            $best = $this->mostValuable($save, $judge, $pool->reject(fn (SaveWrestler $w): bool => in_array($w->id, $ids, true))->values(), $rng);

            if ($best === null) {
                break;
            }

            $ids[] = (int) $best->id;
        }

        return $ids;
    }

    /**
     * Who the manual pick can take: the other brand's non-champions, less
     * the superstars its immunity tag protects.
     *
     * @return Collection<int, SaveWrestler>
     */
    public function handpickPool(Save $save, InterbrandSeries $series): Collection
    {
        $winner = $series->winner_brand;

        return $winner === null ? collect() : $this->eligibleFrom($save, $winner->rival(), $series->exemptIds());
    }

    private function immunityDecided(Save $save, InterbrandSeries $series): bool
    {
        return $series->immunity_brand === null
            || $series->exemptIds() !== []
            || $this->eligibleFrom($save, $series->immunity_brand)->isEmpty();
    }

    private function immunityBrand(Save $save, InterbrandSeries $series): Brand
    {
        $totals = $this->crowds->totals($save, $series->season, $series->week_number);
        [$raw, $smackdown] = [$totals[Brand::Raw->value], $totals[Brand::Smackdown->value]];

        if ($raw['crowd'] !== $smackdown['crowd']) {
            return $raw['crowd'] > $smackdown['crowd'] ? Brand::Raw : Brand::Smackdown;
        }

        if ($raw['score'] !== $smackdown['score']) {
            return $raw['score'] > $smackdown['score'] ? Brand::Raw : Brand::Smackdown;
        }

        return $save->rng('redraft.immunity', $series->week_number)->chance(0.5) ? Brand::Raw : Brand::Smackdown;
    }

    private function openSeries(Save $save): InterbrandSeries
    {
        if ($save->stage !== SaveStage::Redrafting) {
            throw new GameRuleException('There is no re-draft in progress.');
        }

        return $this->prepare($save) ?? throw new GameRuleException('There is no re-draft in progress.');
    }

    private function move(SaveWrestler $wrestler, Brand $to, int $week): void
    {
        $this->mitb->voidFor($wrestler, 'redrafted', $week);
        $this->contenderships->voidFor($wrestler, ContendershipVoidReason::Redrafted, $week);
        $wrestler->update(['brand' => $to, 'joined_brand_week' => $week]);
        $wrestler->activeContract?->update(['brand' => $to]);
    }

    /**
     * The tokens of the WrestleMania week WWE headline: the picks, the tags
     * and the interbrand series, plus each brand's last WrestleMania match
     * (`headline_slots`), which leads that brand's card.
     *
     * @return array<string, mixed>
     */
    private function headlinePayload(Save $save, InterbrandSeries $series): array
    {
        $picks = $this->picks($save)->where('skipped', false);
        $list = function (Brand $brand) use ($picks): string {
            $names = array_values($picks->where('brand', $brand)->map(fn (DraftPick $p): string => $p->saveWrestler->name().($p->pick_type === DraftPickType::Manual ? ' (manual pick)' : ''))->all());

            return $names === [] ? 'nobody' : $this->join($names).', all from '.$brand->rival()->label();
        };
        $crowds = $this->crowds($save, $series);
        $immunity = $series->immunity_brand;
        $slots = MatchSlot::with(['participants.saveWrestler.wrestler', 'result', 'show'])->whereIn('id', $series->match_ids)->orderBy('position')->get()->values();
        $wins = [$series->raw_wins, $series->smackdown_wins];

        return [
            'season' => $series->season,
            'next_season' => $series->season + 1,
            'moved' => $picks->count(),
            'raw_picks' => $list(Brand::Raw),
            'smackdown_picks' => $list(Brand::Smackdown),
            'immunity_brand' => $immunity?->label() ?? 'Neither brand',
            // "433,500 fans to Raw's 416,550": the tag holder's crowd first.
            'immunity_crowd' => number_format($crowds[($immunity ?? Brand::Raw)->value] ?? 0).' fans to '.($immunity ?? Brand::Raw)->rival()->label()."'s ".number_format($crowds[($immunity ?? Brand::Raw)->rival()->value] ?? 0),
            'immune' => $this->names($series->exemptIds()),
            'series_singles' => $this->seriesLine($series, $slots->get(0)),
            'series_tag' => $this->seriesLine($series, $slots->get(1)),
            'series_mitb' => $this->seriesLine($series, $slots->get(2)),
            'manual_brand' => $series->winner_brand?->label() ?? 'Neither brand',
            'series_score' => max($wins).'–'.min($wins),
            'handpick' => $this->names($series->handpickIds()),
            'headline_slots' => $this->headlineSlots($series),
        ];
    }

    /**
     * "Rob Van Dam of Raw, who beat Chris Benoit": a series match's winners,
     * their brand and who they beat. Side 0 is Raw and side 1 Smackdown in
     * the 1v1 and 2v2; the ladder match counts for the briefcase winner's brand.
     */
    private function seriesLine(InterbrandSeries $series, ?MatchSlot $slot): string
    {
        $result = $slot?->result;

        if ($slot === null || $result === null) {
            return 'nobody, since the match never happened';
        }

        $sides = $slot->sides();
        $name = fn (int $id): string => $slot->participants->firstWhere('save_wrestler_id', $id)?->saveWrestler?->name() ?? 'someone';
        $winners = array_map($name, $sides[$result->winner_side] ?? []);
        $losers = [];

        foreach ($sides as $side => $ids) {
            if ($side !== $result->winner_side) {
                array_push($losers, ...array_map($name, $ids));
            }
        }

        $brand = count($sides) === 2
            ? ($result->winner_side === 0 ? Brand::Raw : Brand::Smackdown)
            : (MoneyInTheBank::where('save_id', $series->save_id)->where('won_week', $series->week_number)->value('brand') ?? Brand::Raw);
        $brand = $brand instanceof Brand ? $brand : Brand::from((string) $brand);

        return $this->join($winners).' of '.$brand->label().', who beat '.$this->join($losers);
    }

    /**
     * Each brand's last match on the WrestleMania card: the main event for
     * the brand that had it, else its last slot.
     *
     * @return array<string, int|null>
     */
    private function headlineSlots(InterbrandSeries $series): array
    {
        $show = MatchSlot::whereIn('id', $series->match_ids)->value('show_id');
        $slots = $show === null ? collect() : MatchSlot::where('show_id', $show)->whereNotNull('owner_brand')->whereHas('result')->orderByDesc('position')->get();

        return [
            Brand::Raw->value => $slots->firstWhere('owner_brand', Brand::Raw)?->id,
            Brand::Smackdown->value => $slots->firstWhere('owner_brand', Brand::Smackdown)?->id,
        ];
    }

    /**
     * "Edge, Kane and Carlito", in the order given.
     *
     * @param  list<int>  $ids
     */
    private function names(array $ids): string
    {
        $wrestlers = SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()->keyBy('id');

        return $this->join(array_values(array_filter(array_map(fn (int $id): ?string => $wrestlers->get($id)?->name(), $ids))));
    }

    /**
     * @param  list<string>  $names
     */
    private function join(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? 'nobody';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
