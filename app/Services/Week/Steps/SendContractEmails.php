<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\Contract;
use App\Models\SaveWrestler;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Contract Expired notice for anyone who hit 0 this week, then the
 * Expiring Contracts digest for everyone at 4 weeks or fewer (§2). Anyone
 * with exactly 2 weeks left also makes the news and emails the GM.
 */
class SendContractEmails implements WeekStep
{
    public function __construct(
        private EmailService $emails,
        private NewsRecorder $news,
    ) {}

    public function handle(WeekContext $context): void
    {
        /** @var array<string, array{expired: list<SaveWrestler>, vacated: array<int, list<string>>}> $expired */
        $expired = $context->data['expired'] ?? [];

        foreach (Brand::cases() as $brand) {
            $result = $expired[$brand->value] ?? ['expired' => [], 'vacated' => []];
            $this->emails->contractExpired($context->save, $brand, $context->week, $result['expired'], $result['vacated']);
            $this->emails->expiringContracts($context->save, $brand, $context->week);
        }

        $this->nearlyUp($context);
    }

    private function nearlyUp(WeekContext $context): void
    {
        $newsWeeks = (int) config('gm.news.contract_expiring_weeks');
        $reminderWeeks = (int) config('gm.emails.contract_reminder_weeks');

        $contracts = Contract::with('saveWrestler.wrestler')
            ->where('save_id', $context->save->id)
            ->where('status', ContractStatus::Active)
            ->whereIn('weeks_remaining', array_unique([$newsWeeks, $reminderWeeks]))
            ->orderBy('id')
            ->get();

        foreach ($contracts as $contract) {
            $wrestler = $contract->saveWrestler;
            $brand = $wrestler->brand;

            // Only someone still on the roster under this contract counts.
            if ($brand === null || $brand !== $contract->brand) {
                continue;
            }

            if ($contract->weeks_remaining === $newsWeeks) {
                $this->news->record($context->save, NewsEventType::ContractExpiring, NewsTier::Story, $brand, [
                    'wrestler' => $wrestler->name(),
                    'brand' => $brand->label(),
                    'n' => $contract->weeks_remaining,
                ], ['wrestler_ids' => [$wrestler->id]], priority: (int) config('gm.news.contract_expiring_priority'), week: $context->week);
            }

            if ($contract->weeks_remaining === $reminderWeeks) {
                $this->emails->contractReminder($wrestler, $brand, $contract->weeks_remaining, $context->week);
            }
        }
    }
}
