<?php

namespace App\Services\Morale;

use App\Enums\ChampionshipEligibility;
use App\Enums\ComplaintLevel;
use App\Enums\EmailTemplate;
use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\Championship;
use App\Models\MoraleComplaint;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Injuries\InjuryStatus;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;

/**
 * Complaint-driven morale (§15). Each complaint tracks its own level; morale
 * is the worst active level, or Happy with none.
 */
class MoraleService
{
    public function __construct(
        private MoraleCalculator $calculator,
        private InjuryStatus $injuries,
        private TitleService $titles,
        private EmailService $emails,
        private NewsRecorder $news,
    ) {}

    /**
     * Weekly update after the shows air.
     *
     * @param  array<int, bool>  $used  wrestlers booked this week (competitor or manager)
     * @param  array<int, bool>  $titleMatch  wrestlers who were in a title match
     */
    public function weekly(Save $save, int $week, array $used, array $titleMatch): void
    {
        $wrestlers = SaveWrestler::with('wrestler')->where('save_id', $save->id)->whereNotNull('brand')->orderBy('id')->get();
        $champions = $this->titles->currentReigns($save)->flatMap(fn ($r) => $r->holderIds())->all();
        $championships = Championship::all();

        foreach ($wrestlers as $wrestler) {
            $excused = $this->injuries->isSeriouslyInjured($wrestler, $week) || $wrestler->isSittingOut($week);
            $wasUsed = isset($used[$wrestler->id]);

            // Inactivity: clears when booked; injury and sit-out weeks don't count.
            if ($wasUsed) {
                $wrestler->weeks_unused = 0;
                $this->calculator->resolve($wrestler, MoraleComplaintType::Inactivity, $week);
            } elseif (! $excused) {
                $wrestler->weeks_unused++;
            }

            // Title match demand: only if the brand has a title they're eligible for.
            // Per §15, SmackDown Divas have no title, so Divas only count Divas-only titles.
            $eligible = $championships->contains(fn (Championship $c): bool => $c->brand === $wrestler->brand
                && $c->allowsWrestler($wrestler->wrestler)
                && (! $wrestler->wrestler->isDiva() || $c->eligibility === ChampionshipEligibility::DivasOnly));
            $exempt = (bool) config('gm.morale.champions_exempt_from_title_demand') && in_array($wrestler->id, $champions, true);

            if (isset($titleMatch[$wrestler->id]) || ! $eligible || $exempt) {
                $wrestler->weeks_since_title_match = 0;
                $this->calculator->resolve($wrestler, MoraleComplaintType::TitleMatch, $week);
            } elseif (! $excused) {
                $wrestler->weeks_since_title_match++;
            }

            // Losing streak clears on a win.
            if ($wrestler->loss_streak === 0) {
                $this->calculator->resolve($wrestler, MoraleComplaintType::LosingStreak, $week);
            }

            $wrestler->save();

            $before = $wrestler->morale;

            $this->escalate($wrestler, MoraleComplaintType::LosingStreak, $wrestler->loss_streak, $week);
            $this->escalate($wrestler, MoraleComplaintType::ContractExtension, $wrestler->win_streak, $week);
            $this->escalate($wrestler, MoraleComplaintType::TitleMatch, $wrestler->weeks_since_title_match, $week);
            $this->escalate($wrestler, MoraleComplaintType::Inactivity, $wrestler->weeks_unused, $week);

            $after = $this->calculator->recalculate($wrestler);

            if ($after === Morale::Upset && $before !== Morale::Upset) {
                $this->news->record($save, NewsEventType::MoraleUnrest, NewsTier::Ticker, $wrestler->brand, [
                    'wrestler' => $wrestler->name(),
                    'brand' => $wrestler->brand?->label(),
                ], ['wrestler_ids' => [$wrestler->id]], week: $week);
            }
        }
    }

    /**
     * The level a value reaches for a complaint type, from config thresholds.
     */
    public function levelFor(MoraleComplaintType $type, int $value): ?ComplaintLevel
    {
        [$content, $unsatisfied, $upset] = (array) config('gm.morale.'.$type->value);

        return match (true) {
            $value >= $upset => ComplaintLevel::Upset,
            $value >= $unsatisfied => ComplaintLevel::Unsatisfied,
            $value >= $content => ComplaintLevel::Content,
            default => null,
        };
    }

    /**
     * Open a complaint or raise its level. Complaints never drop a level on
     * their own; each clears through its own rule (§15). Each new level
     * sends an email to the GM.
     */
    private function escalate(SaveWrestler $wrestler, MoraleComplaintType $type, int $value, int $week): void
    {
        $level = $this->levelFor($type, $value);

        if ($level === null) {
            return;
        }

        $existing = $wrestler->activeComplaints()->where('type', $type)->first();

        if ($existing !== null && $this->calculator->rank($existing->level) >= $this->calculator->rank($level)) {
            return;
        }

        if ($existing !== null) {
            $existing->update(['level' => $level]);
        } else {
            MoraleComplaint::create([
                'save_id' => $wrestler->save_id,
                'save_wrestler_id' => $wrestler->id,
                'type' => $type,
                'level' => $level,
                'opened_week' => $week,
            ]);
        }

        $this->notify($wrestler, $type, $level, $value, $week);
    }

    private function notify(SaveWrestler $wrestler, MoraleComplaintType $type, ComplaintLevel $level, int $value, int $week): void
    {
        $name = $wrestler->name();

        // Superstars only write to the GM once they're Unsatisfied or Upset.
        if ($wrestler->brand === null || $level === ComplaintLevel::Content) {
            return;
        }

        $upset = $level === ComplaintLevel::Upset;
        [$subject, $body] = match ($type) {
            MoraleComplaintType::LosingStreak => [
                "I've lost {$value} straight",
                "I've lost {$value} matches in a row and I'm sick of it. Put me in a position to win.",
            ],
            MoraleComplaintType::ContractExtension => [
                'I want a new contract',
                "I've won {$value} straight and I'm still on the same deal. I've earned a new contract.",
            ],
            MoraleComplaintType::TitleMatch => [
                'Where is my title shot?',
                "It's been {$value} weeks since I got a title match. I deserve a shot at the gold.",
            ],
            MoraleComplaintType::Inactivity => [
                'Put me on the card',
                "I haven't been used in {$value} weeks. I didn't sign with {$wrestler->brand->label()} to sit in the back.",
            ],
        };

        $body .= $upset
            ? ' I\'m upset, and if nothing changes I\'ll start looking at the other brand.'
            : ' I\'m not happy about it.';

        $this->emails->fromSuperstar($wrestler, $wrestler->brand, EmailTemplate::MoraleComplaint, $subject, $body, $week, [
            'type' => $type->value,
            'level' => $level->value,
            'value' => $value,
        ]);

        if ($type === MoraleComplaintType::ContractExtension) {
            $this->news->record($wrestler->game, NewsEventType::ContractDemand, NewsTier::Ticker, $wrestler->brand, [
                'wrestler' => $name,
                'n' => $value,
            ], ['wrestler_ids' => [$wrestler->id]], week: $week);
        }

        if ($type === MoraleComplaintType::TitleMatch) {
            $title = Championship::where('brand', $wrestler->brand)->orderBy('sort_order')->get()->first(fn (Championship $c): bool => $c->allowsWrestler($wrestler->wrestler));

            $this->news->record($wrestler->game, NewsEventType::TitleDemand, NewsTier::Ticker, $wrestler->brand, [
                'wrestler' => $name,
                'title' => $title?->name,
            ], ['wrestler_ids' => [$wrestler->id]], week: $week);
        }
    }
}
