<?php

namespace App\Services\Injuries;

use App\Enums\EmailTemplate;
use App\Enums\InjurySeverity;
use App\Enums\InjuryZone;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Models\Injury;
use App\Models\InjuryType;
use App\Models\MatchType;
use App\Models\SaveWrestler;
use App\Services\Fatigue\FatigueZones;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;

/**
 * Injury rolls, escalation and returns (§6). An injury taken in week W with
 * a duration of d weeks keeps the wrestler out of weeks W+1 … W+d.
 */
class InjuryService
{
    public function __construct(
        private InjuryStatus $status,
        private TitleService $titles,
        private EmailService $emails,
        private NewsRecorder $news,
    ) {}

    /**
     * Roll injury consequences for a competitor, using fatigue going into the match.
     */
    public function rollForMatch(SaveWrestler $wrestler, int $fatigueGoingIn, MatchType $type, int $week, Rng $rng, ?int $slotId = null): ?Injury
    {
        return $this->rollFor($wrestler, $fatigueGoingIn, $type->fatigue_cost_bars, $week, $rng, $slotId);
    }

    /**
     * Promos that tire their wrestlers (Interference, Start a Rivalry) roll
     * injuries exactly like a match that adds the same fatigue (§5, §6).
     */
    public function rollForPromo(SaveWrestler $wrestler, int $fatigueGoingIn, int $fatigueBars, int $week, Rng $rng): ?Injury
    {
        return $this->rollFor($wrestler, $fatigueGoingIn, $fatigueBars, $week, $rng);
    }

    private function rollFor(SaveWrestler $wrestler, int $fatigueGoingIn, int $fatigueBars, int $week, Rng $rng, ?int $slotId = null): ?Injury
    {
        $existing = $this->status->active($wrestler, $week);

        if ($existing?->severity === InjurySeverity::Minor && $rng->chance((float) config('gm.injuries.escalation_chance'))) {
            $escalated = $this->escalate($existing, $week, $rng);

            if ($escalated !== null) {
                return $escalated;
            }
        }

        $zone = FatigueZones::zoneFor($fatigueGoingIn);

        if ($zone === InjuryZone::SeriousRisk && $fatigueBars > 0) {
            return $this->injure($wrestler, InjurySeverity::Serious, $week, $rng, $slotId);
        }

        if ($zone === InjuryZone::MinorRisk && $rng->chance((float) config('gm.injuries.minor_chance'))) {
            return $this->injure($wrestler, InjurySeverity::Minor, $week, $rng, $slotId);
        }

        return null;
    }

    /**
     * Give a wrestler a new injury with a label and duration rolled in its severity.
     */
    public function injure(SaveWrestler $wrestler, InjurySeverity $severity, int $week, Rng $rng, ?int $slotId = null, ?InjuryType $type = null): Injury
    {
        $type ??= $rng->pick(InjuryType::where('severity', $severity)->orderBy('id')->get()->all());
        $duration = $rng->int($type->min_weeks, $type->max_weeks);

        $this->healCurrent($wrestler, $week);

        $injury = Injury::create([
            'save_id' => $wrestler->save_id,
            'save_wrestler_id' => $wrestler->id,
            'injury_type_id' => $type->id,
            'severity' => $severity,
            'started_week' => $week,
            'ends_week' => $week + $duration + 1,
            'match_slot_id' => $slotId,
        ]);

        $this->afterInjury($wrestler, $injury->load('type'), $week, false);

        return $injury;
    }

    /**
     * Turn a minor injury into its serious "Escalates to" label with a fresh
     * duration (§6). Injuries with no escalation (Laceration) stay minor.
     */
    public function escalate(Injury $minor, int $week, Rng $rng): ?Injury
    {
        $target = $minor->type->escalatesTo;

        if ($target === null) {
            return null;
        }

        $minor->update(['healed_week' => $week]);
        $duration = $rng->int($target->min_weeks, $target->max_weeks);

        $injury = Injury::create([
            'save_id' => $minor->save_id,
            'save_wrestler_id' => $minor->save_wrestler_id,
            'injury_type_id' => $target->id,
            'severity' => InjurySeverity::Serious,
            'started_week' => $week,
            'ends_week' => $week + $duration + 1,
            'escalated_from_minor' => true,
            'escalated_from_id' => $minor->id,
        ]);

        $this->afterInjury($minor->saveWrestler, $injury->load('type'), $week, true);

        return $injury;
    }

    /**
     * Injuries whose last week out was this week: the wrestler returns next week.
     *
     * @return list<Injury>
     */
    public function returningAfter(int $saveId, int $week): array
    {
        return array_values(Injury::with(['saveWrestler.wrestler', 'type'])
            ->where('save_id', $saveId)
            ->whereNull('healed_week')
            ->where('ends_week', $week + 1)
            ->get()
            ->all());
    }

    /**
     * A champion's injury vacates the title as soon as it's assigned (§16).
     */
    public function vacatesTitles(Injury $injury): bool
    {
        $overWeeks = config('gm.titles.vacate_on_injury_over_weeks');

        if ($overWeeks !== null && $injury->durationWeeks() - 1 > (int) $overWeeks) {
            return true;
        }

        return $injury->severity === InjurySeverity::Serious && (bool) config('gm.titles.vacate_on_serious_injury');
    }

    private function healCurrent(SaveWrestler $wrestler, int $week): void
    {
        Injury::where('save_wrestler_id', $wrestler->id)
            ->whereNull('healed_week')
            ->where('ends_week', '>', $week)
            ->update(['healed_week' => $week]);
    }

    private function afterInjury(SaveWrestler $wrestler, Injury $injury, int $week, bool $escalated): void
    {
        $wrestler->loadMissing('wrestler');
        $weeksOut = $injury->durationWeeks() - 1;
        $champion = $this->titles->isChampion($wrestler);
        $vacated = [];

        if ($this->vacatesTitles($injury)) {
            $vacated = $this->titles->vacateFor($wrestler, ReignEndReason::Injured, $week);
        }

        if ($wrestler->brand !== null) {
            $this->emails->send(
                $wrestler->game,
                $wrestler->brand,
                EmailTemplate::Injury,
                "{$wrestler->name()}: {$injury->type->label}",
                [
                    'save_wrestler_id' => $wrestler->id,
                    'label' => $injury->type->label,
                    'severity' => $injury->severity->value,
                    'weeks' => $weeksOut,
                    'escalated' => $escalated,
                    'vacated' => $vacated,
                ],
                $escalated
                    ? "{$wrestler->name()}'s injury got worse. It's now a {$injury->type->label}."
                    : "{$wrestler->name()} suffered a {$injury->severity->value} injury ({$injury->type->label}).",
                $week,
            );
        }

        $type = $escalated ? NewsEventType::InjuryEscalated : NewsEventType::Injury;
        $tier = ! $escalated && $champion && $injury->severity === InjurySeverity::Serious ? NewsTier::Headline : NewsTier::Story;

        $this->news->record($wrestler->game, $type, $tier, $wrestler->brand ?? NewsBrand::Both, [
            'wrestler' => $wrestler->name(),
            'severity' => $injury->severity->value,
            'weeks' => $weeksOut,
            'label' => $injury->type->label,
        ], ['wrestler_ids' => [$wrestler->id]], priority: $injury->severity === InjurySeverity::Serious ? 50 : 20, week: $week);
    }
}
