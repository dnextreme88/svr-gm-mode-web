<?php

namespace App\Services\Promos;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Models\Injury;
use App\Models\MatchSlot;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Injuries\InjuryStatus;
use App\Services\Rivalries\RivalryService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which promos can run on a show and who or what each can target (§5). The
 * card screen greys out unavailable promos from this, and booking validates
 * against it, so the two never disagree.
 */
class PromoEligibility
{
    public function __construct(
        private InjuryStatus $injuries,
        private RivalryService $rivalries,
    ) {}

    /**
     * @return array<string, array{available: bool, reason: string|null, wrestler_ids: list<int>, pairs: list<array{0: int, 1: int}>, championship_ids: list<int>}>
     */
    public function forShow(Show $show, Brand $brand): array
    {
        $context = $this->context($show, $brand);
        $result = [];

        foreach (PromoType::orderBy('sort_order')->get() as $type) {
            $result[$type->key->value] = $this->forType($type, $context);
        }

        return $result;
    }

    /**
     * @return array{available: bool, reason: string|null, wrestler_ids: list<int>, pairs: list<array{0: int, 1: int}>, championship_ids: list<int>}
     */
    public function forPromo(Show $show, Brand $brand, PromoType $type): array
    {
        return $this->forType($type, $this->context($show, $brand));
    }

    /**
     * @param  array{roster: Collection<int, SaveWrestler>, all: list<int>, booked: list<int>, injured: list<int>, active: list<int>, rivals: list<array{0: int, 1: int}>, inRivalry: list<int>, longOut: list<int>, titles: list<int>, joined: list<int>}  $c
     * @return array{available: bool, reason: string|null, wrestler_ids: list<int>, pairs: list<array{0: int, 1: int}>, championship_ids: list<int>}
     */
    private function forType(PromoType $type, array $c): array
    {
        $divas = $this->ints($c['roster']->filter(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva)->modelKeys());
        $healthy = $c['active'];

        [$ids, $pairs, $titles, $reason] = match ($type->key) {
            PromoTypeKey::NewSuperstar => [$c['joined'], [], [], 'Only works on a freshly signed or traded-in superstar, and nobody joined recently.'],
            PromoTypeKey::ContinueRivalry => [$this->idsIn($c['rivals']), $c['rivals'], [], 'You have no active rivalries.'],
            PromoTypeKey::StartRivalry => [array_values(array_diff($healthy, $c['inRivalry'])), [], [], 'Needs two healthy superstars who aren’t already in a rivalry.'],
            PromoTypeKey::InactiveSuperstar => [array_values(array_diff($c['all'], $c['booked'])), [], [], 'Everyone on your roster is booked on this card.'],
            PromoTypeKey::ComebackReminder => [$c['longOut'], [], [], 'Nobody has been out injured for more than '.(int) config('gm.promos.comeback_reminder_min_weeks_out').' weeks.'],
            PromoTypeKey::InterviewSpace => [$healthy, [], [], 'Nobody on your roster is healthy enough.'],
            PromoTypeKey::DivaBikiniContest => [array_values(array_intersect($healthy, $divas)), [], [], 'Needs two healthy Divas.'],
            PromoTypeKey::InterferencePromotion => [array_values(array_diff($healthy, $divas)), [], [], 'Needs a healthy non-Diva superstar.'],
            PromoTypeKey::TitleMatchHype => [[], [], $c['titles'], 'Book and save a title match on this card first.'],
            default => [[], [], [], null],
        };

        $available = match (true) {
            $type->needs_championship => $titles !== [],
            $type->key === PromoTypeKey::ContinueRivalry => $pairs !== [],
            $type->needsPair() => count($ids) >= 2,
            $type->needs_wrestler => $ids !== [],
            default => true,
        };

        return [
            'available' => $available,
            'reason' => $available ? null : $reason,
            'wrestler_ids' => $ids,
            'pairs' => $pairs,
            'championship_ids' => $titles,
        ];
    }

    /**
     * @return array{roster: Collection<int, SaveWrestler>, all: list<int>, booked: list<int>, injured: list<int>, active: list<int>, rivals: list<array{0: int, 1: int}>, inRivalry: list<int>, longOut: list<int>, titles: list<int>, joined: list<int>}
     */
    private function context(Show $show, Brand $brand): array
    {
        $week = $show->week_number;
        $roster = SaveWrestler::with('wrestler')->where('save_id', $show->save_id)->where('brand', $brand)->orderBy('id')->get();
        $ids = $this->ints($roster->modelKeys());
        $injured = array_values(array_intersect($ids, $this->injuries->seriouslyInjuredIds($show->save_id, $week)));
        $active = $this->ints($roster->filter(fn (SaveWrestler $w): bool => ! in_array($w->id, $injured, true) && ! $w->isSittingOut($week))->modelKeys());

        $rivals = array_values($this->rivalries->active($show->game)
            ->filter(fn (Rivalry $r): bool => in_array($r->wrestler_a_id, $ids, true) && in_array($r->wrestler_b_id, $ids, true))
            ->map(fn (Rivalry $r): array => [$r->wrestler_a_id, $r->wrestler_b_id])
            ->all());
        $inRivalry = $this->ints($this->rivalries->active($show->game)
            ->flatMap(fn (Rivalry $r): array => [$r->wrestler_a_id, $r->wrestler_b_id])
            ->unique()
            ->all());

        $minOut = (int) config('gm.promos.comeback_reminder_min_weeks_out');
        $longOut = $this->ints(Injury::where('save_id', $show->save_id)
            ->whereIn('save_wrestler_id', $injured)
            ->where('severity', InjurySeverity::Serious)
            ->whereNull('healed_week')
            ->where('started_week', '<', $week - $minOut)
            ->where('ends_week', '>', $week)
            ->pluck('save_wrestler_id')
            ->unique()
            ->all());

        $booked = $this->ints(MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->get()
            ->flatMap(fn (MatchSlot $s): array => $s->participantIds())
            ->unique()
            ->all());

        $titles = $this->ints(MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->whereNotNull('title_championship_id')
            ->orderBy('position')
            ->get()
            ->filter(fn (MatchSlot $s): bool => $s->isBooked())
            ->map(fn (MatchSlot $s): int => (int) $s->title_championship_id)
            ->unique()
            ->all());

        $window = (int) config('gm.promos.new_superstar_window_weeks');
        $joined = $this->ints($roster->filter(fn (SaveWrestler $w): bool => $w->joined_brand_week !== null && $w->joined_brand_week >= $week - $window)->modelKeys());

        return [
            'roster' => $roster,
            'all' => $ids,
            'booked' => $booked,
            'injured' => $injured,
            'active' => $active,
            'rivals' => $rivals,
            'inRivalry' => $inRivalry,

            'longOut' => $longOut,
            'titles' => $titles,
            'joined' => $joined,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function ints(array $ids): array
    {
        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return list<int>
     */
    private function idsIn(array $pairs): array
    {
        return array_values(array_unique(array_merge([], ...$pairs)));
    }
}
