<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\FactionOrigin;
use App\Enums\FactionPromptStatus;
use App\Enums\FactionType;
use App\Enums\ParticipantRole;
use App\Enums\RosterType;
use App\Models\FactionPrompt;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\Show;
use App\Services\Factions\FactionNamer;
use App\Services\Factions\FactionService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * New factions (house rule): 2 or 3 male superstars who have won
 * `formation_wins` times together, as the whole winning side, and aren't
 * already one faction. The rival GM's teams form on the spot with a name
 * from `gm.factions.ai_names`. The player's wait for a name in a prompt;
 * a prompt put off with "Not now" comes back after their next win together.
 */
class FactionFormation implements WeekStep
{
    public function __construct(
        private FactionNamer $namer,
        private FactionService $factions,
    ) {}

    public function handle(WeekContext $context): void
    {
        $save = $context->save;
        $max = (int) config('gm.factions.formation_max_size');
        $seen = [];

        $slots = MatchSlot::with(['show', 'result', 'participants.saveWrestler.wrestler'])
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('week_number', $context->week))
            ->whereHas('result')
            ->get()
            ->sortBy(fn (MatchSlot $s): string => sprintf('%08d-%04d', $s->show_id, $s->position));

        foreach ($slots as $slot) {
            $winners = $slot->participants->filter(fn (MatchParticipant $p): bool => $p->role === ParticipantRole::Competitor && $p->side === $slot->result?->winner_side);
            // In the side's order, as they'll be listed.
            $ids = array_values(array_map('intval', $winners->pluck('save_wrestler_id')->all()));
            $key = FactionPrompt::keyFor($ids);

            if (count($ids) < 2 || count($ids) > $max || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($winners->contains(fn (MatchParticipant $p): bool => $p->saveWrestler->wrestler->roster_type === RosterType::Diva)) {
                continue;
            }

            if ($this->namer->factionOf($save->id, $ids, $context->week) !== null) {
                continue;
            }

            [$wins, $firstWeek] = $this->winsTogether($save, $ids);

            if ($wins < (int) config('gm.factions.formation_wins')) {
                continue;
            }

            $brands = array_unique(array_map(fn (MatchParticipant $p): ?string => $p->saveWrestler->brand?->value, $winners->all()));
            $brand = count($brands) === 1 && reset($brands) !== null ? Brand::from((string) reset($brands)) : null;

            if ($brand === null) {
                continue;
            }

            if ($save->isHuman($brand)) {
                $this->prompt($save, $key, $ids, $wins, $firstWeek, $context->week, $slot->show);
            } else {
                $this->formForRival($context, $ids, $wins, $firstWeek, $slot->show);
            }
        }
    }

    /**
     * How many times exactly these wrestlers won together as the whole
     * winning side, and the week of the first.
     *
     * @param  list<int>  $ids
     * @return array{0: int, 1: int}
     */
    public function winsTogether(Save $save, array $ids): array
    {
        $rows = MatchParticipant::query()
            ->join('match_slots', 'match_slots.id', '=', 'match_participants.match_slot_id')
            ->join('shows', 'shows.id', '=', 'match_slots.show_id')
            ->where('shows.save_id', $save->id)
            ->where('match_participants.role', ParticipantRole::Competitor->value)
            ->where('match_participants.result', 'win')
            ->whereIn('match_participants.match_slot_id', function ($q) use ($ids): void {
                $q->select('match_slot_id')->from('match_participants')->where('save_wrestler_id', $ids[0])->where('result', 'win');
            })
            ->get(['match_participants.match_slot_id', 'match_participants.save_wrestler_id', 'shows.week_number']);

        sort($ids);
        $wins = 0;
        $first = null;

        foreach ($rows->groupBy('match_slot_id') as $group) {
            $set = array_map('intval', $group->pluck('save_wrestler_id')->all());
            sort($set);

            if ($set === $ids) {
                $wins++;
                $week = (int) $group->first()?->getAttribute('week_number');
                $first = $first === null ? $week : min($first, $week);
            }
        }

        return [$wins, $first ?? 0];
    }

    /**
     * @param  list<int>  $ids
     */
    private function prompt(Save $save, string $key, array $ids, int $wins, int $firstWeek, int $week, Show $show): void
    {
        $prompt = FactionPrompt::where('save_id', $save->id)->where('member_key', $key)->first();
        $fields = ['member_ids' => $ids, 'wins' => $wins, 'first_win_week' => $firstWeek, 'week_number' => $week, 'show_id' => $show->id];

        if ($prompt === null) {
            FactionPrompt::create(['save_id' => $save->id, 'member_key' => $key, 'status' => FactionPromptStatus::Pending, ...$fields]);

            return;
        }

        // Put off ("Not now") or formed and since disbanded: back on their next win.
        if ($prompt->status !== FactionPromptStatus::Pending && $wins <= (int) $prompt->deferred_at_wins) {
            return;
        }

        $prompt->update(['status' => FactionPromptStatus::Pending, ...$fields]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function formForRival(WeekContext $context, array $ids, int $wins, int $firstWeek, Show $show): ?SaveFaction
    {
        $save = $context->save;
        $names = array_values(array_filter(
            (array) config('gm.factions.ai_names'),
            fn (string $name): bool => ! $this->factions->nameTaken($save, $name),
        ));

        if ($names === []) {
            return null;
        }

        $name = (string) $context->rng('factions.names.'.FactionPrompt::keyFor($ids))->pick($names);
        $type = count($ids) === 2 ? FactionType::TagTeam : FactionType::Stable;

        return $this->factions->form($save, $ids, $name, $type, FactionOrigin::Rival, $context->week, $show, $firstWeek, $wins);
    }
}
