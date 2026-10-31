<?php

namespace App\Services\Morale;

use App\Enums\Brand;
use App\Enums\ContendershipVoidReason;
use App\Enums\EmailTemplate;
use App\Enums\Morale;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Roster\RosterService;
use App\Services\Titles\ContendershipService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;

/**
 * Unsatisfied and Upset wrestlers may try to jump to the rival brand (§8).
 */
class DefectionService
{
    public function __construct(
        private RosterService $roster,
        private TitleService $titles,
        private MitbService $mitb,
        private MoraleCalculator $morale,
        private EmailService $emails,
        private NewsRecorder $news,
        private ContendershipService $contenderships,
    ) {}

    public function chanceFor(Morale $morale): float
    {
        return match ($morale) {
            Morale::Unsatisfied => (float) config('gm.defection.chance.unsatisfied'),
            Morale::Upset => (float) config('gm.defection.chance.upset'),
            default => 0.0,
        };
    }

    /**
     * Roll every eligible wrestler for a defection attempt.
     *
     * @return list<SaveWrestler> wrestlers who attempted to defect
     */
    public function weekly(Save $save, int $week, Rng $rng): array
    {
        $attempts = [];

        $eligible = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->whereNotNull('brand')
            ->whereIn('morale', [Morale::Unsatisfied, Morale::Upset])
            ->orderBy('id')
            ->get();

        foreach ($eligible as $wrestler) {
            if ($wrestler->isSittingOut($week + 1) || ! $rng->chance($this->chanceFor($wrestler->morale))) {
                continue;
            }

            $this->attempt($wrestler, $week);
            $attempts[] = $wrestler;
        }

        return $attempts;
    }

    /**
     * A defection attempt. With room on the rival roster the wrestler moves,
     * keeping their contract; with a full rival roster they sit out 2 weeks.
     */
    public function attempt(SaveWrestler $wrestler, int $week): bool
    {
        $save = $wrestler->game;
        $from = $wrestler->brand ?? throw new \LogicException('Only signed wrestlers can defect.');
        $to = $from === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $name = $wrestler->name();

        $email = $this->emails->send($save, $from, EmailTemplate::DefectionAttempt, "{$name} is trying to leave for {$to->label()}", [
            'save_wrestler_id' => $wrestler->id,
            'to' => $to->value,
        ], $this->emails->text($save, 'defection', $from, $week, ['name' => $name, 'to' => $to->label()], $name), $week);

        $this->news->record($save, NewsEventType::DefectionAttempt, NewsTier::Story, $from, [
            'wrestler' => $name,
            'brand' => $from->label(),
        ], ['wrestler_ids' => [$wrestler->id]], priority: 40, week: $week, relatedEmailId: $email->id);

        if (! $this->roster->hasRoom($save, $to)) {
            $wrestler->update(['unbookable_until_week' => $week + 1 + (int) config('gm.defection.blocked_sit_out_weeks')]);

            $this->news->record($save, NewsEventType::DefectionBlocked, NewsTier::Story, $from, [
                'wrestler' => $name,
            ], ['wrestler_ids' => [$wrestler->id]], priority: 45, week: $week);

            return false;
        }

        $this->titles->vacateFor($wrestler, ReignEndReason::Defected, $week);
        $this->mitb->voidFor($wrestler, 'defected', $week);
        $this->contenderships->voidFor($wrestler, ContendershipVoidReason::Defected, $week);

        $wrestler->update(['brand' => $to, 'joined_brand_week' => $week + 1]);
        $wrestler->activeContract?->update(['brand' => $to]);

        // ASSUMPTION: a fresh start on the new brand, like other new arrivals (§15).
        $this->morale->startHappy($wrestler, $week);

        $this->news->record($save, NewsEventType::DefectionCompleted, NewsTier::Headline, NewsBrand::Both, [
            'wrestler' => $name,
            'rival_brand' => $to->label(),
        ], ['wrestler_ids' => [$wrestler->id]], priority: 85, week: $week);

        return true;
    }
}
