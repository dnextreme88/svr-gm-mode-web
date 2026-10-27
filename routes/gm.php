<?php

use App\Http\Controllers\Gm\CardController;
use App\Http\Controllers\Gm\CardToolsController;
use App\Http\Controllers\Gm\ContractController;
use App\Http\Controllers\Gm\DocsController;
use App\Http\Controllers\Gm\DraftController;
use App\Http\Controllers\Gm\EmailController;
use App\Http\Controllers\Gm\FactionController;
use App\Http\Controllers\Gm\FinancialsController;
use App\Http\Controllers\Gm\FreeAgencyController;
use App\Http\Controllers\Gm\NewsController;
use App\Http\Controllers\Gm\PpvController;
use App\Http\Controllers\Gm\ReportsController;
use App\Http\Controllers\Gm\ResultsController;
use App\Http\Controllers\Gm\RivalryController;
use App\Http\Controllers\Gm\RosterController;
use App\Http\Controllers\Gm\SaveController;
use App\Http\Controllers\Gm\ScheduleController;
use App\Http\Controllers\Gm\TitleAssignmentController;
use App\Http\Controllers\Gm\TitleHistoryController;
use App\Http\Controllers\Gm\TradeController;
use App\Http\Controllers\Gm\WeekController;
use App\Http\Middleware\ShareGameState;
use Illuminate\Support\Facades\Route;

/*
| GM Mode routes. There are no user accounts for now, so saves are open to
| whoever runs the app.
*/

Route::prefix('gm')->name('gm.')->group(function () {
    Route::get('/', [SaveController::class, 'index'])->name('saves.index');
    Route::post('saves', [SaveController::class, 'store'])->name('saves.store');
    Route::get('docs', DocsController::class)->name('docs');

    Route::prefix('saves/{save}')
        ->middleware([ShareGameState::class])
        ->scopeBindings()
        ->group(function () {
            Route::get('/', [SaveController::class, 'show'])->name('saves.show');
            Route::delete('/', [SaveController::class, 'destroy'])->name('saves.destroy');
            Route::post('persist', [SaveController::class, 'persist'])->name('saves.persist');

            Route::get('draft', [DraftController::class, 'show'])->name('draft.show');
            Route::post('draft/pick', [DraftController::class, 'pick'])->name('draft.pick');
            Route::post('draft/finish', [DraftController::class, 'finish'])->name('draft.finish');
            Route::post('draft/ai-step', [DraftController::class, 'aiStep'])->name('draft.ai-step');
            Route::post('draft/ai-finish', [DraftController::class, 'aiFinish'])->name('draft.ai-finish');

            Route::get('titles/assign', [TitleAssignmentController::class, 'show'])->name('titles.assign');
            Route::post('titles/assign/{championship}', [TitleAssignmentController::class, 'store'])->name('titles.assign.store')->withoutScopedBindings();
            Route::post('titles/assign/{championship}/vacant', [TitleAssignmentController::class, 'vacant'])->name('titles.assign.vacant')->withoutScopedBindings();
            Route::post('titles/assign-complete', [TitleAssignmentController::class, 'complete'])->name('titles.assign.complete');

            Route::get('schedule', ScheduleController::class)->name('schedule');
            Route::post('advance', [WeekController::class, 'advance'])->name('advance');
            Route::post('simulate', [WeekController::class, 'simulate'])->name('simulate');
            Route::get('results/{week}', ResultsController::class)->whereNumber('week')->name('results');
            Route::post('results/{week}/decide', [WeekController::class, 'decide'])->whereNumber('week')->name('results.decide');
            Route::post('results/{week}/simulate-all', [WeekController::class, 'simulateAll'])->whereNumber('week')->name('results.simulate-all');

            Route::get('shows/{show}', [CardController::class, 'show'])->name('card.show');
            Route::put('shows/{show}/slots/{position}', [CardController::class, 'book'])->whereNumber('position')->name('card.book');
            Route::delete('shows/{show}/slots/{position}', [CardController::class, 'clear'])->whereNumber('position')->name('card.clear');
            Route::post('shows/{show}/cash-in', [CardController::class, 'cashIn'])->name('card.cash-in');
            Route::post('shows/{show}/confirm', [CardController::class, 'confirm'])->name('card.confirm');
            Route::post('shows/{show}/unlock', [CardController::class, 'unlock'])->name('card.unlock');
            Route::post('shows/{show}/slots/{position}/randomize', [CardToolsController::class, 'randomize'])->whereNumber('position')->name('card.randomize');
            Route::post('shows/{show}/preset', [CardToolsController::class, 'preset'])->name('card.preset');
            Route::put('shows/{show}/promos/{position}', [CardToolsController::class, 'bookPromo'])->whereNumber('position')->name('card.promo.book');
            Route::delete('shows/{show}/promos/{position}', [CardToolsController::class, 'clearPromo'])->whereNumber('position')->name('card.promo.clear');
            Route::get('reports', ReportsController::class)->name('reports');
            Route::get('titles', TitleHistoryController::class)->name('titles.history');
            Route::get('news', NewsController::class)->name('news');
            Route::get('shows/{show}/entrants', [PpvController::class, 'entrants'])->name('ppv.entrants');
            Route::post('shows/{show}/entrants', [PpvController::class, 'storeEntrants'])->name('ppv.entrants.store');
            Route::get('redraft', [PpvController::class, 'redraft'])->name('redraft.show');
            Route::post('redraft/tags', [PpvController::class, 'setTags'])->name('redraft.tags');
            Route::post('redraft/run', [PpvController::class, 'runRedraft'])->name('redraft.run');
            Route::get('rivalries', RivalryController::class)->name('rivalries');
            Route::get('factions', [FactionController::class, 'index'])->name('factions.index');
            Route::post('factions/{saveFaction}/disband', [FactionController::class, 'disband'])->name('factions.disband');
            Route::post('faction-prompts/{factionPrompt}/form', [FactionController::class, 'form'])->name('factions.prompts.form');
            Route::post('faction-prompts/{factionPrompt}/defer', [FactionController::class, 'defer'])->name('factions.prompts.defer');
            Route::get('trade', [TradeController::class, 'show'])->name('trade.show');
            Route::post('trade', [TradeController::class, 'store'])->name('trade.store');

            Route::get('email', [EmailController::class, 'index'])->name('email');
            Route::get('docs', DocsController::class)->name('saves.docs');

            Route::get('rosters', [RosterController::class, 'index'])->name('rosters.index');
            Route::get('rosters/{saveWrestler}', [RosterController::class, 'show'])->name('rosters.show');
            Route::post('rosters/{saveWrestler}/renegotiate', [ContractController::class, 'renegotiate'])->name('contracts.renegotiate');
            Route::post('rosters/{saveWrestler}/release', [ContractController::class, 'release'])->name('contracts.release');

            Route::get('free-agency', FreeAgencyController::class)->name('free-agency');
            Route::post('free-agency/{saveWrestler}/sign', [ContractController::class, 'sign'])->name('free-agency.sign');

            Route::get('financials', [FinancialsController::class, 'index'])->name('financials');
            Route::post('financials/deficit-choice', [FinancialsController::class, 'choose'])->name('financials.deficit-choice');
        });
});
