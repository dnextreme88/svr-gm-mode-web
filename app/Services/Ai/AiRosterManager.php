<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\MoraleComplaintType;
use App\Enums\RosterType;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MoraleComplaint;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractPricing;
use App\Services\Contracts\ContractService;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
use Illuminate\Support\Collection;

/**
 * The rival GM's contract upkeep (house rule): it judges expiring contracts
 * (`AiContractJudge`) and signs free agents so its roster doesn't empty out.
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
        private AiContractJudge $judge,
    ) {}

    public function upkeep(Save $save, Brand $brand, int $week): void
    {
        $this->renew($save, $brand, $week);
        $this->answerContractDemands($save, $brand, $week);
        $this->sign($save, $brand, $week);
    }

    /**
     * Morale (house rule, 2026-11-13): a superstar demanding a new contract
     * (a long win streak) gets one, rolled like its other morale fixes
     * (Content and Unsatisfied 75%, Upset 95%), when it can afford the
     * renewal and keep its cash reserve.
     */
    public function answerContractDemands(Save $save, Brand $brand, int $week): void
    {
        $length = ContractLength::from((int) config('gm.ai.roster.renew_length'));
        $rng = $save->rng("ai.morale.contract.{$brand->value}", $week);

        $demanding = SaveWrestler::with(['wrestler', 'activeContract', 'activeComplaints'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->activeContract !== null
                && $w->activeComplaints->contains(fn (MoraleComplaint $c): bool => $c->type === MoraleComplaintType::ContractExtension));

        foreach ($demanding as $wrestler) {
            $level = $wrestler->activeComplaints->first(fn (MoraleComplaint $c): bool => $c->type === MoraleComplaintType::ContractExtension)?->level;

            if ($level === null
                || ! $rng->chance((float) config('gm.ai.morale.fix_chance.'.$level->value))
                || ! $this->affords($save, $brand, $this->pricing->quote($wrestler->wrestler, $length)['total'])) {
                continue;
            }

            $this->contracts->renegotiate($wrestler, $length, $week);
        }
    }

    /**
     * Judge every contract on its final week (house rule, 2026-11-17,
     * `AiContractJudge`): re-sign the superstars worth keeping for 6 or 3
     * months, champions first, then the focus class, while the money lasts,
     * and let the rest go to Free Agency.
     */
    public function renew(Save $save, Brand $brand, int $week): void
    {
        $expiring = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->activeContract !== null
                && $w->activeContract->weeks_remaining <= (int) config('gm.ai.roster.renewal.decide_at_weeks_left'))
            ->sortBy(fn (SaveWrestler $w): int => match (true) {
                $this->titles->isChampion($w) => 0,
                AiGm::isDraftFocus($brand, $w) => 1,
                default => 2,
            })
            ->values();

        foreach ($expiring as $wrestler) {
            $length = $this->judge->decide($save, $wrestler, $week);

            if ($length === null) {
                continue;
            }

            // ASSUMPTION: short of money for that length, it takes the
            // longest shorter deal it can afford rather than lose the
            // wrestler, dipping into the cash reserve (never below $0).
            $lengths = array_values(array_filter(array_reverse(ContractLength::cases()), fn (ContractLength $l): bool => $l->value <= $length->value));

            foreach ($lengths as $option) {
                if ($this->affords($save, $brand, $this->pricing->quote($wrestler->wrestler, $option)['total'], 0)) {
                    $this->contracts->renegotiate($wrestler, $option, $week);

                    break;
                }
            }
        }
    }

    /**
     * Sign free agents up to `target_size`: first anyone who makes a title
     * fillable again, then the focus class, then anyone. Never past
     * `max_divas` Divas for its brand (house rule, 2026-11-17: 9 on Raw, 6
     * on Smackdown).
     */
    public function sign(Save $save, Brand $brand, int $week): void
    {
        $length = ContractLength::from((int) config('gm.ai.roster.renew_length'));
        $target = min((int) config('gm.ai.roster.target_size'), $this->roster->cap());
        $rng = $save->rng("ai.roster.{$brand->value}", $week);

        $agents = collect($rng->shuffle($this->roster->freeAgentsQuery($save, $week)->with('wrestler')->orderBy('id')->get()->all()));
        // House rule (2026-11-17): short of money for the full length, it
        // signs for the longest it can afford and still keep its reserve.
        $lengths = array_values(array_filter(array_reverse(ContractLength::cases()), fn (ContractLength $l): bool => $l->value <= $length->value));
        $lengthFor = function (SaveWrestler $w) use ($save, $brand, $lengths): ?ContractLength {
            foreach ($lengths as $option) {
                if ($this->affords($save, $brand, $this->pricing->quote($w->wrestler, $option)['total'])) {
                    return $option;
                }
            }

            return null;
        };
        $affordable = fn (SaveWrestler $w): bool => $lengthFor($w) !== null;

        while ($this->roster->rosterCount($save, $brand) < $target) {
            if ($this->divaCount($save, $brand) >= (int) config("gm.ai.roster.max_divas.{$brand->value}")) {
                $agents = $agents->reject(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva)->values();
            }

            $short = $this->shortTitles($save, $brand);
            $agents = $agents->sortBy(fn (SaveWrestler $w): int => match (true) {
                $short->contains(fn (Championship $c): bool => $c->allowsWrestler($w->wrestler)) => 0,
                AiGm::isDraftFocus($brand, $w) => 1,
                default => 2,
            })->values();

            // House rule (2026-11-13): while it has fewer than `max_legends`,
            // each signing rolls `legend_chance` to go for a Legend it can
            // afford; otherwise (or with none affordable) anyone else.
            $legend = $this->legendCount($save, $brand) < (int) config('gm.ai.roster.max_legends')
                && $rng->chance((float) config('gm.ai.roster.legend_chance'))
                ? $agents->first(fn (SaveWrestler $w): bool => $w->wrestler->isLegend() && $affordable($w))
                : null;
            $pick = $legend ?? $agents->first(fn (SaveWrestler $w): bool => ! $w->wrestler->isLegend() && $affordable($w));

            if ($pick === null) {
                return;
            }

            $agents = $agents->reject(fn (SaveWrestler $w): bool => $w->id === $pick->id)->values();

            try {
                $this->contracts->signFreeAgent($save, $pick, $brand, $lengthFor($pick) ?? $length, $week);
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * Divas on the brand's roster, counting signings that start next week.
     */
    public function divaCount(Save $save, Brand $brand): int
    {
        $pending = PendingRosterMove::where('save_id', $save->id)->where('brand', $brand)->whereNull('applied_week')->pluck('save_wrestler_id');

        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereIn('id', $pending))
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva)
            ->count();
    }

    /**
     * Legends on the brand's roster, counting signings that start next week.
     */
    public function legendCount(Save $save, Brand $brand): int
    {
        $pending = PendingRosterMove::where('save_id', $save->id)->where('brand', $brand)->whereNull('applied_week')->pluck('save_wrestler_id');

        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereIn('id', $pending))
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->isLegend())
            ->count();
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

        // Re-signs are 6 months at most (house rule, 2026-11-17).
        $length = ContractLength::from((int) max(config('gm.ai.roster.renewal.lengths')));
        $window = (int) config('gm.ai.roster.renew_at_weeks_left') + (int) config('gm.ai.roster.save_ahead_weeks');

        $due = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->activeContract !== null && $w->activeContract->weeks_remaining <= $window)
            ->sum(fn (SaveWrestler $w): int => $this->pricing->quote($w->wrestler, $length)['total']);

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
