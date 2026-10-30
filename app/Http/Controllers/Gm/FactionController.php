<?php

namespace App\Http\Controllers\Gm;

use App\Enums\FactionOrigin;
use App\Enums\FactionPromptStatus;
use App\Enums\FactionType;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\FactionPrompt;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\Factions\FactionService;
use App\Services\Week\WeekResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Factions (house rule): every tag team and stable in the save, disbanding
 * one, and naming the player's teams that won 3 times together.
 */
class FactionController extends Controller
{
    public function __construct(
        private FactionService $factions,
        private CalendarService $calendar,
    ) {}

    public function index(Save $save): Response
    {
        $rows = SaveFaction::with(['faction', 'members.wrestler'])
            ->where('save_id', $save->id)
            ->get()
            ->sortBy([
                fn (SaveFaction $a, SaveFaction $b): int => (int) $b->isActive() <=> (int) $a->isActive(),
                fn (SaveFaction $a, SaveFaction $b): int => strcasecmp($this->sortName($a->faction_name), $this->sortName($b->faction_name)),
            ])
            ->map(fn (SaveFaction $f): array => $this->row($save, $f))
            ->values();

        return Inertia::render('gm/factions', [
            'factions' => $rows,
            'max_members' => (int) config('gm.factions.max_members'),
            'formation_wins' => (int) config('gm.factions.formation_wins'),
        ]);
    }

    public function disband(Save $save, SaveFaction $saveFaction): RedirectResponse
    {
        app(WeekResolver::class)->assertNotAiring($save);
        $this->factions->disband($save, $saveFaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$saveFaction->faction_name} have disbanded."]);

        return to_route('gm.factions.index', $save);
    }

    /**
     * Name one of the player's teams and make it a faction.
     */
    public function form(Request $request, Save $save, FactionPrompt $factionPrompt): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.config('gm.factions.name_max_length')],
            'type' => ['required', Rule::enum(FactionType::class)],
        ]);

        $this->assertStillTogether($save, $factionPrompt);

        $faction = DB::transaction(function () use ($save, $factionPrompt, $data): SaveFaction {
            $faction = $this->factions->form(
                $save,
                $factionPrompt->member_ids,
                (string) $data['name'],
                FactionType::from((string) $data['type']),
                FactionOrigin::Player,
                $factionPrompt->week_number,
                $factionPrompt->show,
                $factionPrompt->first_win_week,
                $factionPrompt->wins,
            );

            $factionPrompt->update(['status' => FactionPromptStatus::Formed, 'deferred_at_wins' => $factionPrompt->wins]);

            return $faction;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$faction->faction_name} is now a faction."]);

        return back();
    }

    /**
     * "Not now": ask again after their next win together.
     */
    public function defer(Save $save, FactionPrompt $factionPrompt): RedirectResponse
    {
        $factionPrompt->update(['status' => FactionPromptStatus::Deferred, 'deferred_at_wins' => $factionPrompt->wins]);

        return back();
    }

    /**
     * A team can only be named while they're all still on the player's brand.
     */
    private function assertStillTogether(Save $save, FactionPrompt $prompt): void
    {
        if ($prompt->status !== FactionPromptStatus::Pending) {
            throw new GameRuleException('This team has already been answered.');
        }

        $onBrand = SaveWrestler::whereIn('id', $prompt->member_ids)->where('brand', $save->player_brand)->count();

        if ($onBrand !== count($prompt->member_ids)) {
            $prompt->delete();

            throw new GameRuleException('This team is no longer together on your brand.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Save $save, SaveFaction $f): array
    {
        $record = $this->factions->record($f);
        $seeded = $f->origin === FactionOrigin::Seeded;

        return [
            'id' => $f->id,
            'name' => $f->faction_name,
            'type' => $f->faction_type->value,
            'origin' => $f->origin->value,
            'active' => $f->isActive(),
            'members' => $f->members->map(fn (SaveWrestler $w): array => [
                'id' => $w->id,
                'name' => $w->wrestler->name,
                'roster_type' => $w->wrestler->roster_type->value,
                'weight_class' => $w->wrestler->weight_class->value,
                'brand' => $w->brand?->value,
            ])->all(),
            // Seeded factions: real-life dates. Custom ones: the in-game show date.
            'formed' => $seeded ? $this->date($f->faction?->date_formed) : $this->date($f->date_formed),
            'formed_week' => $seeded ? null : $this->weekLabel($f->formed_week),
            'real_disbanded' => $seeded ? $this->date($f->faction?->date_disbanded) : null,
            'disbanded' => $this->date($f->date_disbanded),
            'disbanded_week' => $f->disbanded_week === null ? null : $this->weekLabel($f->disbanded_week),
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'titles' => $this->factions->titleReigns($f)->map(fn (TitleReign $r): array => [
                'id' => $r->id,
                'title' => $r->championship->name,
                'won' => $this->weekLabel($r->won_week),
                'current' => $r->lost_week === null,
            ])->values()->all(),
        ];
    }

    private function date(?CarbonImmutable $date): ?string
    {
        return $date?->format('M j, Y');
    }

    private function weekLabel(int $week): string
    {
        return "S{$this->calendar->seasonOf($week)} W{$this->calendar->seasonWeek($week)}";
    }

    /**
     * "The Basham Brothers" sorts under B.
     */
    private function sortName(string $name): string
    {
        return (string) preg_replace('/^the\s+/i', '', $name);
    }
}
