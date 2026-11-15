<?php

namespace App\Http\Controllers\Gm;

use App\Enums\SaveStage;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\News\NewsComposer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The News carousel: one composed page per week, newest first (§19).
 */
class NewsController extends Controller
{
    public function __invoke(Save $save, NewsComposer $composer): Response|RedirectResponse
    {
        // House rule (2026-11-15): WrestleMania week's news is written when the
        // re-draft ends, so the re-draft comes first (the results' Continue
        // lands here).
        if ($save->stage === SaveStage::Redrafting) {
            return to_route('gm.redraft.show', $save);
        }

        return Inertia::render('gm/news', [
            'pages' => $composer->pages($save),
        ]);
    }
}
