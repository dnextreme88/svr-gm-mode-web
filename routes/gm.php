<?php

use App\Http\Controllers\Gm\CardController;
use App\Http\Controllers\Gm\ContractController;
use App\Http\Controllers\Gm\DraftController;
use App\Http\Controllers\Gm\EmailController;
use App\Http\Controllers\Gm\FinancialsController;
use App\Http\Controllers\Gm\FreeAgencyController;
use App\Http\Controllers\Gm\ReportsController;
use App\Http\Controllers\Gm\ResultsController;
use App\Http\Controllers\Gm\RosterController;
use App\Http\Controllers\Gm\SaveController;
use App\Http\Controllers\Gm\ScheduleController;
use App\Http\Controllers\Gm\TitleAssignmentController;
use App\Http\Controllers\Gm\TitleHistoryController;
use App\Http\Controllers\Gm\WeekController;
use App\Http\Middleware\ShareGameState;
use Illuminate\Support\Facades\Route;

/*
| GM Mode routes. Every route under a save requires the user to own it.
*/

Route::middleware(['auth'])->prefix('gm')->name('gm.')->group(function () {
    Route::get('/', [SaveController::class, 'index'])->name('saves.index');
    Route::post('saves', [SaveController::class, 'store'])->name('saves.store');

    Route::prefix('saves/{save}')
        ->middleware(['can:view,save', ShareGameState::class])
        ->scopeBindings()
        ->group(function () {
            Route::get('/', [SaveController::class, 'show'])->name('saves.show');
            Route::delete('/', [SaveController::class, 'destroy'])->name('saves.destroy');

            Route::get('draft', [DraftController::class, 'show'])->name('draft.show');
            Route::post('draft/pick', [DraftController::class, 'pick'])->name('draft.pick');
            Route::post('draft/finish', [DraftController::class, 'finish'])->name('draft.finish');

            Route::get('titles/assign', [TitleAssignmentController::class, 'show'])->name('titles.assign');
            Route::post('titles/assign/{championship}', [TitleAssignmentController::class, 'store'])->name('titles.assign.store')->withoutScopedBindings();
            Route::post('titles/assign-complete', [TitleAssignmentController::class, 'complete'])->name('titles.assign.complete');

            Route::get('schedule', ScheduleController::class)->name('schedule');
            Route::post('advance', [WeekController::class, 'advance'])->name('advance');
            Route::get('results/{week}', ResultsController::class)->whereNumber('week')->name('results');

            Route::get('shows/{show}', [CardController::class, 'show'])->name('card.show');
            Route::put('shows/{show}/slots/{position}', [CardController::class, 'book'])->whereNumber('position')->name('card.book');
            Route::delete('shows/{show}/slots/{position}', [CardController::class, 'clear'])->whereNumber('position')->name('card.clear');
            Route::post('shows/{show}/cash-in', [CardController::class, 'cashIn'])->name('card.cash-in');
            Route::get('reports', ReportsController::class)->name('reports');
            Route::get('titles', TitleHistoryController::class)->name('titles.history');

            Route::get('email', [EmailController::class, 'index'])->name('email');

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
