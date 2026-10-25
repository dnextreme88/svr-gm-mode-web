<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * How the rival GM answers a trade offer (house rule, `gm.ai.trades`).
 * ASSUMPTION: there's no Popularity yet, so each wrestler is worth 1, plus
 * extra for a Legend, a former champion and the rival's focus class. A fair
 * offer that keeps every title fillable is usually accepted; anything else
 * rarely is.
 */
class AiTradeJudge
{
    /**
     * @param  list<int>  $outIds  what the proposer gives (the rival gets)
     * @param  list<int>  $inIds  what the proposer asks for (the rival gives)
     */
    public function accepts(Save $save, Brand $proposer, array $outIds, array $inIds, Rng $rng): bool
    {
        $rival = $proposer === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $gets = $this->value($save, $rival, $outIds);
        $gives = $this->value($save, $rival, $inIds);

        $fair = $gets >= (float) config('gm.ai.trades.fair_ratio') * $gives
            && ! $this->breaksATitle($save, $rival, $outIds, $inIds);

        return $rng->chance((float) config($fair ? 'gm.ai.trades.fair_accept' : 'gm.ai.trades.unfair_accept'));
    }

    /**
     * @param  list<int>  $ids
     */
    public function value(Save $save, Brand $rival, array $ids): float
    {
        $formerChampions = TitleReign::where('save_id', $save->id)->get()
            ->flatMap(fn (TitleReign $r): array => $r->holderIds())
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        return SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()
            ->sum(fn (SaveWrestler $w): float => 1
                + ($w->wrestler->isLegend() ? (float) config('gm.ai.trades.legend_value') : 0)
                + (in_array($w->id, $formerChampions, true) ? (float) config('gm.ai.trades.champion_history_value') : 0)
                + (AiGm::isDraftFocus($rival, $w) ? (float) config('gm.ai.trades.focus_value') : 0));
    }

    /**
     * Whether the trade leaves the rival unable to book a title match it
     * could book before (a champion and a challenger, or two teams).
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     */
    private function breaksATitle(Save $save, Brand $rival, array $outIds, array $inIds): bool
    {
        $before = SaveWrestler::with('wrestler')->where('save_id', $save->id)->where('brand', $rival)->get();
        $arriving = SaveWrestler::with('wrestler')->whereIn('id', $outIds)->get();
        $after = $before->reject(fn (SaveWrestler $w): bool => in_array($w->id, $inIds, true))->concat($arriving);

        return Championship::where('brand', $rival)->get()
            ->contains(fn (Championship $c): bool => $this->fillable($c, $before->toBase()) && ! $this->fillable($c, $after->toBase()));
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people
     */
    private function fillable(Championship $championship, Collection $people): bool
    {
        return $people->filter(fn (SaveWrestler $w): bool => $championship->allowsWrestler($w->wrestler))->count() >= ($championship->is_tag ? 4 : 2);
    }
}
