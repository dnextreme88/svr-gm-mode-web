<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractPricing;
use App\Services\Contracts\ContractService;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
use Illuminate\Support\Collection;

/**
 * The rival GM's contract upkeep (house rule): it renews expiring contracts
 * and signs free agents so its roster doesn't empty out at season's end.
 * Signings keep `gm.ai.roster.cash_reserve` in the bank; renewals may
 * spend it rather than lose a wrestler.
 */
class AiRosterManager
{
    public function __construct(
        private ContractService $contracts,
        private ContractPricing $pricing,
        private RosterService $roster,
        private TitleService $titles,
    ) {}

    public function upkeep(Save $save, Brand $brand, int $week): void
    {
        $this->renew($save, $brand, $week);
        $this->sign($save, $brand, $week);
    }

    /**
     * Renew every contract with `renew_at_weeks_left` or fewer weeks to go,
     * champions first, then the focus class, while the money lasts.
     */
    public function renew(Save $save, Brand $brand, int $week): void
    {
        $length = ContractLength::from((int) config('gm.ai.roster.renew_length'));

        $expiring = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->activeContract !== null
                && $w->activeContract->weeks_remaining <= (int) config('gm.ai.roster.renew_at_weeks_left'))
            ->sortBy(fn (SaveWrestler $w): int => match (true) {
                $this->titles->isChampion($w) => 0,
                AiGm::isDraftFocus($brand, $w) => 1,
                default => 2,
            })
            ->values();

        // ASSUMPTION: short of money for the full length, it takes the
        // longest shorter deal it can afford rather than lose the wrestler,
        // dipping into the cash reserve (never below $0) if it has to.
        $lengths = array_values(array_filter(array_reverse(ContractLength::cases()), fn (ContractLength $l): bool => $l->value <= $length->value));

        foreach ($expiring as $wrestler) {
            foreach ($lengths as $option) {
                if ($this->affords($save, $brand, $this->pricing->quote($wrestler->wrestler, $option)['upfront'], 0)) {
                    $this->contracts->renegotiate($wrestler, $option, $week);

                    break;
                }
            }
        }
    }

    /**
     * Sign free agents up to `target_size`: first anyone who makes a title
     * fillable again, then the focus class, then anyone.
     */
    public function sign(Save $save, Brand $brand, int $week): void
    {
        $length = ContractLength::from((int) config('gm.ai.roster.renew_length'));
        $target = min((int) config('gm.ai.roster.target_size'), $this->roster->cap());
        $rng = $save->rng("ai.roster.{$brand->value}", $week);

        $agents = collect($rng->shuffle($this->roster->freeAgentsQuery($save, $week)->with('wrestler')->orderBy('id')->get()->all()));

        while ($this->roster->rosterCount($save, $brand) < $target) {
            $short = $this->shortTitles($save, $brand);
            $agents = $agents->sortBy(fn (SaveWrestler $w): int => match (true) {
                $short->contains(fn (Championship $c): bool => $c->allowsWrestler($w->wrestler)) => 0,
                AiGm::isDraftFocus($brand, $w) => 1,
                default => 2,
            })->values();

            $pick = $agents->first(fn (SaveWrestler $w): bool => $this->affords($save, $brand, $this->pricing->quote($w->wrestler, $length)['upfront']));

            if ($pick === null) {
                return;
            }

            $agents = $agents->reject(fn (SaveWrestler $w): bool => $w->id === $pick->id)->values();

            try {
                $this->contracts->signFreeAgent($save, $pick, $brand, $length, $week);
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * The brand's titles without enough eligible wrestlers to book a title
     * match (a champion and a challenger, or two teams), counting signings
     * that start next week.
     *
     * @return Collection<int, Championship>
     */
    public function shortTitles(Save $save, Brand $brand): Collection
    {
        $pending = PendingRosterMove::where('save_id', $save->id)->where('brand', $brand)->whereNull('applied_week')->pluck('save_wrestler_id');
        $people = SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereIn('id', $pending))
            ->get();

        return Championship::where('brand', $brand)->orderBy('sort_order')->get()
            ->filter(fn (Championship $c): bool => $people->filter(fn (SaveWrestler $w): bool => $c->allowsWrestler($w->wrestler))->count() < ($c->is_tag ? 4 : 2))
            ->values()
            ->toBase();
    }

    /**
     * Whether the brand should run money promos first: at or below the
     * low-cash mark, or short of what it needs to renew the contracts
     * ending within `save_ahead_weeks` and keep its reserve.
     */
    public function needsCash(Save $save, Brand $brand): bool
    {
        $budget = $save->brand($brand)->budget;

        if ($budget <= (int) config('gm.emails.low_cash_threshold')) {
            return true;
        }

        $length = ContractLength::from((int) config('gm.ai.roster.renew_length'));
        $window = (int) config('gm.ai.roster.renew_at_weeks_left') + (int) config('gm.ai.roster.save_ahead_weeks');

        $due = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->activeContract !== null && $w->activeContract->weeks_remaining <= $window)
            ->sum(fn (SaveWrestler $w): int => $this->pricing->quote($w->wrestler, $length)['upfront']);

        return $budget < $due + (int) config('gm.ai.roster.cash_reserve');
    }

    /**
     * Whether the brand can pay `$cost` and keep `$reserve` (the cash
     * reserve by default) in the bank.
     */
    private function affords(Save $save, Brand $brand, int $cost, ?int $reserve = null): bool
    {
        return $save->brand($brand)->budget - $cost >= ($reserve ?? (int) config('gm.ai.roster.cash_reserve'));
    }
}
