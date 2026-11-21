<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\TradeStatus;
use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Services\Calendar\CalendarService;
use App\Services\Mail\EmailService;
use App\Services\Roster\RosterService;
use App\Services\Roster\TradeService;
use App\Support\Rng\Rng;

/**
 * Rival GM trade offers (house rule, 2026-11-21; `gm.ai.trades.offers`).
 *
 * Every few season weeks the rival offers the player a trade by email. It
 * asks for 1 to 5 of the player's tradeable superstars, how many drawn at
 * random, from those it values most (by its own trade points,
 * `AiTradeJudge::breakdown`), and offers up to 5 of its own least valuable
 * ones, sized so it comes out 1 or 2 points ahead (the 90% and 100%
 * bands it uses to judge the player's offers), picked at random per offer.
 * Both rosters stay within the cap and the rival can still book every title.
 * The player accepts or declines from the email; an offer left unanswered
 * expires.
 */
class AiTradeOffers
{
    public function __construct(
        private AiTradeJudge $judge,
        private TradeService $trades,
        private RosterService $roster,
        private EmailService $emails,
        private CalendarService $calendar,
    ) {}

    /**
     * The week's rival offer, if one is due: expire old offers, then make one.
     */
    public function handleWeek(Save $save, int $week): ?Trade
    {
        $this->expire($save, $week);

        if (! $this->due($save, $week)) {
            return null;
        }

        return $this->make($save, $week);
    }

    public function due(Save $save, int $week): bool
    {
        $every = (int) config('gm.ai.trades.offers.every_weeks');
        $seasonWeek = $this->calendar->seasonWeek($week);

        if ($every < 1 || $seasonWeek % $every !== 0 || $this->calendar->isWrestleManiaWeek($week)) {
            return false;
        }

        // Once per week (week resolution is idempotent), and never while one is open.
        return ! Trade::where('save_id', $save->id)
            ->where('proposer_brand', $save->rivalBrand())
            ->where(fn ($q) => $q->where('week_number', $week)->orWhere('status', TradeStatus::Proposed))
            ->exists();
    }

    /**
     * Unanswered offers past their last week expire.
     */
    public function expire(Save $save, int $week): void
    {
        Trade::where('save_id', $save->id)
            ->where('proposer_brand', $save->rivalBrand())
            ->where('status', TradeStatus::Proposed)
            ->whereNotNull('expires_week')
            ->where('expires_week', '<', $week)
            ->update(['status' => TradeStatus::Expired]);
    }

    /**
     * Build and send an offer, or null when no offer fits.
     */
    public function make(Save $save, int $week): ?Trade
    {
        $rival = $save->rivalBrand();
        $rng = $save->rng('ai.trade.offer', $week);
        $offer = $this->build($save, $rival, $rng);

        if ($offer === null) {
            return null;
        }

        [$gives, $gets] = $offer;
        $trade = Trade::create([
            'save_id' => $save->id,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'proposer_brand' => $rival,
            'status' => TradeStatus::Proposed,
            'expires_week' => $week + (int) config('gm.ai.trades.offers.answer_weeks'),
        ]);

        // Out leaves the proposer (the rival), in joins it.
        foreach ($gives as $id) {
            TradeItem::create(['trade_id' => $trade->id, 'save_wrestler_id' => $id, 'direction' => 'out']);
        }

        foreach ($gets as $id) {
            TradeItem::create(['trade_id' => $trade->id, 'save_wrestler_id' => $id, 'direction' => 'in']);
        }

        $trade->load('items.saveWrestler.wrestler');
        $this->emails->rivalTradeOffer($save, $trade, $week);

        return $trade;
    }

    /**
     * The player accepts: the trade goes through now, if it still can.
     */
    public function accept(Save $save, Trade $trade): void
    {
        $this->assertOpen($save, $trade);
        $week = $save->current_week;
        [$gives, $gets] = $this->sides($trade);

        try {
            $this->trades->validate($save, $trade->proposer_brand, $gives, $gets);
        } catch (GameRuleException $e) {
            $trade->update(['status' => TradeStatus::Invalid, 'note' => $e->getMessage()]);

            throw new GameRuleException("This offer can't go through any more: {$e->getMessage()}");
        }

        $trade->update(['week_number' => $week]);
        $this->trades->execute($trade->load('items.saveWrestler.wrestler'), answer: false);
    }

    public function decline(Save $save, Trade $trade): void
    {
        $this->assertOpen($save, $trade);
        $trade->update(['status' => TradeStatus::Rejected]);
    }

    /**
     * Whether the player can still answer this offer.
     */
    public function isOpen(Save $save, Trade $trade): bool
    {
        return $trade->save_id === $save->id
            && $trade->proposer_brand === $save->rivalBrand()
            && $trade->status === TradeStatus::Proposed
            && ($trade->expires_week === null || $trade->expires_week >= $save->current_week);
    }

    private function assertOpen(Save $save, Trade $trade): void
    {
        if (! $this->isOpen($save, $trade)) {
            throw new GameRuleException('This trade offer is no longer open.');
        }
    }

    /**
     * @return array{0: list<int>, 1: list<int>} what the rival gives, what it gets
     */
    private function sides(Trade $trade): array
    {
        $ids = fn (string $direction): array => array_values(array_map('intval', $trade->items->where('direction', $direction)->pluck('save_wrestler_id')->all()));

        return [$ids('out'), $ids('in')];
    }

    /**
     * The offer: who the rival gives and who it asks for.
     *
     * @return array{0: list<int>, 1: list<int>}|null
     */
    private function build(Save $save, Brand $rival, Rng $rng): ?array
    {
        $config = (array) config('gm.ai.trades.offers');
        $week = $save->current_week;
        $tradeable = fn (Brand $brand) => $this->roster->roster($save, $brand)
            ->filter(fn (SaveWrestler $w): bool => $this->trades->untradeableReason($w, $week) === null)
            ->values();

        // What each superstar is worth to the rival, by its own trade points.
        $worth = fn (SaveWrestler $w): int => $this->judge->value($save, $rival, [$w->id]);

        $targets = $rng->shuffle($tradeable($save->player_brand)->all());
        usort($targets, fn (SaveWrestler $a, SaveWrestler $b): int => $worth($b) <=> $worth($a));
        $targets = array_map(fn (SaveWrestler $w): int => (int) $w->id, array_slice($targets, 0, (int) $config['targets']));

        $pool = $rng->shuffle($tradeable($rival)->all());
        usort($pool, fn (SaveWrestler $a, SaveWrestler $b): int => $worth($a) <=> $worth($b));
        $pool = array_slice($pool, 0, (int) $config['give_pool']);
        $values = [];

        foreach ($pool as $w) {
            $values[$w->id] = $worth($w);
        }

        $aheads = $this->drawOrder($rng, array_values(array_map('intval', (array) $config['ahead'])));
        // House rule (2026-11-21): it asks for 1 to 5 of the player's
        // superstars, how many drawn at random; when no offer fits that
        // many, it tries the other counts.
        [$minAsk, $maxAsk] = array_map('intval', (array) $config['ask']);
        $counts = $this->drawOrder($rng, range($minAsk, min($maxAsk, count($targets))));

        foreach ($counts as $count) {
            $tries = 0;

            // The most valuable sets first: the targets are sorted by worth.
            foreach ($this->combinations($targets, $count) as $gets) {
                if (++$tries > (int) $config['ask_tries']) {
                    break;
                }

                $value = $this->judge->value($save, $rival, $gets);

                foreach ($aheads as $ahead) {
                    $need = $value - $ahead;

                    foreach ($this->sets(array_keys($values), (int) $config['max_give']) as $gives) {
                        if (array_sum(array_map(fn (int $id): int => $values[$id], $gives)) !== $need) {
                            continue;
                        }

                        // The set's own worth, counted together (whole factions are worth more).
                        if ($this->judge->value($save, $rival, $gives) !== $need) {
                            continue;
                        }

                        if ($this->fits($save, $rival, $gives, $gets)) {
                            return [$gives, $gets];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * The options in a random order, the drawn one first.
     *
     * @param  list<int>  $options
     * @return list<int>
     */
    private function drawOrder(Rng $rng, array $options): array
    {
        if ($options === []) {
            return [];
        }

        $first = (int) $rng->pick($options);

        return [$first, ...array_values(array_filter($rng->shuffle($options), fn (int $o): bool => $o !== $first))];
    }

    /**
     * @param  list<int>  $gives
     * @param  list<int>  $gets
     */
    private function fits(Save $save, Brand $rival, array $gives, array $gets): bool
    {
        try {
            $this->trades->validate($save, $rival, $gives, $gets);
        } catch (GameRuleException) {
            return false;
        }

        return ! $this->judge->breaksATitle($save, $rival, $gets, $gives);
    }

    /**
     * Every set of 1 to $max ids, smallest sets first.
     *
     * @param  list<int>  $ids
     * @return iterable<list<int>>
     */
    private function sets(array $ids, int $max): iterable
    {
        for ($size = 1; $size <= min($max, count($ids)); $size++) {
            yield from $this->combinations($ids, $size);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return iterable<list<int>>
     */
    private function combinations(array $ids, int $size, int $start = 0): iterable
    {
        if ($size === 0) {
            yield [];

            return;
        }

        for ($i = $start; $i <= count($ids) - $size; $i++) {
            foreach ($this->combinations($ids, $size - 1, $i + 1) as $rest) {
                yield [$ids[$i], ...$rest];
            }
        }
    }
}
