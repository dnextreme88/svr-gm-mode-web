<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Models\SaveWrestler;
use App\Services\Mail\EmailService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Contract Expired notice for anyone who hit 0 this week, then the
 * Expiring Contracts digest for everyone at 4 weeks or fewer (§2).
 */
class SendContractEmails implements WeekStep
{
    public function __construct(private EmailService $emails) {}

    public function handle(WeekContext $context): void
    {
        /** @var array<string, array{expired: list<SaveWrestler>, vacated: array<int, list<string>>}> $expired */
        $expired = $context->data['expired'] ?? [];

        foreach (Brand::cases() as $brand) {
            $result = $expired[$brand->value] ?? ['expired' => [], 'vacated' => []];
            $this->emails->contractExpired($context->save, $brand, $context->week, $result['expired'], $result['vacated']);
            $this->emails->expiringContracts($context->save, $brand, $context->week);
        }
    }
}
