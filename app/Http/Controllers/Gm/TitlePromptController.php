<?php

namespace App\Http\Controllers\Gm;

use App\Enums\TitleVacancyPromptStatus;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\GmNotice;
use App\Models\Save;
use App\Models\TitleVacancyPrompt;
use App\Services\Titles\ContendershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The GM's answers to title contention (house rule, 2026-10-31): award a
 * vacated title to its #1 contender(s) or forfeit the contendership, and
 * dismiss one-off notices.
 */
class TitlePromptController extends Controller
{
    public function __construct(private ContendershipService $contenderships) {}

    public function award(Save $save, TitleVacancyPrompt $titleVacancyPrompt): RedirectResponse
    {
        $this->assertOpen($save, $titleVacancyPrompt);
        DB::transaction(fn () => $this->contenderships->award($titleVacancyPrompt, $save->current_week));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$titleVacancyPrompt->championship->name} awarded."]);

        return back();
    }

    public function forfeit(Save $save, TitleVacancyPrompt $titleVacancyPrompt): RedirectResponse
    {
        $this->assertOpen($save, $titleVacancyPrompt);
        DB::transaction(fn () => $this->contenderships->forfeit($titleVacancyPrompt, $save->current_week));

        return back();
    }

    public function seen(Save $save): RedirectResponse
    {
        GmNotice::where('save_id', $save->id)->whereNull('seen_at')->update(['seen_at' => now()]);

        return back();
    }

    private function assertOpen(Save $save, TitleVacancyPrompt $prompt): void
    {
        if ($prompt->save_id !== $save->id || $prompt->status !== TitleVacancyPromptStatus::Pending
            || $prompt->championship->brand !== $save->player_brand) {
            throw new GameRuleException('That title decision has already been made.');
        }
    }
}
