<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\News\NewsComposer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The News carousel: one composed page per week, newest first (§19).
 */
class NewsController extends Controller
{
    public function __invoke(Save $save, NewsComposer $composer): Response
    {
        return Inertia::render('gm/news', [
            'pages' => array_map(fn (int $week): array => $composer->compose($save, $week), $composer->weeks($save)),
        ]);
    }
}
