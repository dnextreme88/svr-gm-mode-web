<?php

namespace App\Services\Contracts;

use App\Enums\Brand;
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
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;

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
    ) {}

    /**
     * Sign a drafted wrestler immediately (§1 initial draft).
     */
    public function signDrafted(Save $save, SaveWrestler $wrestler, Brand $brand, int $week, ?ContractLength $length = null): Contract
    {
        $length ??= ContractLength::from((int) config('gm.contracts.draft_default_length'));
        $contract = $this->createContract($save, $wrestler, $brand, $length, $week, $week);

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
            $this->news->record($save, NewsEventType::FreeAgentSigned, NewsTier::Ticker, $brand, [
                'brand' => $brand->label(),
                'wrestler' => $wrestler->name(),
            ], ['wrestler_ids' => [$wrestler->id]], week: $week);
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

        $current->update(['status' => ContractStatus::Superseded, 'ended_week' => $week]);

        $contract = $this->createContract($wrestler->game, $wrestler, $wrestler->brand, $length, $week, $week, $current->last_ticked_week);
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

        $wrestler->activeContract?->update([
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
     * The weekly tick (§2): every active contract loses one week and its brand
     * pays upkeep, except while the wrestler is buried alive (house rule).
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

            if ($paused && config('gm.buried_alive.pause_upkeep')) {
                continue;
            }

            $upkeep[$contract->brand->value] = ($upkeep[$contract->brand->value] ?? ['total' => 0, 'count' => 0]);
            $upkeep[$contract->brand->value]['total'] += $contract->weekly_cost;
            $upkeep[$contract->brand->value]['count']++;
        }

        foreach ($upkeep as $brand => $line) {
            $this->ledger->post($save, Brand::from($brand), LedgerCategory::ContractUpkeep, -$line['total'], "Contract upkeep ({$line['count']} wrestlers)", $week);
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

    private function createContract(Save $save, SaveWrestler $wrestler, Brand $brand, ContractLength $length, int $week, int $startsWeek, ?int $lastTicked = null): Contract
    {
        $wrestler->loadMissing('wrestler');
        $quote = $this->pricing->quote($wrestler->wrestler, $length);

        $contract = Contract::create([
            'save_id' => $save->id,
            'save_wrestler_id' => $wrestler->id,
            'brand' => $brand,
            'length_weeks' => $length->value,
            'upfront_fee' => $quote['upfront'],
            'weekly_cost' => $quote['weekly'],
            'signed_week' => $week,
            'starts_week' => $startsWeek,
            'weeks_remaining' => $length->value,
            'last_ticked_week' => $lastTicked,
            'status' => ContractStatus::Active,
        ]);

        $this->ledger->post($save, $brand, LedgerCategory::SigningFee, -$quote['upfront'], "Signed {$wrestler->name()} ({$length->label()})", $week, $contract);

        return $contract;
    }
}
