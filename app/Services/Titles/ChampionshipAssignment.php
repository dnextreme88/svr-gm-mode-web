<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\SaveStage;
use App\Enums\WonVia;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TagTeam;
use App\Models\TitleReign;
use App\Services\Injuries\InjuryStatus;
use App\Services\News\NewsRecorder;
use App\Services\Week\ShowScheduler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Awarding titles: reign #1 after the initial draft (§1, §16) and awarding
 * vacant titles later on.
 */
class ChampionshipAssignment
{
    public function __construct(
        private TitleService $titles,
        private ShowScheduler $shows,
        private NewsRecorder $news,
        private InjuryStatus $injuries,
    ) {}

    /**
     * Wrestlers on the brand who can hold this title.
     *
     * @return Collection<int, SaveWrestler>
     */
    public function eligible(Save $save, Championship $championship): Collection
    {
        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->where('brand', $championship->brand)
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $championship->allowsWrestler($w->wrestler)
                && ! $this->injuries->isSeriouslyInjured($w, $save->current_week))
            ->sortBy(fn (SaveWrestler $w): string => $w->wrestler->name)
            ->values();
    }

    /**
     * Award a title that has no current holder.
     *
     * @param  list<int>  $saveWrestlerIds  one id for singles titles, two for tag titles
     */
    public function assign(Save $save, Championship $championship, array $saveWrestlerIds, ?int $week = null): TitleReign
    {
        $week ??= $save->current_week;
        $needed = $championship->is_tag ? 2 : 1;
        $saveWrestlerIds = array_values(array_unique($saveWrestlerIds));

        if (count($saveWrestlerIds) !== $needed) {
            throw new GameRuleException("{$championship->name} needs {$needed} holder(s).");
        }

        if ($this->titles->currentReign($save, $championship) !== null) {
            throw new GameRuleException("{$championship->name} already has a champion.");
        }

        $eligibleIds = $this->eligible($save, $championship)->pluck('id')->all();

        foreach ($saveWrestlerIds as $id) {
            if (! in_array($id, $eligibleIds, true)) {
                throw new GameRuleException("That wrestler isn't eligible for the {$championship->name}.");
            }
        }

        $members = SaveWrestler::with('wrestler')->whereIn('id', $saveWrestlerIds)->get();
        $holder = $championship->is_tag
            ? $this->findOrCreateTeam($save, $championship->brand, $members)
            : $members->firstOrFail();

        $reign = $this->titles->award($save, $championship, $holder, $week, WonVia::Awarded);

        if ($save->stage === SaveStage::InSeason) {
            $this->news->record($save, NewsEventType::TitleAwarded, NewsTier::Story, $championship->brand, [
                'wrestler' => $reign->holderName(),
                'title' => $championship->name,
            ], ['championship_id' => $championship->id, 'wrestler_ids' => $reign->holderIds()], priority: 40, week: $week);
        }

        return $reign;
    }

    /**
     * AI title assignment: random eligible holders, distinct where possible.
     */
    public function autoAssign(Save $save, Brand $brand): void
    {
        $taken = [];

        foreach (Championship::where('brand', $brand)->orderBy('sort_order')->get() as $championship) {
            if ($this->titles->currentRow($save, $championship) !== null) {
                continue;
            }

            $pool = $this->eligible($save, $championship)->reject(fn (SaveWrestler $w): bool => in_array($w->id, $taken, true))->values();
            $needed = $championship->is_tag ? 2 : 1;

            if ($pool->count() < $needed) {
                $this->titles->leaveVacant($save, $championship, $save->current_week);

                continue;
            }

            $picked = $save->rng("ai.titles.{$championship->key->value}", $save->current_week)->sample($pool->pluck('id')->all(), $needed);
            $ids = array_map('intval', $picked);
            $this->assign($save, $championship, $ids);
            $taken = [...$taken, ...$ids];
        }
    }

    /**
     * Finish the post-draft assignment step. Titles left unassigned stay vacant.
     */
    public function complete(Save $save): void
    {
        if ($save->stage !== SaveStage::AssigningTitles) {
            throw new GameRuleException('Titles have already been assigned.');
        }

        foreach (Championship::orderBy('sort_order')->get() as $championship) {
            if ($this->titles->currentRow($save, $championship) === null) {
                $this->titles->leaveVacant($save, $championship, $save->current_week);
            }
        }

        $save->update(['stage' => SaveStage::InSeason]);
        $this->shows->ensureWeek($save, $save->current_week);
    }

    /**
     * @param  Collection<int, SaveWrestler>  $members
     */
    public function findOrCreateTeam(Save $save, Brand $brand, Collection $members): TagTeam
    {
        $ids = $members->pluck('id')->sort()->values()->all();

        $existing = TagTeam::with('members')
            ->where('save_id', $save->id)
            ->where('active', true)
            ->get()
            ->first(fn (TagTeam $team): bool => $team->members->pluck('id')->sort()->values()->all() === $ids);

        if ($existing !== null) {
            return $existing;
        }

        $team = TagTeam::create([
            'save_id' => $save->id,
            'name' => $members->map(fn (SaveWrestler $m): string => $m->name())->implode(' & '),
            'brand' => $brand,
            'active' => true,
        ]);
        $team->members()->attach($ids);

        return $team->load('members');
    }
}
