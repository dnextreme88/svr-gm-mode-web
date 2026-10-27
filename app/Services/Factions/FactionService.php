<?php

namespace App\Services\Factions;

use App\Enums\Brand;
use App\Enums\FactionOrigin;
use App\Enums\FactionType;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Exceptions\GameRuleException;
use App\Models\Faction;
use App\Models\MatchParticipant;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\News\NewsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Factions inside a save (house rule): copying the seeded ones into a new
 * save, forming, growing and disbanding them, and their record.
 */
class FactionService
{
    public function __construct(
        private FactionNamer $namer,
        private NewsRecorder $news,
        private CalendarService $calendar,
    ) {}

    /**
     * Every seeded faction is active in a new save from week 1, whatever its
     * real-life dates (those are for display only).
     */
    public function seedSave(Save $save): void
    {
        $saveWrestlers = SaveWrestler::where('save_id', $save->id)->pluck('id', 'wrestler_id');

        foreach (Faction::with('members')->orderBy('id')->get() as $faction) {
            $saveFaction = SaveFaction::create([
                'save_id' => $save->id,
                'faction_id' => $faction->id,
                'faction_name' => $faction->faction_name,
                'faction_type' => $faction->faction_type,
                'origin' => FactionOrigin::Seeded,
                'formed_week' => 0,
                'record_from_week' => 0,
            ]);

            $saveFaction->members()->attach($faction->members->map(fn ($w): int => (int) $saveWrestlers[$w->id])->all(), ['joined_week' => 0]);
        }

        $this->namer->forget($save->id);
    }

    /**
     * Form a faction. Custom factions date from the show where the founding
     * win came, and their record counts from `$recordFromWeek` (the first
     * win together).
     *
     * @param  list<int>  $memberIds
     */
    public function form(Save $save, array $memberIds, string $name, FactionType $type, FactionOrigin $origin, int $week, ?Show $show, int $recordFromWeek, int $wins = 0): SaveFaction
    {
        $name = trim($name);
        $memberIds = array_values(array_unique($memberIds));
        $min = (int) config('gm.factions.min_members');
        $max = (int) config('gm.factions.max_members');

        if (count($memberIds) < $min || count($memberIds) > $max) {
            throw new GameRuleException("A faction needs {$min} to {$max} members.");
        }

        if ($name === '' || mb_strlen($name) > (int) config('gm.factions.name_max_length')) {
            throw new GameRuleException('Give the faction a name of up to '.config('gm.factions.name_max_length').' characters.');
        }

        if ($this->nameTaken($save, $name)) {
            throw new GameRuleException("There is already a faction called {$name}.");
        }

        return DB::transaction(function () use ($save, $memberIds, $name, $type, $origin, $week, $show, $recordFromWeek, $wins): SaveFaction {
            $members = SaveWrestler::with('wrestler')->whereIn('id', $memberIds)->get()->sortBy(fn (SaveWrestler $w): int => (int) array_search($w->id, $memberIds, true))->values();
            $brand = $this->brandOf($members);

            $faction = SaveFaction::create([
                'save_id' => $save->id,
                'faction_name' => $name,
                'faction_type' => $type,
                'origin' => $origin,
                'brand' => $brand,
                'formed_week' => $week,
                'date_formed' => $show === null ? $this->calendar->date($week) : $this->showDate($show),
                'record_from_week' => $recordFromWeek,
            ]);

            $faction->members()->attach($memberIds, ['joined_week' => $week]);
            $this->namer->forget($save->id);

            $this->news->record($save, NewsEventType::FactionFormed, NewsTier::Headline, $brand ?? NewsBrand::Both, [
                'faction' => $name,
                'members' => $this->listNames($members->map(fn (SaveWrestler $w): string => $w->name())->all()),
                'type' => $type === FactionType::TagTeam ? 'tag team' : 'stable',
                'wins' => $wins,
                'show' => $show?->name,
            ], ['wrestler_ids' => $memberIds, 'faction_id' => $faction->id], priority: (int) config('gm.news.faction_priority.formed'), week: $week, dedupeKey: "faction-formed:{$faction->id}");

            return $faction;
        });
    }

    /**
     * Add a member (up to the cap). Nothing in the game calls this yet; the
     * news is ready for when it does.
     */
    public function addMember(Save $save, SaveFaction $faction, SaveWrestler $wrestler, int $week): void
    {
        $faction->loadMissing('members');

        if (! $faction->isActive()) {
            throw new GameRuleException("{$faction->faction_name} has disbanded.");
        }

        if (in_array($wrestler->id, $faction->memberIds(), true)) {
            throw new GameRuleException("{$wrestler->name()} is already in {$faction->faction_name}.");
        }

        if (count($faction->memberIds()) >= (int) config('gm.factions.max_members')) {
            throw new GameRuleException("{$faction->faction_name} already has ".config('gm.factions.max_members').' members.');
        }

        $faction->members()->attach($wrestler->id, ['joined_week' => $week]);
        $this->namer->forget($save->id);

        $this->news->record($save, NewsEventType::FactionMemberAdded, NewsTier::Story, $wrestler->brand ?? $faction->brand ?? NewsBrand::Both, [
            'faction' => $faction->faction_name,
            'wrestler' => $wrestler->loadMissing('wrestler')->name(),
        ], ['wrestler_ids' => [$wrestler->id], 'faction_id' => $faction->id], priority: (int) config('gm.news.faction_priority.member_added'), week: $week);
    }

    /**
     * Disband an active faction from this week on. Its name still applies to
     * the weeks it was together.
     */
    public function disband(Save $save, SaveFaction $faction): void
    {
        if (! $faction->isActive()) {
            throw new GameRuleException("{$faction->faction_name} has already disbanded.");
        }

        $week = $save->current_week;
        $faction->update([
            'disbanded_week' => $week,
            'date_disbanded' => $this->calendar->date($week),
        ]);
        $this->namer->forget($save->id);

        $members = $faction->members()->with('wrestler')->get();

        $this->news->record($save, NewsEventType::FactionDisbanded, NewsTier::Story, $this->brandOf($members) ?? NewsBrand::Both, [
            'faction' => $faction->faction_name,
            'members' => $this->listNames($members->map(fn (SaveWrestler $w): string => $w->name())->all()),
        ], ['wrestler_ids' => $faction->memberIds(), 'faction_id' => $faction->id], priority: (int) config('gm.news.faction_priority.disbanded'), week: $week);
    }

    /**
     * Matches where 2+ members teamed on one side, while the faction was
     * together (custom factions from their first win together).
     *
     * @return array{wins: int, losses: int}
     */
    public function record(SaveFaction $faction): array
    {
        $members = $faction->memberIds();
        $rows = MatchParticipant::query()
            ->join('match_slots', 'match_slots.id', '=', 'match_participants.match_slot_id')
            ->join('shows', 'shows.id', '=', 'match_slots.show_id')
            ->where('shows.save_id', $faction->save_id)
            ->where('shows.week_number', '>=', $faction->record_from_week)
            ->when($faction->disbanded_week !== null, fn ($q) => $q->where('shows.week_number', '<', $faction->disbanded_week))
            ->whereIn('match_participants.save_wrestler_id', $members)
            ->where('match_participants.role', ParticipantRole::Competitor->value)
            ->whereNotNull('match_participants.result')
            ->get(['match_participants.match_slot_id', 'match_participants.side', 'match_participants.result']);

        $wins = 0;
        $losses = 0;

        foreach ($rows->groupBy(fn ($r): string => "{$r->match_slot_id}:{$r->side}") as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $group->first()->result === 'win' ? $wins++ : $losses++;
        }

        return ['wins' => $wins, 'losses' => $losses];
    }

    /**
     * Title reigns won by 2+ members together while the faction was
     * together, newest first.
     *
     * @return Collection<int, TitleReign>
     */
    public function titleReigns(SaveFaction $faction): Collection
    {
        $members = $faction->memberIds();

        return TitleReign::with('championship')
            ->where('save_id', $faction->save_id)
            ->where('won_week', '>=', $faction->record_from_week)
            ->when($faction->disbanded_week !== null, fn ($q) => $q->where('won_week', '<', $faction->disbanded_week))
            ->orderByDesc('won_week')
            ->get()
            ->filter(fn (TitleReign $r): bool => count(array_intersect($r->holderIds(), $members)) >= 2)
            ->values()
            ->toBase();
    }

    /**
     * The night a show airs: Sunday for a PPV, else its brand's weekday.
     */
    public function showDate(Show $show): CarbonImmutable
    {
        return $show->isPpv() || $show->brand === null
            ? $this->calendar->date($show->week_number)
            : $this->calendar->showDate($show->week_number, $show->brand);
    }

    public function nameTaken(Save $save, string $name): bool
    {
        return SaveFaction::where('save_id', $save->id)->whereRaw('LOWER(faction_name) = ?', [mb_strtolower(trim($name))])->exists();
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  array<int, string>  $names
     */
    public function listNames(array $names): string
    {
        $names = array_values($names);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names)." and {$last}";
    }

    /**
     * The brand most of the members are on (the first one's on a tie), or
     * null when none of them is signed.
     *
     * @param  Collection<int, SaveWrestler>|\Illuminate\Database\Eloquent\Collection<int, SaveWrestler>  $members
     */
    private function brandOf($members): ?Brand
    {
        $counts = [];

        foreach ($members as $member) {
            if ($member->brand !== null) {
                $counts[$member->brand->value] = ($counts[$member->brand->value] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $top = max($counts);

        return Brand::from((string) array_search($top, $counts, true));
    }
}
