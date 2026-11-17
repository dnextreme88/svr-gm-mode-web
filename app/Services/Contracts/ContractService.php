<?php

namespace App\Services\Contracts;

use App\Enums\Brand;
use App\Enums\ContendershipVoidReason;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\LedgerCategory;
use App\Enums\MoraleComplaintType;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Exceptions\GameRuleException;
use App\Models\Contract;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Finance\LedgerService;
use App\Services\Morale\MoraleCalculator;
use App\Services\News\NewsRecorder;
use App\Services\Roster\RosterService;
use App\Services\Titles\ContendershipService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use Illuminate\Support\Str;

/**
 * Signing, renegotiation, release and the weekly contract tick (§2).
 */
class ContractService
{
    public function __construct(
        private ContractPricing $pricing,
        private LedgerService $ledger,
        private TitleService $titles,
        private MitbService $mitb,
        private MoraleCalculator $morale,
        private NewsRecorder $news,
        private RosterService $roster,
        private ContendershipService $contenderships,
    ) {}

    /**
     * Sign a drafted wrestler immediately (§1 initial draft). The roster cap
     * holds here too, so nothing can put a brand over it.
     */
    public function signDrafted(Save $save, SaveWrestler $wrestler, Brand $brand, int $week, ?ContractLength $length = null, bool $checkBudget = true): Contract
    {
        if (! $this->roster->hasRoom($save, $brand)) {
            throw new GameRuleException("The {$brand->label()} roster is full ({$this->roster->cap()}).");
        }

        $length ??= ContractLength::from((int) config('gm.contracts.draft_default_length'));
        $contract = $this->createContract($save, $wrestler, $brand, $length, $week, $week, checkBudget: $checkBudget);

        $wrestler->update(['brand' => $brand, 'joined_brand_week' => $week]);
        $this->morale->startHappy($wrestler, $week);

        return $contract;
    }

    /**
     * Sign a free agent. The wrestler joins the roster next week (§2).
     */
    public function signFreeAgent(Save $save, SaveWrestler $wrestler, Brand $brand, ContractLength $length, int $week): PendingRosterMove
    {
        if ($wrestler->save_id !== $save->id || $wrestler->isSigned() || $this->roster->isPending($wrestler)) {
            throw new GameRuleException("{$wrestler->name()} isn't available in Free Agency.");
        }

        if ($wrestler->unsignable_until_week !== null && $week < $wrestler->unsignable_until_week) {
            throw new GameRuleException("{$wrestler->name()} can't be signed until week {$wrestler->unsignable_until_week} (Loser Leaves Show lockout).");
        }

        if (! $this->roster->hasRoom($save, $brand)) {
            throw new GameRuleException("The {$brand->label()} roster is full ({$this->roster->cap()}).");
        }

        $contract = $this->createContract($save, $wrestler, $brand, $length, $week, $week + 1);

        $move = PendingRosterMove::create([
            'save_id' => $save->id,
            'save_wrestler_id' => $wrestler->id,
            'contract_id' => $contract->id,
            'brand' => $brand,
            'effective_week' => $week + 1,
        ]);

        $wrestler->loadMissing('wrestler');

        if ($wrestler->wrestler->isLegend()) {
            $this->news->record($save, NewsEventType::LegendSigned, NewsTier::Headline, NewsBrand::Both, [
                'legend' => $wrestler->name(),
                'brand' => $brand->label(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: 60, week: $week);
        } else {
            $this->news->record($save, NewsEventType::FreeAgentSigned, NewsTier::Story, $brand, [
                'brand' => $brand->label(),
                'wrestler' => $wrestler->name(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: (int) config('gm.news.free_agent_signed_priority'), week: $week);
        }

        return $move;
    }

    /**
     * Apply Free Agency signings that take effect this week.
     *
     * @return list<SaveWrestler>
     */
    public function applyPendingMoves(Save $save, int $effectiveWeek): array
    {
        $applied = [];

        $moves = PendingRosterMove::with('saveWrestler')
            ->where('save_id', $save->id)
            ->whereNull('applied_week')
            ->where('effective_week', '<=', $effectiveWeek)
            ->get();

        foreach ($moves as $move) {
            $move->saveWrestler->update(['brand' => $move->brand, 'joined_brand_week' => $effectiveWeek]);
            $this->morale->startHappy($move->saveWrestler, $effectiveWeek);
            $move->update(['applied_week' => $effectiveWeek]);
            $applied[] = $move->saveWrestler;
        }

        return $applied;
    }

    /**
     * New length, `weeks_remaining` reset, extension complaint cleared (§2, §15).
     */
    public function renegotiate(SaveWrestler $wrestler, ContractLength $length, int $week): Contract
    {
        $current = $wrestler->activeContract;

        if ($current === null || $wrestler->brand === null) {
            throw new GameRuleException("{$wrestler->name()} has no contract to renegotiate.");
        }

        // House rule (2026-11-17): the old contract's unused weeks come back,
        // then the new one is paid in full.
        $refund = $current->remainingValue();

        if ($refund > 0) {
            $this->ledger->post($wrestler->game, $wrestler->brand, LedgerCategory::ContractRefund, $refund, "Renegotiated {$wrestler->name()} ({$current->weeks_remaining} ".Str::plural('week', $current->weeks_remaining).' left on the old contract)', $week, $current);
        }

        $current->update(['status' => ContractStatus::Superseded, 'ended_week' => $week]);

        $contract = $this->createContract($wrestler->game, $wrestler, $wrestler->brand, $length, $week, $week, $current->last_ticked_week, memo: "Re-signed {$wrestler->name()} for {$length->label()}");
        $this->morale->resolve($wrestler, MoraleComplaintType::ContractExtension, $week);
        $wrestler->unsetRelation('activeContract');

        return $contract;
    }

    /**
     * Release to Free Agency. Titles vacate (`released`) and any briefcase is voided.
     *
     * @return list<string> vacated title names
     */
    public function release(SaveWrestler $wrestler, int $week, ReignEndReason $reason = ReignEndReason::Released): array
    {
        $brand = $wrestler->brand;

        if ($brand === null) {
            throw new GameRuleException("{$wrestler->name()} isn't signed.");
        }

        $vacated = $this->titles->vacateFor($wrestler, $reason, $week);
        $this->mitb->voidFor($wrestler, $reason->value, $week);
        // House rule: a #1 contender who leaves the brand loses the spot.
        $this->contenderships->voidFor($wrestler, match ($reason) {
            ReignEndReason::ContractExpired => ContendershipVoidReason::ContractExpired,
            ReignEndReason::LoserLeaves => ContendershipVoidReason::LoserLeaves,
            ReignEndReason::Defected => ContendershipVoidReason::Defected,
            default => ContendershipVoidReason::Released,
        }, $week);

        $contract = $wrestler->activeContract;

        // House rule (2026-11-17): contracts are paid up front, so releasing
        // a superstar early gives back the weeks left on the deal. Expiry
        // has nothing left; Loser Leaves refunds through its own line.
        if ($reason === ReignEndReason::Released && $contract !== null && $contract->remainingValue() > 0) {
            $this->ledger->post($wrestler->game, $brand, LedgerCategory::ContractRefund, $contract->remainingValue(), "Released {$wrestler->name()} ({$contract->weeks_remaining} ".Str::plural('week', $contract->weeks_remaining).' left)', $week, $contract);
        }

        $contract?->update([
            'status' => $reason === ReignEndReason::ContractExpired ? ContractStatus::Expired : ContractStatus::Released,
            'ended_week' => $week,
        ]);

        $wrestler->update(['brand' => null, 'joined_brand_week' => null]);
        $wrestler->unsetRelation('activeContract');

        if ($reason === ReignEndReason::Released) {
            $this->news->record($wrestler->game, NewsEventType::Released, NewsTier::Story, $brand, [
                'brand' => $brand->label(),
                'wrestler' => $wrestler->name(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: 20, week: $week);
        }

        return $vacated;
    }

    /**
     * The weekly tick (§2): every active contract loses one week, except
     * while the wrestler is buried alive (house rule). Contracts are paid in
     * full when signed (house rule, 2026-11-17), so only a contract from
     * before that (nothing paid up front) still charges a weekly salary.
     * Contracts that hit 0 expire. Guarded by `last_ticked_week`
     * so a retried resolution can't double-decrement.
     *
     * @return array<string, array{expired: list<SaveWrestler>, vacated: array<int, list<string>>}> keyed by brand
     */
    public function tick(Save $save, int $week): array
    {
        $result = [];
        $upkeep = [];

        $contracts = Contract::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('status', ContractStatus::Active)
            ->where('starts_week', '<=', $week)
            ->where(fn ($q) => $q->whereNull('last_ticked_week')->orWhere('last_ticked_week', '<', $week))
            ->orderBy('id')
            ->get();

        foreach ($contracts as $contract) {
            // House rule: a contract pauses while its wrestler is buried alive.
            $paused = $contract->saveWrestler->isBuried($week);

            $contract->update([
                'weeks_remaining' => $paused ? $contract->weeks_remaining : max(0, $contract->weeks_remaining - 1),
                'last_ticked_week' => $week,
            ]);

            if ($contract->upfront_fee > 0 || ($paused && config('gm.buried_alive.pause_upkeep'))) {
                continue;
            }

            $upkeep[$contract->brand->value] = ($upkeep[$contract->brand->value] ?? ['total' => 0, 'count' => 0]);
            $upkeep[$contract->brand->value]['total'] += $contract->weekly_cost;
            $upkeep[$contract->brand->value]['count']++;
        }

        foreach ($upkeep as $brand => $line) {
            $this->ledger->post($save, Brand::from($brand), LedgerCategory::ContractUpkeep, -$line['total'], "Salaries ({$line['count']} ".Str::plural('superstar', $line['count']).')', $week);
        }

        foreach ($contracts->where('weeks_remaining', 0) as $contract) {
            $wrestler = $contract->saveWrestler;
            $brand = $contract->brand->value;
            $result[$brand] ??= ['expired' => [], 'vacated' => []];

            if ($wrestler->brand === null) {
                $contract->update(['status' => ContractStatus::Expired, 'ended_week' => $week]);

                continue;
            }

            $wrestler->setRelation('activeContract', $contract);
            $result[$brand]['vacated'][$wrestler->id] = $this->release($wrestler, $week, ReignEndReason::ContractExpired);
            $result[$brand]['expired'][] = $wrestler;
        }

        return $result;
    }

    /**
     * House rule (2026-11-17): a contract is paid in full the moment it's
     * signed (the budget drops at once, a Contract signing line on the
     * Financials report); there are no weekly salaries. A signing needs the
     * whole value in the bank.
     */
    private function createContract(Save $save, SaveWrestler $wrestler, Brand $brand, ContractLength $length, int $week, int $startsWeek, ?int $lastTicked = null, bool $checkBudget = true, ?string $memo = null): Contract
    {
        $wrestler->loadMissing('wrestler');
        $quote = $this->pricing->quote($wrestler->wrestler, $length);

        if ($checkBudget && $this->ledger->budget($save, $brand) < $quote['total']) {
            throw new GameRuleException("{$brand->label()} can’t afford {$wrestler->name()} for {$length->label()} (\$".number_format($quote['total']).').');
        }

        $contract = Contract::create([
            'save_id' => $save->id,
            'save_wrestler_id' => $wrestler->id,
            'brand' => $brand,
            'length_weeks' => $length->value,
            'upfront_fee' => $quote['total'],
            'weekly_cost' => $quote['weekly'],
            'signed_week' => $week,
            'starts_week' => $startsWeek,
            'weeks_remaining' => $length->value,
            'last_ticked_week' => $lastTicked,
            'status' => ContractStatus::Active,
        ]);

        $this->ledger->post($save, $brand, LedgerCategory::SigningFee, -$quote['total'], $memo ?? "Signed {$wrestler->name()} for {$length->label()}", $week, $contract);

        return $contract;
    }
}
