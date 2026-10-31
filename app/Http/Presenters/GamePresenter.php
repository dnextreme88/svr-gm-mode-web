<?php

namespace App\Http\Presenters;

use App\Enums\FactionPromptStatus;
use App\Enums\FactionType;
use App\Enums\SaveStage;
use App\Models\Email;
use App\Models\FactionPrompt;
use App\Models\GmNotice;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\WeekAiring;
use App\Services\Calendar\CalendarService;
use App\Services\Roster\RosterService;
use App\Services\Titles\ContendershipService;
use App\Services\Week\BookingOrder;
use App\Services\Week\WeekResolver;

/**
 * The broadcast header shown on every GM screen.
 */
class GamePresenter
{
    public function __construct(
        private CalendarService $calendar,
        private RosterService $roster,
        private BookingOrder $order,
        private ContendershipService $contenderships,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shell(Save $save): array
    {
        $week = $save->current_week;
        $player = $save->brand($save->player_brand);
        $rival = $save->brand($save->rivalBrand());
        $next = $this->calendar->nextPpv($week, $save->player_brand);
        $limit = (int) config('gm.finances.deficit_weeks_limit');
        // Between a PPV night and the weekly show (house rule, 2026-11-01)
        // nothing is airing: the weekly show is being booked.
        $airingRow = WeekAiring::where('save_id', $save->id)->where('week_number', $week)->whereNull('completed_at')->first();
        $airing = $airingRow !== null && ($airingRow->context['phase'] ?? null) !== WeekResolver::PHASE_PPV_DONE;

        return [
            'id' => $save->id,
            'name' => $save->name,
            'saved' => $save->saved_at !== null,
            'player_brand' => $save->player_brand->value,
            'rival_brand' => $save->rivalBrand()->value,
            'stage' => $save->stage->value,
            'season' => $this->calendar->seasonOf($week),
            'week' => $week,
            'season_week' => $this->calendar->seasonWeek($week),
            // Live results: the week's matches are being decided.
            'airing' => $airing,
            // The season/week chip in the header links here.
            'current_card_url' => $this->currentCardUrl($save, $airing),
            // The player's weekly show night (Raw Monday, SmackDown Friday).
            'month' => $this->calendar->showDate($week, $save->player_brand)->format('F'),
            'date' => $this->calendar->showDate($week, $save->player_brand)->format('D M j'),
            'budget' => $player->budget,
            'rival_budget' => $rival->budget,
            'roster_count' => $this->roster->rosterCount($save, $save->player_brand),
            'rival_roster_count' => $this->roster->rosterCount($save, $save->rivalBrand()),
            'roster_cap' => $this->roster->cap(),
            'unread_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->whereNull('read_at')->count(),
            'next_ppv' => $next === null ? null : [
                'name' => $next['ppv']->name,
                'week' => $next['week'],
                'weeks_away' => $next['week'] - $week,
            ],
            'fatigue_config' => [
                'bar_size' => (int) config('gm.fatigue.bar_size'),
                'safe_max' => (int) config('gm.fatigue.safe_max'),
                'serious_at' => (int) config('gm.fatigue.serious_at'),
                'max' => (int) config('gm.fatigue.max'),
            ],
            'deficit' => [
                'weeks' => $player->deficit_weeks,
                'limit' => $limit,
                'choice' => $player->deficit_choice,
                'blocked' => $player->deficit_weeks >= $limit && $player->deficit_choice === null,
            ],
            'faction_prompt' => $this->factionPrompt($save),
            'title_vacancy_prompt' => $this->titleVacancyPrompt($save),
            'notices' => GmNotice::where('save_id', $save->id)->whereNull('seen_at')->orderBy('id')->get()
                ->map(fn (GmNotice $n): array => ['id' => $n->id, 'kind' => $n->kind->value, 'week' => $n->week_number, ...$n->payload])
                ->values()
                ->all(),
        ];
    }

    /**
     * The player's vacated title with a #1 contender waiting (house rule):
     * award it or forfeit the contendership.
     *
     * @return array<string, mixed>|null
     */
    private function titleVacancyPrompt(Save $save): ?array
    {
        $prompt = $this->contenderships->pendingPrompt($save);

        if ($prompt === null) {
            return null;
        }

        return [
            'id' => $prompt->id,
            'title' => $prompt->championship->name,
            'contenders' => $this->contenderships->names($prompt->titleShot->contender_ids),
            'contender_count' => count($prompt->titleShot->contender_ids),
        ];
    }

    /**
     * Where this week stands: the live results while it airs, else the
     * first card still to confirm in booking order (the PPV, the interbrand
     * card, then the weekly show), or the last one once all are locked in.
     */
    private function currentCardUrl(Save $save, bool $airing): ?string
    {
        if ($save->stage !== SaveStage::InSeason) {
            return null;
        }

        if ($airing) {
            return route('gm.results', ['save' => $save, 'week' => $save->current_week]);
        }

        $steps = $this->order->nightUp($save, $save->current_week);
        $show = $steps->first(fn (Show $s): bool => $s->confirmed_at === null) ?? $steps->last();

        return $show === null ? null : route('gm.card.show', [$save, $show]);
    }

    /**
     * The player's next team waiting to be named as a faction (house rule).
     *
     * @return array<string, mixed>|null
     */
    private function factionPrompt(Save $save): ?array
    {
        $prompt = FactionPrompt::with('show')
            ->where('save_id', $save->id)
            ->where('status', FactionPromptStatus::Pending)
            ->orderBy('id')
            ->first();

        if ($prompt === null) {
            return null;
        }

        $members = SaveWrestler::with('wrestler')->whereIn('id', $prompt->member_ids)->get();

        return [
            'id' => $prompt->id,
            'wins' => $prompt->wins,
            'show' => $prompt->show?->name,
            'default_type' => (count($prompt->member_ids) === 2 ? FactionType::TagTeam : FactionType::Stable)->value,
            'name_max_length' => (int) config('gm.factions.name_max_length'),
            'members' => $members->map(fn (SaveWrestler $w): array => [
                'id' => $w->id,
                'name' => $w->wrestler->name,
                'roster_type' => $w->wrestler->roster_type->value,
                'weight_class' => $w->wrestler->weight_class->value,
            ])->values()->all(),
        ];
    }
}
