<?php

namespace App\Services\Titles;

use App\Enums\ContendershipVoidReason;
use App\Enums\GmNoticeKind;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\RosterType;
use App\Enums\TitleShotKind;
use App\Enums\TitleShotStatus;
use App\Enums\TitleVacancyPromptStatus;
use App\Enums\WonVia;
use App\Models\Championship;
use App\Models\GmNotice;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleShot;
use App\Models\TitleVacancyPrompt;
use App\Services\News\NewsRecorder;

/**
 * #1 contenderships (house rule, 2026-10-31). Winning a #1 Contender's
 * Match reserves the title's next title match for the winners, with no
 * deadline. It's voided when a contender leaves the brand or is seriously
 * injured. When the title is vacated first, the player decides whether
 * the contender(s) are awarded it; the rival awards it.
 */
class ContendershipService
{
    public function __construct(
        private TitleService $titles,
        private ChampionshipAssignment $assignment,
        private NewsRecorder $news,
    ) {}

    /**
     * @param  list<int>  $contenderIds
     * @param  list<int>  $championIds
     */
    public function create(Save $save, Championship $championship, array $contenderIds, array $championIds, MatchSlot $slot, int $week): TitleShot
    {
        return TitleShot::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'kind' => TitleShotKind::NumberOneContender,
            'contender_ids' => $contenderIds,
            'champion_ids' => $championIds,
            'earned_match_id' => $slot->id,
            'earned_week' => $week,
            'due_week' => null,
            'status' => TitleShotStatus::Pending,
        ]);
    }

    /**
     * Void every pending #1 contendership a wrestler is part of.
     */
    public function voidFor(SaveWrestler $wrestler, ContendershipVoidReason $reason, int $week): void
    {
        TitleShot::with('championship')
            ->where('save_id', $wrestler->save_id)
            ->where('kind', TitleShotKind::NumberOneContender)
            ->where('status', TitleShotStatus::Pending)
            ->orderBy('id')
            ->get()
            ->filter(fn (TitleShot $shot): bool => in_array($wrestler->id, $shot->contender_ids, true))
            ->each(fn (TitleShot $shot) => $this->void($shot, $reason, $week, $wrestler->loadMissing('wrestler')->name()));
    }

    /**
     * End a contendership early: a notice for the player's own, and a
     * ticker line either way.
     */
    public function void(TitleShot $shot, ContendershipVoidReason $reason, int $week, ?string $who = null): void
    {
        $shot->update(['status' => $reason === ContendershipVoidReason::Forfeited ? TitleShotStatus::Forfeited : TitleShotStatus::Voided, 'void_reason' => $reason, 'resolved_week' => $week]);

        TitleVacancyPrompt::where('title_shot_id', $shot->id)
            ->where('status', TitleVacancyPromptStatus::Pending)
            ->update(['status' => TitleVacancyPromptStatus::Forfeited]);

        $save = $shot->game;
        $contenders = $this->names($shot->contender_ids);
        $title = $shot->championship->name;
        $why = $who === null || count($shot->contender_ids) === 1
            ? "{$contenders} {$reason->label()}"
            : "{$who} {$reason->label()}";

        if ($shot->brand === $save->player_brand) {
            GmNotice::create([
                'save_id' => $save->id,
                'kind' => GmNoticeKind::ContendershipVoided,
                'payload' => [
                    'title' => $title,
                    'contenders' => $contenders,
                    'reason' => $why,
                ],
                'week_number' => $week,
            ]);
        }

        $this->news->record($save, NewsEventType::ContendershipVoided, NewsTier::Ticker, $shot->brand, [
            'contenders' => $contenders,
            'title' => $title,
            'reason' => $why,
        ], ['championship_id' => $shot->championship_id, 'wrestler_ids' => $shot->contender_ids], priority: (int) config('gm.news.stipulation_priority.contendership_voided'), week: $week, dedupeKey: "contendership-void:{$shot->id}");
    }

    /**
     * The title was vacated with a #1 contender waiting: ask whoever runs
     * the brand.
     */
    public function promptFor(TitleShot $shot, int $week): TitleVacancyPrompt
    {
        return TitleVacancyPrompt::firstOrCreate(
            ['title_shot_id' => $shot->id, 'status' => TitleVacancyPromptStatus::Pending],
            ['save_id' => $shot->save_id, 'championship_id' => $shot->championship_id, 'week_number' => $week],
        );
    }

    /**
     * Crown the #1 contender(s) of a vacated title.
     */
    public function award(TitleVacancyPrompt $prompt, int $week): void
    {
        $shot = $prompt->titleShot;
        $championship = $prompt->championship;
        $save = $prompt->game;
        $members = SaveWrestler::with('wrestler')->whereIn('id', $shot->contender_ids)->get();
        $holder = $championship->is_tag
            ? $this->assignment->findOrCreateTeam($save, $championship->brand, $members)
            : $members->firstOrFail();

        $reign = $this->titles->award($save, $championship, $holder, $week, WonVia::Awarded);

        $shot->update(['status' => TitleShotStatus::Fulfilled, 'resolved_week' => $week]);
        $prompt->update(['status' => TitleVacancyPromptStatus::Awarded]);

        $this->news->record($save, NewsEventType::ContenderAwardedTitle, NewsTier::Headline, $championship->brand, [
            'contenders' => $reign->holderName(),
            'title' => $championship->name,
            'pronoun' => count($shot->contender_ids) > 1 ? 'They' : ($members->first()?->wrestler->roster_type === RosterType::Diva ? 'She' : 'He'),
        ], ['championship_id' => $championship->id, 'wrestler_ids' => $shot->contender_ids], priority: (int) config('gm.news.stipulation_priority.contender_awarded_title'), week: $week, dedupeKey: "contender-awarded:{$shot->id}");
    }

    public function forfeit(TitleVacancyPrompt $prompt, int $week): void
    {
        $this->void($prompt->titleShot, ContendershipVoidReason::Forfeited, $week);
    }

    /**
     * The rival answers its own vacancy prompts (house rule): it awards the
     * title, or forfeits when `gm.titles.ai_award_contender_on_vacancy` is off.
     */
    public function settleRival(Save $save, int $week): void
    {
        TitleVacancyPrompt::with(['titleShot', 'championship'])
            ->where('save_id', $save->id)
            ->where('status', TitleVacancyPromptStatus::Pending)
            ->orderBy('id')
            ->get()
            ->reject(fn (TitleVacancyPrompt $prompt): bool => $prompt->championship->brand === $save->player_brand)
            ->each(fn (TitleVacancyPrompt $prompt) => config('gm.titles.ai_award_contender_on_vacancy')
                ? $this->award($prompt, $week)
                : $this->forfeit($prompt, $week));
    }

    /**
     * The player's vacancy prompt waiting for an answer, if any.
     */
    public function pendingPrompt(Save $save): ?TitleVacancyPrompt
    {
        return TitleVacancyPrompt::with(['titleShot', 'championship'])
            ->where('save_id', $save->id)
            ->where('status', TitleVacancyPromptStatus::Pending)
            ->whereHas('championship', fn ($q) => $q->where('brand', $save->player_brand))
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $ids
     */
    public function names(array $ids): string
    {
        return SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()
            ->sortBy(fn (SaveWrestler $w): int => (int) array_search($w->id, $ids, true))
            ->map(fn (SaveWrestler $w): string => $w->name())
            ->implode(' & ');
    }
}
