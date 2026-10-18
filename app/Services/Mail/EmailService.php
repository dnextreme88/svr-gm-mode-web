<?php

namespace App\Services\Mail;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Calendar\CalendarService;
use App\Services\Titles\TitleService;

/**
 * Private GM emails from JR (Raw) or Tazz (SmackDown) (§2, §3).
 */
class EmailService
{
    public function __construct(
        private CalendarService $calendar,
        private TitleService $titles,
    ) {}

    public function sender(Brand $brand): string
    {
        return (string) config('gm.email_senders.'.$brand->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(Save $save, Brand $brand, EmailTemplate $template, string $subject, array $payload = [], ?string $body = null, ?int $week = null): Email
    {
        $week ??= $save->current_week;

        return Email::create([
            'save_id' => $save->id,
            'brand' => $brand,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'template' => $template,
            'sender' => $this->sender($brand),
            'subject' => $subject,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    /**
     * The weekly Expiring Contracts digest (§2): one email listing everyone
     * on the brand with 4 or fewer weeks left, sorted by weeks left.
     */
    public function expiringContracts(Save $save, Brand $brand, int $week): ?Email
    {
        $threshold = (int) config('gm.contracts.expiring_threshold_weeks');

        $contracts = Contract::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('status', ContractStatus::Active)
            ->where('weeks_remaining', '>', 0)
            ->where('weeks_remaining', '<=', $threshold)
            ->whereHas('saveWrestler', fn ($q) => $q->where('brand', $brand))
            ->get()
            ->sort(fn (Contract $a, Contract $b): int => [$a->weeks_remaining, $a->saveWrestler->name()] <=> [$b->weeks_remaining, $b->saveWrestler->name()])
            ->values();

        if ($contracts->isEmpty()) {
            return null;
        }

        $rows = $contracts->map(function (Contract $contract): array {
            $wrestler = $contract->saveWrestler;
            $titles = $this->titles->titlesHeldBy($wrestler);

            return [
                'save_wrestler_id' => $wrestler->id,
                'name' => $wrestler->name(),
                'weeks_left' => $contract->weeks_remaining,
                'weekly_cost' => $contract->weekly_cost,
                'morale' => $wrestler->morale->value,
                'champion' => $titles !== [],
                'titles' => $titles,
                'expires_this_week' => $contract->weeks_remaining === 1,
            ];
        })->all();

        $count = count($rows);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::ExpiringContracts,
            "Contracts expiring soon — {$count} superstar(s)",
            ['rows' => $rows],
            week: $week,
        );
    }

    /**
     * Follow-up listing everyone whose contract hit 0 this week (§2).
     *
     * @param  list<SaveWrestler>  $expired
     * @param  array<int, list<string>>  $vacatedTitles  titles vacated, keyed by save_wrestler_id
     */
    public function contractExpired(Save $save, Brand $brand, int $week, array $expired, array $vacatedTitles = []): ?Email
    {
        if ($expired === []) {
            return null;
        }

        $count = count($expired);

        return $this->send(
            $save,
            $brand,
            EmailTemplate::ContractExpired,
            "Contract expired — {$count} superstar(s) left for Free Agency",
            [
                'rows' => array_map(fn (SaveWrestler $w): array => [
                    'save_wrestler_id' => $w->id,
                    'name' => $w->name(),
                    'vacated' => $vacatedTitles[$w->id] ?? [],
                ], $expired),
            ],
            'These contracts ran out this week. Each wrestler has been released to Free Agency.',
            $week,
        );
    }
}
