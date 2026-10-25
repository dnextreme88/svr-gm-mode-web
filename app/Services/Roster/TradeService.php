<?php

namespace App\Services\Roster;

use App\Enums\Brand;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\TradeStatus;
use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Services\Ai\AiTradeJudge;
use App\Services\Calendar\CalendarService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use Illuminate\Support\Collection;

/**
 * 5-for-5 trades between brands (§8).
 */
class TradeService
{
    public function __construct(
        private RosterService $roster,
        private TitleService $titles,
        private InjuryStatus $injuries,
        private MitbService $mitb,
        private CalendarService $calendar,
        private EmailService $emails,
        private NewsRecorder $news,
        private AiTradeJudge $judge,
    ) {}

    /**
     * Why a wrestler can't be traded, or null if they can.
     */
    public function untradeableReason(SaveWrestler $wrestler, int $week): ?string
    {
        if ($this->titles->isChampion($wrestler)) {
            return 'Champion';
        }

        if ($this->injuries->isSeriouslyInjured($wrestler, $week)) {
            return 'Seriously injured';
        }

        return null;
    }

    /**
     * Resulting roster sizes if the trade goes through (shown before sending).
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     * @return array<string, int>
     */
    public function resultingSizes(Save $save, Brand $proposer, array $outIds, array $inIds): array
    {
        $rival = $proposer === Brand::Raw ? Brand::Smackdown : Brand::Raw;

        return [
            $proposer->value => $this->roster->rosterCount($save, $proposer) - count($outIds) + count($inIds),
            $rival->value => $this->roster->rosterCount($save, $rival) - count($inIds) + count($outIds),
        ];
    }

    /**
     * Validate an offer of up to 5 of the proposer's wrestlers for up to 5 of
     * the rival's. Sides don't need to be equal.
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     */
    public function validate(Save $save, Brand $proposer, array $outIds, array $inIds): void
    {
        $max = (int) config('gm.trades.max_per_side');
        $rival = $proposer === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $week = $save->current_week;

        if ($outIds === [] && $inIds === []) {
            throw new GameRuleException('A trade needs at least one wrestler.');
        }

        if (count($outIds) > $max || count($inIds) > $max) {
            throw new GameRuleException("Trades are limited to {$max} wrestlers per side.");
        }

        foreach ([[$outIds, $proposer], [$inIds, $rival]] as [$ids, $brand]) {
            $wrestlers = SaveWrestler::with('wrestler')->where('save_id', $save->id)->whereIn('id', $ids)->get();

            if ($wrestlers->count() !== count(array_unique($ids)) || $wrestlers->contains(fn (SaveWrestler $w): bool => $w->brand !== $brand)) {
                throw new GameRuleException('Every wrestler must be on the right roster.');
            }

            foreach ($wrestlers as $wrestler) {
                $reason = $this->untradeableReason($wrestler, $week);

                if ($reason !== null) {
                    throw new GameRuleException("{$wrestler->name()} can't be traded ({$reason}).");
                }
            }
        }

        foreach ($this->resultingSizes($save, $proposer, $outIds, $inIds) as $brand => $size) {
            if ($size > $this->roster->cap()) {
                throw new GameRuleException('The trade would put '.Brand::from($brand)->label()." over {$this->roster->cap()} wrestlers.");
            }
        }
    }

    /**
     * Propose a trade. The AI answers right away (`AiTradeJudge`).
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     */
    public function propose(Save $save, Brand $proposer, array $outIds, array $inIds): Trade
    {
        $this->validate($save, $proposer, $outIds, $inIds);
        $week = $save->current_week;

        $trade = Trade::create([
            'save_id' => $save->id,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'proposer_brand' => $proposer,
            'status' => TradeStatus::Proposed,
        ]);

        foreach ($outIds as $id) {
            TradeItem::create(['trade_id' => $trade->id, 'save_wrestler_id' => $id, 'direction' => 'out']);
        }

        foreach ($inIds as $id) {
            TradeItem::create(['trade_id' => $trade->id, 'save_wrestler_id' => $id, 'direction' => 'in']);
        }

        $tradeCount = Trade::where('save_id', $save->id)->count();
        $accepts = $this->judge->accepts($save, $proposer, $outIds, $inIds, $save->rng("ai.trade.{$tradeCount}", $week));

        $trade->load('items.saveWrestler.wrestler');

        if (! $accepts) {
            $trade->update(['status' => TradeStatus::Rejected]);
            $this->emails->tradeAnswer($save, $proposer, false, $this->namesFor($trade, 'out'), $this->namesFor($trade, 'in'), $week);

            return $trade;
        }

        $this->execute($trade);

        return $trade;
    }

    public function execute(Trade $trade): void
    {
        $save = $trade->game;
        $week = $trade->week_number;
        $proposer = $trade->proposer_brand;
        $rival = $proposer === Brand::Raw ? Brand::Smackdown : Brand::Raw;

        foreach ($trade->items as $item) {
            $wrestler = $item->saveWrestler;
            $to = $item->direction === 'out' ? $rival : $proposer;

            // A traded Money in the Bank holder's briefcase is voided (§8, §10).
            $this->mitb->voidFor($wrestler, 'traded', $week);
            $wrestler->update(['brand' => $to, 'joined_brand_week' => $week]);
            $wrestler->activeContract?->update(['brand' => $to]);
        }

        $trade->update(['status' => TradeStatus::Accepted]);

        $names = fn (string $direction): string => $trade->items->where('direction', $direction)->map(fn (TradeItem $i): string => $i->saveWrestler->name())->implode(', ') ?: 'nothing';

        $this->news->record($save, NewsEventType::TradeCompleted, NewsTier::Headline, NewsBrand::Both, [
            'wrestlers_out' => $names('out'),
            'brand_b' => $rival->label(),
            'wrestlers_in' => $names('in'),
        ], ['wrestler_ids' => $trade->items->pluck('save_wrestler_id')->all()], priority: 85, week: $week);

        // The rival GM answers the offer.
        $this->emails->tradeAnswer($save, $proposer, true, $this->namesFor($trade, 'out'), $this->namesFor($trade, 'in'), $week);
    }

    /**
     * @return list<string>
     */
    private function namesFor(Trade $trade, string $direction): array
    {
        return array_values($trade->items->where('direction', $direction)->map(fn (TradeItem $i): string => $i->saveWrestler->name())->all());
    }

    /**
     * @return Collection<int, Trade>
     */
    public function history(Save $save): Collection
    {
        return Trade::with('items.saveWrestler.wrestler')->where('save_id', $save->id)->orderByDesc('id')->limit(20)->get();
    }
}
