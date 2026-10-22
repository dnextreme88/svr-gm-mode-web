<?php

namespace App\Services\Ppv;

use App\Enums\Brand;
use App\Enums\DraftPhase;
use App\Enums\DraftPickSource;
use App\Enums\DraftPickType;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\DraftPick;
use App\Models\InterbrandSeries;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Roster\RosterService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * The post-WrestleMania re-draft (§13, §23): up to 10 random picks per brand
 * from the rival roster's non-champions. The series winner swaps one random
 * pick for a hand-pick and protects one of its own wrestlers.
 */
class ReDraftService
{
    public function __construct(
        private RosterService $roster,
        private TitleService $titles,
        private MitbService $mitb,
        private NewsRecorder $news,
        private EmailService $emails,
    ) {}

    public function series(Save $save): ?InterbrandSeries
    {
        return InterbrandSeries::where('save_id', $save->id)->where('redraft_done', false)->latest('id')->first();
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
     * The winning GM's tags: one of its own non-champions to protect, and one
     * rival non-champion to hand-pick.
     */
    public function setTags(Save $save, ?int $exemptionId, ?int $handpickId): InterbrandSeries
    {
        $series = $this->series($save) ?? throw new GameRuleException('There is no re-draft to set up.');
        $winner = $series->winner_brand ?? throw new GameRuleException('Nobody won the brand battle, so there are no tags.');
        $rival = $winner === Brand::Raw ? Brand::Smackdown : Brand::Raw;

        if ($exemptionId !== null && ! $this->eligibleFrom($save, $winner)->contains('id', $exemptionId)) {
            throw new GameRuleException('The exemption must be one of your own non-champions.');
        }

        if ($handpickId !== null && ! $this->eligibleFrom($save, $rival)->contains('id', $handpickId)) {
            throw new GameRuleException('The hand-pick must be one of the rival’s non-champions.');
        }

        $series->update(['exemption_wrestler_id' => $exemptionId, 'handpick_wrestler_id' => $handpickId]);

        return $series;
    }

    /**
     * Run every pick. Order is randomized, then alternates. A brand at 30
     * skips its remaining picks. Drafted wrestlers keep their contract and
     * morale; a drafted briefcase holder loses the briefcase.
     */
    public function run(Save $save): void
    {
        if ($save->stage !== SaveStage::Redrafting) {
            throw new GameRuleException('There is no re-draft in progress.');
        }

        $series = $this->series($save);
        $week = $save->current_week;
        $rng = $save->rng('redraft', $week);
        $winner = $series?->winner_brand;

        if ($winner !== null && ! $save->isHuman($winner)) {
            $this->aiTags($save, $series, $winner, $rng);
            $series->refresh();
        }

        $max = (int) config('gm.ppv.redraft_max_picks');
        $order = $rng->shuffle(Brand::cases());
        $picks = ['raw' => 0, 'smackdown' => 0];
        $moved = $series?->exemption_wrestler_id !== null ? [$series->exemption_wrestler_id] : [];
        $number = 0;

        for ($round = 0; $round < $max; $round++) {
            foreach ($order as $brand) {
                $rival = $brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
                $number++;
                $manual = $round === 0 && $brand === $winner && $series->handpick_wrestler_id !== null;

                if (! $this->roster->hasRoom($save, $brand)) {
                    $this->record($save, $number, $brand, null, $manual ? DraftPickType::Manual : DraftPickType::Random, true);

                    continue;
                }

                $pool = $this->eligibleFrom($save, $rival, $moved);
                $wrestler = $manual
                    ? $pool->firstWhere('id', $series->handpick_wrestler_id)
                    : ($pool->isEmpty() ? null : $rng->pick($pool->all()));

                if ($wrestler === null) {
                    $this->record($save, $number, $brand, null, DraftPickType::Random, true);

                    continue;
                }

                $this->move($wrestler, $brand, $week);
                $moved[] = $wrestler->id;
                $picks[$brand->value]++;
                $this->record($save, $number, $brand, $wrestler, $manual ? DraftPickType::Manual : DraftPickType::Random, false);

                $this->news->record($save, NewsEventType::RedraftPick, NewsTier::Story, $brand, [
                    'brand' => $brand->label(),
                    'wrestler' => $wrestler->name(),
                    'source' => $rival->label(),
                ], ['wrestler_ids' => [$wrestler->id]], priority: 20, week: $week);
            }
        }

        $series?->update(['redraft_done' => true]);
        $save->update(['stage' => SaveStage::InSeason]);
        $this->emails->welcome($save, $save->player_brand, $save->current_week);
    }

    private function move(SaveWrestler $wrestler, Brand $to, int $week): void
    {
        $this->mitb->voidFor($wrestler, 'redrafted', $week);
        $wrestler->update(['brand' => $to, 'joined_brand_week' => $week]);
        $wrestler->activeContract?->update(['brand' => $to]);
    }

    private function aiTags(Save $save, InterbrandSeries $series, Brand $winner, Rng $rng): void
    {
        $rival = $winner === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $own = $this->eligibleFrom($save, $winner);
        $theirs = $this->eligibleFrom($save, $rival);

        $series->update([
            'exemption_wrestler_id' => $own->isEmpty() ? null : $rng->pick($own->all())->id,
            'handpick_wrestler_id' => $theirs->isEmpty() ? null : $rng->pick($theirs->all())->id,
        ]);
    }

    private function record(Save $save, int $number, Brand $brand, ?SaveWrestler $wrestler, DraftPickType $type, bool $skipped): void
    {
        DraftPick::create([
            'save_id' => $save->id,
            'season' => $save->current_season,
            'phase' => DraftPhase::WrestlemaniaReset,
            'pick_number' => $number,
            'brand' => $brand,
            'save_wrestler_id' => $wrestler?->id,
            'source' => DraftPickSource::RivalRoster,
            'pick_type' => $type,
            'skipped' => $skipped,
        ]);
    }
}
